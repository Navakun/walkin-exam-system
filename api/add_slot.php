<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/jwt_helper.php';

$headers = getallheaders();

// ตรวจสอบ JWT
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือไม่พบ token']);
    exit;
}
$token = $matches[1];
$userData = verifyJwtToken($token);

if (!$userData || ($userData['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

// รับค่าจาก frontend
$exam_date    = $data['slot_date'] ?? null; // เปลี่ยนเป็น exam_date
$start_time   = $data['start_time'] ?? null;
$end_time     = $data['end_time'] ?? null;
$max_seats    = $data['max_seats'] ?? null;
$reg_open_at  = $data['reg_open_at'] ? str_replace("T", " ", $data['reg_open_at']) . ":00" : null;
$reg_close_at = $data['reg_close_at'] ? str_replace("T", " ", $data['reg_close_at']) . ":00" : null;

$examset_title = $data['examset_title'] ?? null;
$easy_count    = $data['easy_count'] ?? 0;
$medium_count  = $data['medium_count'] ?? 0;
$hard_count    = $data['hard_count'] ?? 0;
$duration      = $data['duration_minutes'] ?? 60;

if (!$exam_date || !$start_time || !$end_time || !$max_seats || !$examset_title) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

try {
    // 1. Insert examset
    $stmtExamset = $pdo->prepare("
        INSERT INTO examset (title, easy_count, medium_count, hard_count, duration_minutes, created_by)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmtExamset->execute([
        $examset_title,
        $easy_count,
        $medium_count,
        $hard_count,
        $duration,
        $userData['instructor_id'] ?? 'Unknown'
    ]);
    $examset_id = $pdo->lastInsertId();

    // 2. Insert exam slot
    $stmtSlot = $pdo->prepare("
        INSERT INTO exam_slots (
            exam_date, start_time, end_time, max_seats,
            reg_open_at, reg_close_at, created_by, examset_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtSlot->execute([
        $exam_date,
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
    echo json_encode(['status' => 'error', 'message' => 'DB_ERROR', 'debug' => $e->getMessage()]);
}
