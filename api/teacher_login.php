<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../vendor/autoload.php';
require_once '../config/db.php';

use Firebase\JWT\JWT;

try {

  // อ่าน JSON input
  $raw = file_get_contents('php://input');
  $input = json_decode($raw, true);
  if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'INVALID_JSON_BODY']);
    exit;
  }

  $username = trim($input['username'] ?? '');
  $password = (string)($input['password'] ?? '');
  if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'MISSING_FIELDS']);
    exit;
  }

  // ค้นหาอาจารย์ด้วย email หรือ instructor_id
  $sql = "SELECT instructor_id, name, email, password
          FROM instructor
          WHERE email = ? OR instructor_id = ?
          LIMIT 1";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$username, $username]);
  $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$instructor) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบบัญชีผู้ใช้']);
    exit;
  }

  // ตรวจสอบรหัสผ่าน
  if (!password_verify($password, $instructor['password'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านไม่ถูกต้อง']);
    exit;
  }

  // สร้าง JWT token
  $secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";
  $issuedAt = time();
  $expirationTime = $issuedAt + 60 * 60 * 24; // 24 ชั่วโมง

  $payload = [
    'iat' => $issuedAt,
    'exp' => $expirationTime,
    'id' => $instructor['instructor_id'],
    'name' => $instructor['name'],
    'email' => $instructor['email'],
    'role' => 'teacher'
  ];

  $jwt = JWT::encode($payload, $secret_key, 'HS256');

  echo json_encode([
    'status' => 'success',
    'token' => $jwt,
    'instructor' => [
      'id' => $instructor['instructor_id'],
      'name' => $instructor['name'],
      'email' => $instructor['email']
    ]
  ]);

} catch (Throwable $e) {
    error_log('[teacher_login] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
