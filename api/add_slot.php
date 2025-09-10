<?php
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

if (!$userData || ($userData['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล',
        'debug' => $userData
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// รับข้อมูลสำหรับสร้าง slot
$slot_date = $data['slot_date'] ?? null;
$start_time = $data['start_time'] ?? null;
$end_time = $data['end_time'] ?? null;
$max_seats = $data['max_seats'] ?? null;
$reg_open_at = $data['reg_open_at'] ?? null;
$reg_close_at = $data['reg_close_at'] ?? null;

// รับข้อมูลชุดข้อสอบ
$examset_title = $data['examset_title'] ?? null;
$easy_count = $data['easy_count'] ?? 0;
$medium_count = $data['medium_count'] ?? 0;
$hard_count = $data['hard_count'] ?? 0;

if (!$slot_date || !$start_time || !$end_time || !$max_seats || !$examset_title) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

try {
    // 1. สร้างชุดข้อสอบใหม่ใน examset
    $stmtExamset = $pdo->prepare("
        INSERT INTO examset (title, easy_count, medium_count, hard_count, created_by)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmtExamset->execute([
        $examset_title,
        $easy_count,
        $medium_count,
        $hard_count,
        $userData['instructor_id'] ?? 'Unknown'
    ]);

    $examset_id = $pdo->lastInsertId();

    // 2. สร้าง slot ที่ผูกกับ examset นี้
    $stmtSlot = $pdo->prepare("
        INSERT INTO exam_slots (
            slot_date, start_time, end_time, max_seats,
            reg_open_at, reg_close_at, created_by, examset_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmtSlot->execute([
        $slot_date,
        $start_time,
        $end_time,
        $max_seats,
        $reg_open_at,
        $reg_close_at,
        $userData['instructor_id'] ?? 'Unknown',
        $examset_id
    ]);

    echo json_encode(['status' => 'success', 'examset_id' => $examset_id]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'DB_ERROR',
        'debug' => $e->getMessage()
    ]);
}
