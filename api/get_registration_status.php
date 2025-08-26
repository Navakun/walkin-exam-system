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
    // ตรวจสอบว่าเป็น token ของอาจารย์
    if (!isset($decoded->role) || $decoded->role !== 'teacher') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึงข้อมูล');
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}

try {
    error_log("Fetching registration data...");
    
    // ตรวจสอบข้อมูลในตาราง exambooking
    $check_sql = "SELECT COUNT(*) as count FROM exambooking";
    $check_stmt = $pdo->query($check_sql);
    $booking_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    error_log("Total bookings in database: " . $booking_count);
    
    // ดึงข้อมูลการลงทะเบียนทั้งหมด
    $sql = "
        SELECT 
            b.booking_id,
            s.student_id,
            s.name as student_name,
            es.slot_date as exam_date,
            es.start_time,
            es.end_time,
            COALESCE(e.title, 'ยังไม่กำหนดชุดข้อสอบ') as exam_title,
            b.status,
            b.scheduled_at
        FROM exambooking b
        JOIN student s ON b.student_id = s.student_id
        JOIN exam_slots es ON b.slot_id = es.id
        LEFT JOIN examset e ON b.examset_id = e.examset_id
        ORDER BY es.slot_date ASC, es.start_time ASC
    ";

    error_log("Executing query: " . $sql);
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Found " . count($registrations) . " registrations");

    // จัดรูปแบบข้อมูลก่อนส่งกลับ
    $formatted_registrations = array_map(function($reg) {
        return [
            'booking_id' => $reg['booking_id'],
            'student_id' => $reg['student_id'],
            'student_name' => $reg['student_name'],
            'exam_date' => $reg['exam_date'],
            'exam_time' => $reg['start_time'] . ' - ' . $reg['end_time'],
            'exam_title' => $reg['exam_title'],
            'status' => $reg['status'],
            'scheduled_at' => $reg['scheduled_at']
        ];
    }, $registrations);

    echo json_encode([
        'status' => 'success',
        'registrations' => $formatted_registrations
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage()
    ]);
}
