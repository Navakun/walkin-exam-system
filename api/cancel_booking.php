<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Allow CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request body
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['booking_id']) || !isset($data['student_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน']);
    exit;
}

$booking_id = $data['booking_id'];
$student_id = $data['student_id'];

// Get and verify authorization header
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';

if (!$auth_header) {
    error_log('No Authorization header found');
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง']);
    exit;
}

// Extract token
$token = str_replace('Bearer ', '', $auth_header);

try {
    // Verify JWT token
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    
    if (!isset($decoded->student_id) || $decoded->student_id !== $student_id) {
        throw new Exception('Invalid token');
    }

    // Connect to database
    $conn = pdo();

    // Debug data
    error_log('Booking ID: ' . $booking_id);
    error_log('Student ID: ' . $student_id);
    
    // ตรวจสอบการจองและวันที่สอบ
    $sql = "SELECT es.slot_date, er.id
            FROM exam_slots es
            JOIN exam_registrations er ON es.id = er.slot_id
            WHERE er.id = :booking_id 
            AND er.student_id = :student_id 
            AND er.status = 'registered'";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':booking_id' => $booking_id,
        ':student_id' => $student_id
    ]);
    
    error_log('SQL Query: ' . $sql);
    
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('ไม่พบการจองหรือถูกยกเลิกไปแล้ว');
    }

    // ตรวจสอบจำนวนวันก่อนสอบ
    $slot_date = new DateTime($row['slot_date']);
    $today = new DateTime();
    $interval = $today->diff($slot_date);
    $days_until_exam = $interval->days;

    if ($days_until_exam < 3 || $slot_date <= $today) {
        throw new Exception('ไม่สามารถยกเลิกการจองได้ เนื่องจากใกล้วันสอบเกินไป (ต้องยกเลิกล่วงหน้าอย่างน้อย 3 วัน)');
    }

    // ทำการยกเลิกการจอง
    $sql = "UPDATE exam_registrations SET status = 'cancelled', cancelled_at = NOW() WHERE id = :booking_id AND student_id = :student_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':booking_id' => $booking_id,
        ':student_id' => $student_id
    ]);
    
    error_log('Update SQL: ' . $sql);

    if ($stmt->rowCount() === 0) {
        throw new Exception('ไม่สามารถยกเลิกการจองได้');
    }

    echo json_encode(['status' => 'success', 'message' => 'ยกเลิกการจองสำเร็จ']);

} catch (Exception $e) {
    error_log('Error in cancel_booking.php: ' . $e->getMessage());
    $status_code = 400;
    
    if ($e->getMessage() === 'Invalid token') {
        $status_code = 401;
    } else if ($e->getMessage() === 'ไม่พบการจองหรือถูกยกเลิกไปแล้ว') {
        $status_code = 404;
    }
    
    http_response_code($status_code);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}