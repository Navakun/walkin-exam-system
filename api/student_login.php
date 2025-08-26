<?php
// แสดง error ชั่วคราวระหว่างดีบัก
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'SERVER_FATAL','detail'=>$e['message']]);
  }
});

try {
  // 1) DB
  $dbPath = __DIR__ . '/../config/db.php';
  if (!file_exists($dbPath)) throw new RuntimeException('db.php not found: '.$dbPath);
  require_once $dbPath;           // ต้องนิยาม $pdo
  if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined');

  // 2) รับ JSON
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'INVALID_JSON_BODY']); exit;
  }

  $student_id = trim((string)($input['student_id'] ?? ''));
  $password   = trim((string)($input['password'] ?? ''));
  if ($student_id === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'MISSING_FIELDS']); exit;
  }

  // 3) คิวรีตามสคีมาที่ใช้อยู่ (table: student, cols: student_id, name, password)
  $stmt = $pdo->prepare('SELECT student_code, fullname, password_hash FROM Student WHERE student_code = ? LIMIT 1');
  $stmt->execute([$student_id]);
  $row = $stmt->fetch();

  if (!$row) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'USER_NOT_FOUND']); exit;
  }

  // 4) ตรวจรหัสผ่าน
  if (!password_verify($password, $row['password_hash'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'INVALID_PASSWORD']); exit;
  }

  // 5) สำเร็จ → สร้าง JWT token
  require_once '../vendor/autoload.php';

  $secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";
  $payload = [
      'student_id' => $row['student_code'],
      'name' => $row['fullname'],
      'role' => 'student',
      'iat' => time(),
      'exp' => time() + (60 * 60) // 1 hour expiration
  ];

  $token = JWT::encode($payload, $secret_key, 'HS256');
  
  echo json_encode([
    'status'  => 'success',
    'token'   => $token,
    'student' => ['id' => $row['student_code'], 'name' => $row['fullname']]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
