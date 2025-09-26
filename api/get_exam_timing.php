<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- JWT ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
$decoded = decodeToken($m[1]);
if (!$decoded) {
    out(['status' => 'error', 'message' => 'Unauthorized (invalid/expired token)'], 403);
}
$claims = (array)$decoded;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? 'student')); // ยอมรับกรณีไม่มี role
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? $claims['uid'] ?? '');

if ($role !== 'student') {
    out(['status' => 'error', 'message' => 'Unauthorized (role)'], 403);
}
if ($student_id === '') {
    out(['status' => 'error', 'message' => 'Missing student_id'], 403);
}

/* ---------- body ---------- */
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) {
    out(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
}
$session_id = (int)($body['session_id'] ?? 0);
if ($session_id <= 0) {
    out(['status' => 'error', 'message' => 'Missing session_id'], 400);
}

try {
    // ให้เวลา MySQL เป็น +07:00
    $pdo->exec("SET time_zone = '+07:00'");

    // ดึง session ของนิสิตคนนี้เท่านั้น
    $st = $pdo->prepare("
    SELECT s.session_id, s.student_id, s.slot_id,
           s.start_time, s.deadline_at, s.end_time
    FROM examsession s
    WHERE s.session_id = ? AND s.student_id = ?
    LIMIT 1
  ");
    $st->execute([$session_id, $student_id]);
    $sess = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        out(['status' => 'error', 'message' => 'Session not found or not your session'], 404);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('+07:00'));
    $deadline = isset($sess['deadline_at']) ? new DateTimeImmutable($sess['deadline_at']) : null;
    $end = isset($sess['end_time']) ? new DateTimeImmutable($sess['end_time']) : null;

    // กรณีจบแล้ว
    if ($end !== null) {
        out([
            'status'          => 'closed',
            'time_left_secs'  => 0,
            'now'             => $now->format('Y-m-d H:i:s'),
            'deadline_at'     => $deadline?->format('Y-m-d H:i:s'),
            'end_time'        => $end->format('Y-m-d H:i:s'),
        ]);
    }

    // ยังไม่จบ แต่เลย deadline
    if ($deadline !== null && $now >= $deadline) {
        out([
            'status'          => 'due',
            'time_left_secs'  => 0,
            'now'             => $now->format('Y-m-d H:i:s'),
            'deadline_at'     => $deadline->format('Y-m-d H:i:s'),
            'end_time'        => null,
        ]);
    }

    // ยังทำได้
    $left = $deadline ? max(0, $deadline->getTimestamp() - $now->getTimestamp()) : 0;

    out([
        'status'          => 'open',            // หรือจะใส่ 'success' ก็ได้ ฝั่งหน้าเช็คทั้งคู่
        'time_left_secs'  => $left,
        'now'             => $now->format('Y-m-d H:i:s'),
        'deadline_at'     => $deadline?->format('Y-m-d H:i:s'),
        'end_time'        => null,
        'session_id'      => (int)$sess['session_id'],
    ]);
} catch (Throwable $e) {
    error_log('[get_exam_timing.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
