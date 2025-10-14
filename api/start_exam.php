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
if (!$decoded) out(['status' => 'error', 'message' => 'Unauthorized (invalid/expired token)'], 403);

$claims = (array)$decoded;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($role !== 'student') out(['status' => 'error', 'message' => 'Unauthorized (role)'], 403);
if ($student_id === '') out(['status' => 'error', 'message' => 'Missing student_id'], 403);

/* ---------- body ---------- */
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) out(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
$slot_id = (int)($body['slot_id'] ?? 0);
if ($slot_id <= 0) out(['status' => 'error', 'message' => 'Missing slot_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // ดึงสิทธิ์ลงทะเบียน + เวลา slot แบบใหม่ (start_at / end_at)
    $st = $pdo->prepare("
    SELECT
      esr.id AS registration_id,
      esr.payment_status,
      s.start_at,
      s.end_at,
      s.examset_id,
      es.duration_minutes
    FROM exam_slot_registrations esr
    JOIN exam_slots s     ON s.id = esr.slot_id
    LEFT JOIN examset es  ON es.examset_id = s.examset_id
    WHERE esr.student_id = ? AND esr.slot_id = ?
    ORDER BY esr.registered_at DESC
    LIMIT 1
  ");
    $st->execute([$student_id, $slot_id]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);
    if (!$reg) out(['status' => 'error', 'message' => 'ยังไม่ได้ลงทะเบียนรอบนี้'], 403);

    if (!in_array($reg['payment_status'], ['free', 'paid', 'waived'], true)) {
        out(['status' => 'error', 'message' => 'ยังไม่ชำระเงินหรือรอการตรวจสอบ'], 403);
    }

    $examStart = $reg['start_at']; // DATETIME
    $slotEnd   = $reg['end_at'];   // DATETIME
    $durMin    = (int)($reg['duration_minutes'] ?? 0);

    // เวลาอนุญาตสิ้นสุด = เร็วสุดระหว่าง end_at และ start_at + duration
    $calcEndByDuration = $durMin > 0
        ? date('Y-m-d H:i:s', strtotime($examStart . " +{$durMin} minutes"))
        : $slotEnd;

    $allowedEndTs = min(strtotime($slotEnd), strtotime($calcEndByDuration));
    $nowTs = time();

    if ($nowTs < strtotime($examStart)) {
        out([
            'status' => 'not_started',
            'message' => 'ยังไม่ถึงเวลาเริ่มสอบ',
            'exam_start_at' => $examStart,
            'starts_in' => max(0, strtotime($examStart) - $nowTs)
        ], 403);
    }

    if ($nowTs >= $allowedEndTs) {
        out([
            'status' => 'closed',
            'message' => 'หมดเวลาหรือรอบสอบจบแล้ว',
            'slot_end_at' => $slotEnd,
            'allowed_end_at' => date('Y-m-d H:i:s', $allowedEndTs)
        ], 403);
    }

    $timeRemaining = max(0, $allowedEndTs - $nowTs);

    // ถ้ามี session เปิดอยู่แล้ว ส่งค่านั้นกลับไป
    $st = $pdo->prepare("
    SELECT session_id
    FROM examsession
    WHERE student_id = ? AND slot_id = ? AND end_time IS NULL
    ORDER BY start_time DESC
    LIMIT 1
  ");
    $st->execute([$student_id, $slot_id]);
    if ($sid = $st->fetchColumn()) {
        out([
            'status'           => 'success',
            'session_id'       => (int)$sid,
            'slot_id'          => $slot_id,
            'examset_id'       => (int)($reg['examset_id'] ?? 0),
            'duration_minutes' => $durMin,
            'exam_start_at'    => $examStart,
            'slot_end_at'      => $slotEnd,
            'allowed_end_at'   => date('Y-m-d H:i:s', $allowedEndTs),
            'time_remaining'   => $timeRemaining,
            'server_time'      => date('Y-m-d H:i:s'),
        ]);
    }

    // หา attempt ถัดไป (ทั้งระบบ หรือจะจำกัดต่อ slot ก็ได้)
    $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no),0) + 1 FROM examsession WHERE student_id = ?");
    $st->execute([$student_id]);
    $nextAttempt = (int)$st->fetchColumn();
    if ($nextAttempt <= 0) $nextAttempt = 1;

    // สร้าง session
    $st = $pdo->prepare("
    INSERT INTO examsession (student_id, slot_id, start_time, attempt_no)
    VALUES (?, ?, NOW(), ?)
  ");
    $st->execute([$student_id, $slot_id, $nextAttempt]);
    $session_id = (int)$pdo->lastInsertId();

    out([
        'status'           => 'success',
        'session_id'       => $session_id,
        'slot_id'          => $slot_id,
        'examset_id'       => (int)($reg['examset_id'] ?? 0),
        'duration_minutes' => $durMin,
        'exam_start_at'    => $examStart,
        'slot_end_at'      => $slotEnd,
        'allowed_end_at'   => date('Y-m-d H:i:s', $allowedEndTs),
        'time_remaining'   => $timeRemaining,
        'server_time'      => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log('[start_exam.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
