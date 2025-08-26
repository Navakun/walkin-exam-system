<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ตรวจสอบ Authorization
$headers = getallheaders();
error_log('Request headers: ' . print_r($headers, true));

if (!isset($headers['Authorization'])) {
    error_log('No Authorization header found');
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
$secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";

try {
    // Debug log
    error_log("Received token: " . $token);
    
    // ตรวจสอบ token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    error_log("Decoded token: " . print_r($decoded, true));
    
    // ตรวจสอบ role (ยอมรับทั้งอาจารย์และนักศึกษา)
    if (!isset($decoded->role)) {
        error_log("Token missing role");
        throw new Exception('Token ไม่ถูกต้อง - ไม่พบข้อมูล role');
    }
    
    error_log("User role: " . $decoded->role);

    $sql = "
    SELECT 
        es.id, 
        es.examset_id, -- เพิ่ม field นี้
        es.slot_date, 
        es.start_time, 
        es.end_time, 
        es.max_seats,
        e.title AS exam_title,
        COALESCE((
            SELECT COUNT(*) 
            FROM exambooking eb 
            WHERE eb.slot_id = es.id 
            AND eb.status = 'registered'
        ), 0) AS booked_count
    FROM exam_slots es
    LEFT JOIN ExamSet e ON es.examset_id = e.examset_id
    WHERE es.slot_date >= CURDATE()
    ORDER BY es.slot_date ASC, es.start_time ASC
";

    error_log("Executing query: " . $sql);
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Found " . count($slots) . " slots");
    
    echo json_encode([
        'status' => 'success',
        'data' => $slots,
        'message' => empty($slots) ? 'ยังไม่มีช่วงเวลาสอบที่เปิดให้ลงทะเบียน' : null
    ]);

} catch (PDOException $e) {
    error_log("Database Error in get_available_slots.php: " . $e->getMessage());
    error_log("SQL Query: " . $sql);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล',
        'error' => $e->getMessage(),
        'query' => $sql
    ]);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
