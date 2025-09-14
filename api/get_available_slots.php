<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once '../config/db.php';

// ==================
// 🔹 ตรวจสอบ JWT
// ==================
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'No token provided',
        'error_code' => 'NO_TOKEN'
    ]);
    exit;
}

$token = $matches[1];
$decoded = decodeToken($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized',
        'error_code' => 'BAD_TOKEN'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
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
            COUNT(DISTINCT r.id) AS registered_count,
            (es.max_seats - COUNT(DISTINCT r.id)) AS available_seats
        FROM exam_slots es
        LEFT JOIN exam_slot_registrations r ON r.slot_id = es.id
        LEFT JOIN examset e ON es.examset_id = e.examset_id
        WHERE NOW() BETWEEN es.reg_open_at AND es.reg_close_at
        GROUP BY es.id
        ORDER BY es.exam_date, es.start_time
    ");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'now' => date('Y-m-d H:i:s'),
        'slots' => $slots
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'SERVER_ERROR',
        'error_code' => 'TABLE_OR_COLUMNS_NOT_FOUND',
        'debug' => $e->getMessage()
    ]);
}
