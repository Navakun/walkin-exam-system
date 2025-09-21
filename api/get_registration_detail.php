<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ==== ตรวจสอบ JWT ====
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}
$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception("Token ไม่มี student_id");
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง', 'debug' => $e->getMessage()]);
    exit;
}

// ==== รับค่า registration_id ====
$registration_id = $_GET['id'] ?? null;
if (!$registration_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ registration_id']);
    exit;
}

// ==== ดึงข้อมูลจากฐานข้อมูล ====
try {
    $stmt = $pdo->prepare("SELECT * FROM exam_slot_registrations WHERE id = ? AND student_id = ?");
    $stmt->execute([$registration_id, $student_id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลการลงทะเบียน']);
        exit;
    }

    echo json_encode(['status' => 'success', 'registration' => $reg]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด', 'debug' => $e->getMessage()]);
}
