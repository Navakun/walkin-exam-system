<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/jwt_helper.php';

// เปิด debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ตรวจสอบ JWT
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token format']);
    exit;
}

$token = $matches[1];
$user = decodeToken($token);
if (!$user || !isset($user['student_id'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    $sql = "
        SELECT 
            es.id,
            es.exam_date,
            es.start_time,
            es.end_time,
            es.max_seats,
            es.reg_open_at,
            es.reg_close_at,
            es.created_by,
            e.title AS examset_title,
            COUNT(DISTINCT r.id) AS booked_count
        FROM exam_slots es
        LEFT JOIN exam_slot_registrations r ON r.slot_id = es.id
        LEFT JOIN examset e ON es.examset_id = e.examset_id
        GROUP BY es.id, es.exam_date, es.start_time, es.end_time, es.max_seats,
                 es.reg_open_at, es.reg_close_at, es.created_by, e.title
        ORDER BY es.exam_date ASC, es.start_time ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'now' => date('Y-m-d H:i:s'),
        'slots' => $slots
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'SERVER_ERROR',
        'debug' => $e->getMessage()
    ]);
}
