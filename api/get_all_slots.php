<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once __DIR__ . '/../config/db.php';

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided', 'error_code' => 'NO_TOKEN']);
    exit;
}

if (!preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token format', 'error_code' => 'BAD_TOKEN']);
    exit;
}

$token = $matches[1];
$decoded = decodeToken($token);
if (!$decoded || ($decoded['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden', 'error_code' => 'NOT_TEACHER']);
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
            COUNT(DISTINCT r.id) AS booked_count,
            e.title AS examset_title
        FROM exam_slots es
        LEFT JOIN exam_slot_registrations r ON r.slot_id = es.id
        LEFT JOIN examset e ON es.examset_id = e.examset_id
        GROUP BY es.id, es.exam_date, es.start_time, es.end_time, es.max_seats, 
                 es.reg_open_at, es.reg_close_at, es.created_by, e.title
        ORDER BY es.exam_date, es.start_time
    ");
    $stmt->execute();
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'slots' => $slots
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'SERVER_ERROR',
        'error_code' => 'TABLE_OR_COLUMNS_NOT_FOUND',
        'debug' => $e->getMessage()
    ]);
}
    