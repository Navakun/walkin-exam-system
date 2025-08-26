<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
  // 1) DB
  require_once '../config/db.php';

  // 2) รับ JSON
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'INVALID_JSON_BODY']); 
    exit;
  }

  $student_id = trim((string)($input['student_id'] ?? ''));
  $password   = trim((string)($input['password'] ?? ''));
  if ($student_id === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'MISSING_FIELDS']); 
    exit;
  }

  // 3) คิวรีตามสคีมาที่ใช้อยู่
  $stmt = $pdo->prepare('SELECT student_id, name, password FROM student WHERE student_id = ? LIMIT 1');
  $stmt->execute([$student_id]);
  $row = $stmt->fetch();

  if (!$row) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'USER_NOT_FOUND']); 
    exit;
  }

  // 4) ตรวจรหัสผ่าน
  if (!password_verify($password, $row['password'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'INVALID_PASSWORD']); 
    exit;
  }

  // 5) สร้าง JWT token
  $secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";
  $payload = [
      'student_id' => $row['student_id'],
      'name' => $row['name'],
      'role' => 'student',
      'iat' => time(),
  'exp' => time() + (90 * 60) // 1.5 hour (90 นาที) expiration
  ];

  $token = JWT::encode($payload, $secret_key, 'HS256');
  
  echo json_encode([
    'status'  => 'success',
    'token'   => $token,
    'student' => ['id' => $row['student_id'], 'name' => $row['name']]
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log("Login error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
