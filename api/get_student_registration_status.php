<?php
ini_set('display_errors', 1);
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
$auth_header = $headers['Authorization'];
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}
$token = $matches[1];
try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
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
            es.examset_id,
            b.status,
            b.scheduled_at,
            es.slot_date,
            es.start_time,
            es.end_time,
            es.max_seats,
            es.booked_count,
            exs.session_id,
            CASE 
                WHEN es.reg_close_at < NOW() THEN 'closed'
                WHEN es.booked_count >= es.max_seats THEN 'full'
                ELSE 'available'
            END as slot_status,
            CASE 
                WHEN exs.session_id IS NOT NULL THEN true
                ELSE false
            END as has_active_session
        FROM exam_booking b
        JOIN exam_slots es ON b.slot_id = es.id
        LEFT JOIN examsession exs 
            ON exs.student_id = b.student_id 
            AND exs.slot_id = b.slot_id
            AND exs.end_time IS NULL
        WHERE b.student_id = ?
        ORDER BY es.slot_date ASC, es.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($registrations as &$reg) {
        $reg['can_start_exam'] = (
            $reg['status'] === 'registered' &&
            $reg['slot_status'] === 'available' &&
            !$reg['has_active_session']
        );
        $reg['booking_id'] = (int)$reg['booking_id'];
        $reg['slot_id'] = (int)$reg['slot_id'];
        $reg['examset_id'] = (int)$reg['examset_id'];
        if ($reg['session_id']) {
            $reg['session_id'] = (int)$reg['session_id'];
        }
    }
    unset($reg);
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
