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

/* JWT */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
$decoded = decodeToken($m[1]);
if (!$decoded) out(['status' => 'error', 'message' => 'Unauthorized'], 403);
$claims = (array)$decoded;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($role !== 'student' || $student_id === '') out(['status' => 'error', 'message' => 'Unauthorized'], 403);

/* body */
$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$session_id = (int)($body['session_id'] ?? 0);
if ($session_id <= 0) out(['status' => 'error', 'message' => 'Missing session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    $st = $pdo->prepare("
    SELECT se.session_id, se.end_time,
           s.exam_date, s.start_time AS slot_start, s.end_time AS slot_end,
           es.duration_minutes
    FROM examsession se
    JOIN exam_slots s     ON s.id = se.slot_id
    LEFT JOIN examset es  ON es.examset_id = s.examset_id
    WHERE se.session_id = ? AND se.student_id = ?
    LIMIT 1
  ");
    $st->execute([$session_id, $student_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);
    if (!$S) out(['status' => 'error', 'message' => 'Session not found'], 404);

    if (!empty($S['end_time'])) {
        out(['status' => 'closed', 'time_left_secs' => 0]);
    }

    $examStart = $S['exam_date'] . ' ' . $S['slot_start'];
    $slotEnd   = $S['exam_date'] . ' ' . $S['slot_end'];
    $durMin    = (int)($S['duration_minutes'] ?? 0);

    $calcEndByDuration = $durMin > 0
        ? date('Y-m-d H:i:s', strtotime($examStart . " +{$durMin} minutes"))
        : $slotEnd;

    $allowedEndTs = min(strtotime($slotEnd), strtotime($calcEndByDuration));
    $nowTs = time();

    if ($nowTs < strtotime($examStart)) {
        out([
            'status' => 'not_started',
            'exam_start_at' => $examStart,
            'time_left_secs' => max(0, strtotime($examStart) - $nowTs)
        ]);
    }

    $left = max(0, $allowedEndTs - $nowTs);
    out(['status' => ($left > 0 ? 'open' : 'due'), 'time_left_secs' => $left]);
} catch (Throwable $e) {
    error_log('[get_exam_timing.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
