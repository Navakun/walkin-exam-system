<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// ตรวจสอบ Authorization
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
$auth_header = $headers['Authorization'];
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}
$token = $matches[1];
try {
    $decoded = JWT::decode($token, new Key("d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef", 'HS256'));
    if (!isset($decoded->role) || $decoded->role !== 'student') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึงข้อมูล');
    }
    $student_id = $decoded->student_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

try {
    $sql = "
        SELECT 
            b.booking_id,
            b.slot_id,
            es.slot_date,
            es.start_time,
            es.end_time,
            COALESCE(e.title, 'ยังไม่กำหนดชุดข้อสอบ') as exam_title,
            b.status,
            b.scheduled_at
        FROM exambooking b
        JOIN exam_slots es ON b.slot_id = es.id
        LEFT JOIN examset e ON b.examset_id = e.examset_id
        WHERE b.student_id = ?
        ORDER BY es.slot_date ASC, es.start_time ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'status' => 'success',
        'data' => $registrations
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage()
    ]);
}
