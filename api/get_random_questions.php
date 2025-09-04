<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);

function fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['status'=>'error','message'=>$msg] + $extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function ok(array $payload): void {
  echo json_encode(['status'=>'success'] + $payload, JSON_UNESCAPED_UNICODE);
  exit;
}
function getAuthHeader(): string {
  if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (isset($h['Authorization'])) return $h['Authorization'];
    if (isset($h['authorization'])) return $h['authorization'];
  }
  return $_SERVER['HTTP_AUTHORIZATION'] ?? '';
}

try {
  $root = dirname(__DIR__);

  // autoload (firebase/php-jwt)
  $autoload = $root . '/vendor/autoload.php';
  if (!is_file($autoload)) fail(500, 'SERVER_ERROR', ['error_code'=>'NO_AUTOLOAD']);
  require_once $autoload;

  // db
  $dbFile = $root . '/config/db.php';
  if (!is_file($dbFile)) fail(500, 'SERVER_ERROR', ['error_code'=>'NO_DB']);
  require_once $dbFile;
  if (!isset($pdo) || !($pdo instanceof PDO)) fail(500, 'SERVER_ERROR', ['error_code'=>'PDO_NOT_SET']);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // jwt helper
  $jwtHelper = $root . '/api/helpers/jwt_helper.php';
  if (!is_file($jwtHelper)) fail(500, 'SERVER_ERROR', ['error_code'=>'NO_JWT_HELPER']);
  require_once $jwtHelper;

  // สร้าง verifyJwtToken ถ้าโปรเจกต์นี้ยังไม่มี
  if (!function_exists('verifyJwtToken')) {
    function verifyJwtToken(string $token, array $requiredClaims = []) {
      if (!function_exists('decodeToken')) return null;
      $decoded = decodeToken($token);
      if (!$decoded) return null;
      foreach ($requiredClaims as $c) if (!isset($decoded->$c)) return null;
      return $decoded;
    }
  }

  // --- auth: ต้องเป็น student ---
  $auth = getAuthHeader();
  if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) fail(401, 'Unauthorized', ['error_code'=>'NO_BEARER']);
  $token   = $m[1];
  $decoded = verifyJwtToken($token, ['student_id']);
  if (!$decoded) fail(401, 'Unauthorized', ['error_code'=>'BAD_TOKEN']);
  $student_id = (string)$decoded->student_id;

  // --- config จำนวนข้อ (fallback ถ้าไม่มีตาราง/คอลัมน์) ---
  $easyN = 15; $midN = 15; $hardN = 20; // ค่าเริ่มต้น
  try {
    $cfg = $pdo->query("SELECT easy_count, medium_count, hard_count FROM exam_config LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($cfg) {
      $easyN = (int)($cfg['easy_count'] ?? $easyN);
      $midN  = (int)($cfg['medium_count'] ?? $midN);
      $hardN = (int)($cfg['hard_count'] ?? $hardN);
    }
  } catch (Throwable $e) {
    // ไม่มีตาราง exam_config ก็ใช้ default
  }
  $totalN = $easyN + $midN + $hardN;

  // --- ช่วงความยาก (ปรับ threshold ได้) ---
  $sqlEasy = "SELECT question_id, question_text FROM question WHERE item_difficulty IS NOT NULL AND item_difficulty < 0.33";
  $sqlMid  = "SELECT question_id, question_text FROM question WHERE item_difficulty >= 0.33 AND item_difficulty < 0.66";
  $sqlHard = "SELECT question_id, question_text FROM question WHERE item_difficulty >= 0.66";

  // ฟังก์ชันสุ่มด้วย RAND() LIMIT
  $pick = function(string $baseSql, int $n) use ($pdo): array {
    if ($n <= 0) return [];
    $sql = $baseSql." ORDER BY RAND() LIMIT :n";
    $st  = $pdo->prepare($sql);
    $st->bindValue(':n', $n, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  };

  $easy  = $pick($sqlEasy, $easyN);
  $mid   = $pick($sqlMid,  $midN);
  $hard  = $pick($sqlHard, $hardN);

  $picked = array_merge($easy, $mid, $hard);

  // เติมสำรองถ้าจำนวนยังไม่ครบ
  if (count($picked) < $totalN) {
    $haveIds = array_column($picked, 'question_id');
    $haveIds = array_map('intval', $haveIds);
    $notIn   = $haveIds ? (' AND question_id NOT IN ('.implode(',', $haveIds).')') : '';
    $need    = $totalN - count($picked);
    $fillSql = "SELECT question_id, question_text FROM question WHERE 1=1 {$notIn} ORDER BY RAND() LIMIT :n";
    $st = $pdo->prepare($fillSql);
    $st->bindValue(':n', $need, PDO::PARAM_INT);
    $st->execute();
    $picked = array_merge($picked, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
  }

  // สลับลำดับอีกที
  shuffle($picked);

  // ดึง choices ทั้งหมดของคำถามที่เลือก
  $choicesByQ = [];
  if ($picked) {
    $ids = implode(',', array_map('intval', array_column($picked, 'question_id')));
    $cs  = $pdo->query("SELECT question_id, label, content FROM choice WHERE question_id IN ($ids) ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cs as $r) {
      $qid = (int)$r['question_id'];
      if (!isset($choicesByQ[$qid])) $choicesByQ[$qid] = [];
      $choicesByQ[$qid][$r['label']] = $r['content'];
    }
  }

  // --- สร้าง session + ใส่ answer ว่าง (1 แถวต่อคำถาม) ---
  $pdo->beginTransaction();
  try {
    // สร้าง session (ถ้าตาราง examsession มี start_time/attempt_no default ให้ปล่อยว่าง)
    $st = $pdo->prepare("INSERT INTO examsession (student_id, start_time) VALUES (:sid, NOW())");
    $st->execute([':sid'=>$student_id]);
    $session_id = (int)$pdo->lastInsertId();

    // เตรียม insert answer ว่าง
    $ins = $pdo->prepare("INSERT INTO answer (session_id, question_id, selected_choice) VALUES (:sess, :qid, '')");
    foreach ($picked as $q) {
      $ins->execute([':sess'=>$session_id, ':qid'=>(int)$q['question_id']]);
    }

    $pdo->commit();

  } catch (Throwable $e) {
    $pdo->rollBack();
    error_log('get_random_questions TX error: '.$e->getMessage());
    fail(500, 'SERVER_ERROR', ['error_code'=>'TX_ERROR']);
  }

  // --- response: ส่งคำถาม + choices (ไม่ส่งเฉลย) ---
  $out = [];
  foreach ($picked as $q) {
    $qid = (int)$q['question_id'];
    $out[] = [
      'question_id'   => $qid,
      'question_text' => $q['question_text'],
      'choices'       => $choicesByQ[$qid] ?? []
    ];
  }

  ok([
    'session_id' => $session_id,
    'total'      => count($out),
    'questions'  => $out
  ]);

} catch (Throwable $e) {
  error_log('get_random_questions FATAL: '.$e->getMessage());
  fail(500, 'SERVER_ERROR', ['error_code'=>'UNSPECIFIED']);
}
