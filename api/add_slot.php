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
$secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";

try {
    // ตรวจสอบ token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    
    // ตรวจสอบว่าเป็น token ของอาจารย์
    if (!isset($decoded->role) || $decoded->role !== 'teacher') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึงข้อมูล');
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    exit;
}


// รับข้อมูล JSON จาก request body
$data = json_decode(file_get_contents('php://input'), true);

// รับค่าจาก POST/JSON
$slot_date = $data['slot_date'] ?? null;
$start_time = $data['start_time'] ?? null;
$end_time = $data['end_time'] ?? null;
$max_seats = $data['max_seats'] ?? null;
$examset_id = $data['examset_id'] ?? null;

// ถ้ายังไม่มี $examset_id ให้สุ่มจาก DB
if (!$examset_id) {
    $stmt = $pdo->query("SELECT examset_id FROM examset ORDER BY RAND() LIMIT 1");
    $randomSet = $stmt->fetch(PDO::FETCH_ASSOC);
    $examset_id = $randomSet['examset_id'] ?? null;
}

// ตรวจสอบความครบถ้วน
if (!$examset_id || !$slot_date || !$start_time || !$end_time || !$max_seats) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน'
    ]);
    exit;
}

// ตรวจสอบความถูกต้องของข้อมูล
if (empty($slot_date) || empty($start_time) || empty($end_time) || empty($max_seats)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'กรุณากรอกข้อมูลให้ครบทุกช่อง'
    ]);
    exit;
}

// ตรวจสอบรูปแบบวันที่
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $slot_date)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'รูปแบบวันที่ไม่ถูกต้อง'
    ]);
    exit;
}

// ... (โค้ดด้านบนเหมือนเดิมจนถึง try block ใหญ่)

try {
    // ✅ สุ่ม examset_id จากฐานข้อมูล
    $stmtExam = $pdo->query("SELECT examset_id FROM examset ORDER BY RAND() LIMIT 1");
    $randomExamset = $stmtExam->fetch(PDO::FETCH_ASSOC);
    
    if (!$randomExamset) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'ไม่พบชุดข้อสอบในระบบ กรุณาสร้างชุดข้อสอบก่อน'
        ]);
        exit;
    }

    $examset_id = $randomExamset['examset_id'];

    // DEBUG LOG
    error_log("สุ่มได้ examset_id: " . $examset_id);

    // ตรวจสอบว่าช่วงเวลาซ้ำหรือไม่
    $check_sql = "SELECT COUNT(*) FROM exam_slots 
                  WHERE slot_date = :slot_date AND 
                  ((start_time <= :end_time AND end_time >= :start_time))";

    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([
        ':slot_date' => $slot_date,
        ':start_time' => $start_time,
        ':end_time' => $end_time
    ]);
    
    if ($check_stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'ช่วงเวลาสอบนี้ซ้ำกับช่วงเวลาที่มีอยู่แล้ว'
        ]);
        exit;
    }

    // ✅ เพิ่มช่วงเวลาสอบใหม่ พร้อม examset_id
    $sql = "INSERT INTO exam_slots (examset_id, slot_date, start_time, end_time, max_seats) 
            VALUES (:examset_id, :slot_date, :start_time, :end_time, :max_seats)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':examset_id' => $examset_id,
        ':slot_date' => $slot_date,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':max_seats' => $max_seats
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'เพิ่มช่วงเวลาสอบสำเร็จ',
        'slot_id' => $pdo->lastInsertId()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
    error_log("Add Slot Error: " . $e->getMessage());
}


