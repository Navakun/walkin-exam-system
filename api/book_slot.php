<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

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
    if (empty($jwt_key)) throw new Exception('JWT key not configured');
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    if (!isset($decoded->student_id)) throw new Exception('Token ไม่พบ student_id');
    $student_id = $decoded->student_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ']);
    exit;
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('รูปแบบข้อมูลไม่ถูกต้อง');
    }

    if (!isset($data['slot_id'])) {
        throw new Exception('กรุณาระบุช่วงเวลาสอบ');
    }
    $slot_id = $data['slot_id'];

    // ตรวจสอบการลงทะเบียนซ้ำ
    $stmt = $pdo->prepare("SELECT booking_id FROM exambooking WHERE student_id = :student_id AND slot_id = :slot_id");
    $stmt->execute([':student_id' => $student_id, ':slot_id' => $slot_id]);
    if ($stmt->rowCount() > 0) {
        throw new Exception('คุณได้ลงทะเบียนช่วงเวลานี้แล้ว');
    }

    // ตรวจสอบ slot
    $stmt = $pdo->prepare("
        SELECT es.*, 
               (SELECT COUNT(*) FROM exambooking WHERE slot_id = es.id) AS booked_count
        FROM exam_slots es
        WHERE es.id = :slot_id
          AND es.slot_date >= CURDATE()
    ");
    $stmt->execute([':slot_id' => $slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) throw new Exception('ไม่พบช่วงเวลาสอบที่เลือก');
    if ($slot['booked_count'] >= $slot['max_seats']) {
        throw new Exception('ที่นั่งเต็มแล้ว');
    }

    // ลงทะเบียนสอบ
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        INSERT INTO exambooking (
            student_id, slot_id, scheduled_at, status
        ) VALUES (
            :student_id, :slot_id, :scheduled_at, 'registered'
        )
    ");
    $stmt->execute([
        ':student_id' => $student_id,
        ':slot_id' => $slot_id,
        ':scheduled_at' => date('Y-m-d H:i:s')
    ]);
    $booking_id = $pdo->lastInsertId();
    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'ลงทะเบียนสำเร็จ',
        'session_id' => $booking_id,
        'booking_id' => $booking_id,
        'slot_id' => $slot_id
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
