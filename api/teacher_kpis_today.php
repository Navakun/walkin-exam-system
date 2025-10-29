<?php
// api/teacher_kpis_today.php
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
    // AUTH
    $token = getBearerToken();
    if (!$token) json_error('No token', 401);
    $payload = decodeToken($token);
    if (!$payload) json_error('Invalid token', 401);
    $role = strtolower(strval($payload->role ?? $payload->user_role ?? ''));
    $teacherId = $payload->teacher_id ?? $payload->instructor_id ?? null;
    if ($role !== 'teacher' && !$teacherId) json_error('Forbidden', 403);

    // optional timezone (บางโฮสต์ห้าม SET time_zone)
    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) { /* ignore */
    }

    $out = [
        'registered_today' => 0,
        'completed_today'  => 0,
        'questions_total'  => 0,
    ];

    // 1) registered_today — ลองจาก exam_slot_registrations ก่อน แล้วค่อย fallback exambooking
    $ok = false;
    $lastErr = null;
    foreach (
        [
            // current structure
            "SELECT COUNT(*) AS c FROM exam_slot_registrations WHERE DATE(registered_at) = CURDATE()",
            // legacy booking table
            "SELECT COUNT(*) AS c FROM exam_booking WHERE DATE(COALESCE(created_at, booking_time)) = CURDATE() AND (status IS NULL OR status='booked')"
        ] as $sql
    ) {
        try {
            $c = (int)$pdo->query($sql)->fetchColumn();
            $out['registered_today'] = $c;
            $ok = true;
            break;
        } catch (Throwable $e) {
            $lastErr = $e;
        }
    }
    if (!$ok && $lastErr) throw $lastErr;

    // 2) completed_today — นับจาก examsession ถ้ามี end_time วันนี้
    try {
        $out['completed_today'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL AND DATE(end_time) = CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
        // ไม่มีตาราง/คอลัมน์นี้ก็ปล่อย 0
    }

    // 3) questions_total — ทั้งหมดใน bank
    try {
        $out['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) {
        // ไม่มีตารางก็ปล่อย 0
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
