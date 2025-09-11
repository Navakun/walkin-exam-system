<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบ JWT
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
if (!preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}
$token = $matches[1];
try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception();
} catch (Exception) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token หมดอายุหรือไม่ถูกต้อง']);
    exit;
}

// รับข้อมูลจาก frontend
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
$slot_id = $data['slot_id'] ?? null;

if (!$slot_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

try {
    // ตรวจสอบการจองซ้ำ
    $stmt = $pdo->prepare("SELECT 1 FROM exambooking WHERE student_id = ? AND slot_id = ?");
    $stmt->execute([$student_id, $slot_id]);
    if ($stmt->fetch()) throw new Exception("คุณได้ลงทะเบียนรอบนี้แล้ว");

    // ตรวจสอบ slot
    $stmt = $pdo->prepare("
        SELECT es.*, 
               (SELECT COUNT(*) FROM exambooking WHERE slot_id = es.id) AS booked_count
        FROM exam_slots es
        WHERE es.id = ?
    ");
    $stmt->execute([$slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) throw new Exception('ไม่พบข้อมูลรอบสอบ');

    // ตรวจสอบสถานะเวลา
    $now = new DateTime();
    $reg_open = new DateTime($slot['reg_open_at']);
    $reg_close = new DateTime($slot['reg_close_at']);
    $exam_end = new DateTime($slot['slot_date'] . ' ' . $slot['end_time']);

    if ($now < $reg_open) throw new Exception("ยังไม่ถึงเวลาลงทะเบียน");
    if ($now > $reg_close) throw new Exception("หมดเขตลงทะเบียน");
    if ($now > $exam_end) throw new Exception("เลยเวลาสอบแล้ว");
    if ($slot['booked_count'] >= $slot['max_seats']) throw new Exception("ที่นั่งเต็มแล้ว");

    // ลงทะเบียนทั้ง 2 ตาราง
    $pdo->beginTransaction();

    // 1. บันทึกลง exambooking
    $stmt = $pdo->prepare("INSERT INTO exambooking (student_id, slot_id, scheduled_at, status) VALUES (?, ?, NOW(), 'registered')");
    $stmt->execute([$student_id, $slot_id]);
    $booking_id = $pdo->lastInsertId();

    // 2. บันทึกลง exam_slot_registrations
    $stmt = $pdo->prepare("INSERT INTO exam_slot_registrations (student_id, slot_id, registered_at) VALUES (?, ?, NOW())");
    $stmt->execute([$student_id, $slot_id]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'ลงทะเบียนสำเร็จ',
        'booking_id' => $booking_id
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
