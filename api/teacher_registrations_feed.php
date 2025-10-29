<?php
// api/teacher_registrations_feed.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verify_token.php';

function json_error($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ----- AUTH -----
    $token = getBearerToken();
    if (!$token) json_error('No token', 401);
    $payload = decodeToken($token);
    if (!$payload) json_error('Invalid token', 401);
    $role = strtolower(strval($payload->role ?? $payload->user_role ?? ''));
    $teacherId = $payload->teacher_id ?? $payload->instructor_id ?? null;
    if ($role !== 'teacher' && !$teacherId) json_error('Forbidden', 403);

    // ----- INPUT -----
    $range = $_GET['range'] ?? '30d';
    $days = 30;
    if ($range === '7d') $days = 7;
    else if ($range === '90d') $days = 90;

    // ----- QUERY (ฐานข้อมูลล่าสุดใช้ exam_slot_registrations) -----
    $sql = "SELECT
                s.student_id,
                TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
                r.registered_at AS booked_at,
                sl.title AS slot_title
            FROM exam_slot_registrations r
            JOIN student s ON s.student_id = r.student_id
            LEFT JOIN exam_slots sl ON sl.id = r.slot_id
            WHERE r.registered_at >= (NOW() - INTERVAL ? DAY)
            ORDER BY r.registered_at DESC
            LIMIT 5000";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$days]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'data' => $rows
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
