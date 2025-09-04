<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// ตรวจสอบ Authorization
error_log("book_slot.php called");
$headers = getallheaders();
error_log("Headers received: " . print_r($headers, true));

if (!isset($headers['Authorization'])) {
    error_log("No Authorization header found");
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
    if (empty($jwt_key)) {
        throw new Exception('JWT key not configured');
    }
    error_log("Decoding token...");
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    error_log("Decoded token: " . print_r($decoded, true));
    
    if (!isset($decoded->student_id)) {
        throw new Exception('Token ไม่ถูกต้อง: ไม่พบ student_id');
    }
    
    $student_id = $decoded->student_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ']);
    exit;
}

try {
    $raw_data = file_get_contents("php://input");
    error_log("Received request body: " . $raw_data);
    
    $data = json_decode($raw_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON format: ' . json_last_error_msg());
    }

    if (!isset($data['slot_id'])) {
        throw new Exception('กรุณาระบุช่วงเวลาสอบ');
    }
    
    error_log("Parsed data: " . print_r($data, true));

    $slot_id = $data['slot_id'];

    // ตรวจสอบว่าลงทะเบียนซ้ำหรือไม่
    $check = $pdo->prepare("
        SELECT b.booking_id 
        FROM exambooking b
        WHERE b.student_id = :student_id AND b.slot_id = :slot_id
    ");
    $check->execute([
        ':student_id' => $student_id,
        ':slot_id' => $slot_id
    ]);
    
    if ($check->rowCount() > 0) {
        throw new Exception('คุณได้ลงทะเบียนช่วงเวลานี้แล้ว');
    }

    // ตรวจสอบที่นั่งว่าง
    $stmt = $pdo->prepare("
        SELECT es.*, 
            (SELECT COUNT(*) FROM exambooking WHERE slot_id = es.id) as booked_count
        FROM exam_slots es
        WHERE es.id = :slot_id
        AND es.slot_date >= CURDATE()
    ");
    $stmt->execute([':slot_id' => $slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) {
        throw new Exception('ไม่พบช่วงเวลาสอบที่เลือก');
    }

    if ($slot['booked_count'] >= $slot['max_seats']) {
        throw new Exception('ที่นั่งเต็มแล้ว');
    }

    // สุ่มชุดข้อสอบจากคลัง
    $stmt = $pdo->query("
        SELECT examset_id 
        FROM examset 
        ORDER BY RAND() 
        LIMIT 1
    ");
    $random_examset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$random_examset) {
        throw new Exception('ไม่พบชุดข้อสอบในระบบ กรุณาติดต่อผู้ดูแลระบบ');
    }

    // เพิ่มการจอง
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO exambooking (
                student_id, 
                slot_id,
                examset_id,
                scheduled_at,
                status
            ) VALUES (
                :student_id,
                :slot_id,
                :examset_id,
                :scheduled_at,
                'registered'
            )
        ");

        $insert->execute([
            ':student_id' => $student_id,
            ':slot_id' => $slot_id,
            ':examset_id' => $random_examset['examset_id'],
            ':scheduled_at' => date('Y-m-d H:i:s')
        ]);

        $booking_id = $pdo->lastInsertId(); // ใช้เป็น session_id ก็ได้
        $pdo->commit();

        echo json_encode([
            'status' => 'success',
            'message' => 'ลงทะเบียนสำเร็จ',
            'session_id' => $booking_id,
            'examset_id' => $random_examset['examset_id'],
            'booking_id' => $booking_id
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    error_log("Error in book_slot.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>
