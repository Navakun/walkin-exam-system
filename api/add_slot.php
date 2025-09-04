<?php
$rawData = file_get_contents('php://input');
error_log("📦 RAW INPUT: " . $rawData);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/jwt_helper.php';

$headers = getallheaders();

// ตรวจสอบ Authorization Header
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือไม่พบ token']);
    exit;
}

$token = $matches[1];
$userData = verifyJwtToken($token);
error_log('Decoded user: ' . print_r($userData, true));
error_log("👀 userData: " . json_encode($userData));


if (!$userData) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}

error_log('TOKEN ROLE: ' . ($userData['role'] ?? 'NULL'));

if (!$userData || ($userData['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล',
        'debug' => $userData
    ]);
    exit;
}

// รับค่าจาก JSON body
$data = json_decode(file_get_contents('php://input'), true);

$slot_date = $data['slot_date'] ?? null;
$start_time = $data['start_time'] ?? null;
$end_time = $data['end_time'] ?? null;
$max_seats = $data['max_seats'] ?? null;
$reg_open_at = $data['reg_open_at'] ?? null;
$reg_close_at = $data['reg_close_at'] ?? null;

// ตรวจสอบข้อมูลที่จำเป็น
if (!$slot_date || !$start_time || !$end_time || !$max_seats) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO exam_slots (slot_date, start_time, end_time, max_seats, reg_open_at, reg_close_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $slot_date,
        $start_time,
        $end_time,
        $max_seats,
        $reg_open_at,
        $reg_close_at,
        $userData['instructor_id'] ?? 'Unknown'
    ]);

    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB_ERROR', 'debug' => $e->getMessage()]);
}
