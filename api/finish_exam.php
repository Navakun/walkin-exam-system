<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $m)) {
  http_response_code(401);
  echo json_encode(['status'=>'error','message'=>'MISSING_TOKEN']);
  exit;
}
$token = $m[1];
// TODO: ตรวจสอบ token จริงถ้าต้องการ

$session_id = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($session_id <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'INVALID_SESSION_ID']);
  exit;
}

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $stmt = $pdo->prepare('UPDATE examsession SET end_time = NOW() WHERE session_id = ?');
  $stmt->execute([$session_id]);
  echo json_encode(['status'=>'success','message'=>'End time recorded']);
} catch (Throwable $e) {
  error_log('[finish_exam] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
