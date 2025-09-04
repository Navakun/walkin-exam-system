<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0'); error_reporting(E_ALL);

function fail(int $code, string $msg, array $extra=[]): void {
  http_response_code($code);
  echo json_encode(['status'=>'error','message'=>$msg]+$extra, JSON_UNESCAPED_UNICODE);
  exit;
}
function ok(array $payload): void {
  echo json_encode(['status'=>'success']+$payload, JSON_UNESCAPED_UNICODE);
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
  $root = dirname(__DIR__, 2);

  // autoload
  $autoload = $root . '/vendor/autoload.php';
  if (!is_file($autoload)) fail(500,'SERVER_ERROR',['error_code'=>'NO_AUTOLOAD']);
  require_once $autoload;

  // db
  $dbFile = $root . '/config/db.php';
  if (!is_file($dbFile)) fail(500,'SERVER_ERROR',['error_code'=>'NO_DB']);
  require_once $dbFile;
  if (!isset($pdo) || !($pdo instanceof PDO)) fail(500,'SERVER_ERROR',['error_code'=>'PDO_NOT_SET']);

  // jwt
  $jwtHelper = $root . '/api/helpers/jwt_helper.php';
  if (!is_file($jwtHelper)) fail(500,'SERVER_ERROR',['error_code'=>'NO_JWT_HELPER']);
  require_once $jwtHelper;

  if (!function_exists('verifyJwtToken')) {
    function verifyJwtToken(string $token, array $requiredClaims=[]){
      if (!function_exists('decodeToken')) return null;
      $d = decodeToken($token);
      if (!$d) return null;
      foreach($requiredClaims as $c) if (!isset($d->$c)) return null;
      return $d;
    }
  }

  // auth (ยอมรับอาจารย์ หรือจะใส่ทั้งสองบทบาท)
  $auth = getAuthHeader();
  if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) fail(401,'Unauthorized',['error_code'=>'NO_BEARER']);
  $token = $m[1];
  $decoded = verifyJwtToken($token); // ไม่บังคับ claims
  if (!$decoded || (!isset($decoded->instructor_id) && !isset($decoded->student_id))) {
    fail(401, 'Unauthorized', ['error_code'=>'BAD_TOKEN']);
  }

  // ---------- per-question ----------
  $sql1 = "
    SELECT 
      q.question_id,
      q.question_text,
      SUM(a.is_correct = 1) AS correct_cnt,
      SUM(a.is_correct = 0) AS wrong_cnt,
      COUNT(*)               AS attempts
    FROM answer a
    JOIN question q ON q.question_id = a.question_id
    GROUP BY q.question_id, q.question_text
    ORDER BY q.question_id
  ";
  $perQuestion = $pdo->query($sql1)->fetchAll(PDO::FETCH_ASSOC) ?: [];

  // ---------- per-student ----------
  $sql2 = "
    SELECT 
      es.student_id,
      COUNT(*)                 AS total_answered,
      SUM(a.is_correct = 1)    AS correct_count
    FROM answer a
    JOIN examsession es ON es.session_id = a.session_id
    GROUP BY es.student_id
    ORDER BY es.student_id
  ";
  $perStudent = $pdo->query($sql2)->fetchAll(PDO::FETCH_ASSOC) ?: [];

  ok([
    'per_question' => $perQuestion,
    'per_student'  => $perStudent
  ]);

} catch (Throwable $e) {
  error_log('get_exam_overview FATAL: '.$e->getMessage());
  fail(500,'SERVER_ERROR',['error_code'=>'UNSPECIFIED']);
}
