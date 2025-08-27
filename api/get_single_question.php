<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/db.php';

function getBearerToken(): ?string {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');
  if (!$auth && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
      if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
    }
  }
  if (!$auth) return null;
  $auth = trim(preg_replace('/\s+/', ' ', $auth));
  if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return null;
  return preg_replace('/[\x00-\x1F\x7F]/', '', $m[1]) ?: null;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Auth (ใช้ token ครู)
  $token = getBearerToken();
  if (!$token || strlen($token) < 16) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'UNAUTHORIZED']);
    exit;
  }

  // รับ question_id จาก query string
  $qid = isset($_GET['question_id']) ? (int)$_GET['question_id'] : 0;
  if ($qid <= 0) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'missing question_id']);
    exit;
  }

  // ดึงข้อมูลคำถาม
  $stmQ = $pdo->prepare('SELECT question_id, question_text, correct_choice, difficulty FROM question WHERE question_id = ?');
  $stmQ->execute([$qid]);
  $q = $stmQ->fetch(PDO::FETCH_ASSOC);
  if (!$q) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'QUESTION_NOT_FOUND']);
    exit;
  }

  // ดึง choices
  $stmC = $pdo->prepare('SELECT label, content FROM choice WHERE question_id = ? ORDER BY label ASC');
  $stmC->execute([$qid]);
  $choices = [];
  while ($c = $stmC->fetch(PDO::FETCH_ASSOC)) {
    $choices[$c['label']] = $c['content'];
  }

  // คืนข้อมูลในรูปแบบที่ JS ต้องการ
  echo json_encode([
    'status' => 'success',
    'data' => [
      'question_id'     => (int)$q['question_id'],
      'question_text'   => $q['question_text'],
      'choices'         => $choices,
      'correct_choice'  => $q['correct_choice'],
      'difficulty'      => (int)$q['difficulty'],
    ]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('[get_single_question] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
