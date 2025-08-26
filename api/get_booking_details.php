<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

$student_id = isset($_GET['student_id']) ? $_GET['student_id'] : null;
if (!$student_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing student_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT booking_id, slot_id, examset_id, scheduled_at, status FROM exambooking WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        'status' => 'success',
        'data' => $bookings
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
