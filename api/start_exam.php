<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php'; // ต้องมีฟังก์ชัน decodeToken($jwt)

/** helper: ส่ง JSON พร้อม status code แล้วจบการทำงาน */
function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===================== 1) อ่านและตรวจ JWT ===================== */
function getAuthorizationHeader(): string
{
    // เผื่อบางเซิร์ฟเวอร์ไม่รองรับ getallheaders()
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers = array_change_key_case($headers ?: [], CASE_LOWER);

    if (!empty($headers['authorization'])) {
        return (string)$headers['authorization'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    return '';
}

$auth = getAuthorizationHeader();
if (!preg_match('/\s*bearer\s+(\S+)/i', $auth, $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}

$jwt = $m[1];
$decoded = decodeToken($jwt);            // ควรคืน object/array หรือ false เมื่อ invalid/expired
if (!$decoded) {
    out(['status' => 'error', 'message' => 'Unauthorized (invalid/expired token)'], 403);
}

$claims = is_array($decoded) ? $decoded : (array)$decoded;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));

// ถ้ามี role และไม่ใช่ student → ปัดทิ้ง (ถ้าไม่มี role ก็ไม่บังคับ)
if ($role !== '' && $role !== 'student') {
    out(['status' => 'error', 'message' => 'Unauthorized (role)'], 403);
}

// รองรับหลายชื่อเคลม: student_id หรือ sub/uid/id
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? $claims['uid'] ?? $claims['id'] ?? '');
if ($student_id === '') {
    out(['status' => 'error', 'message' => 'Unauthorized (missing student_id/sub)'], 403);
}

/* ===================== 2) รับ slot_id จาก body ===================== */
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
if (!is_array($body)) {
    out(['status' => 'error', 'message' => 'Invalid JSON body'], 400);
}

$slot_id = (int)($body['slot_id'] ?? 0);
if ($slot_id <= 0) {
    out(['status' => 'error', 'message' => 'Missing slot_id'], 400);
}

/* ===================== 3) กระบวนการหลัก ===================== */
try {
    // ให้ MySQL ใช้เวลาไทย สำหรับ NOW(), TIMESTAMPDIFF ฯลฯ
    $pdo->exec("SET time_zone = '+07:00'");

    // 3.1 ตรวจสิทธิ์จากการลงทะเบียนรอบสอบ + ดึงข้อมูลเวลารอบสอบ/ระยะเวลา
    $st = $pdo->prepare("
        SELECT
          esr.id              AS registration_id,
          esr.payment_status,
          s.exam_date, s.start_time, s.end_time,
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
    if (!$reg) {
        out(['status' => 'error', 'message' => 'ยังไม่ได้ลงทะเบียนรอบนี้'], 403);
    }

    // 3.2 ต้องชำระเงินแล้ว (หรือ free/waived)
    if (!in_array($reg['payment_status'], ['free', 'paid', 'waived'], true)) {
        out(['status' => 'error', 'message' => 'ยังไม่ชำระเงินหรือรอการตรวจสอบ'], 403);
    }

    // 3.3 คำนวณช่วงเวลาที่อนุญาตจาก slot และ duration
    $examStart = $reg['exam_date'] . ' ' . $reg['start_time'];
    $slotEnd   = $reg['exam_date'] . ' ' . $reg['end_time'];
    $durMin    = (int)($reg['duration_minutes'] ?? 0);

    $calcEndByDuration = $durMin > 0
        ? date('Y-m-d H:i:s', strtotime($examStart . " +{$durMin} minutes"))
        : $slotEnd;

    $allowedEndTs = min(strtotime($slotEnd), strtotime($calcEndByDuration));
    $nowTs        = time();

    // 3.4 ถ้ายังไม่ถึงเวลาเริ่มสอบ
    if ($nowTs < strtotime($examStart)) {
        out([
            'status'        => 'not_started',
            'message'       => 'ยังไม่ถึงเวลาเริ่มสอบ',
            'exam_start_at' => $examStart,
            'starts_in'     => max(0, strtotime($examStart) - $nowTs), // วินาที
        ], 403);
    }

    // 3.5 ถ้าเลยเวลาที่อนุญาตแล้ว → กันก่อนถึง trigger จะได้ไม่กลายเป็น 500
    if ($nowTs >= $allowedEndTs) {
        out([
            'status'         => 'closed',
            'message'        => 'หมดเวลาหรือรอบสอบจบแล้ว',
            'slot_end_at'    => $slotEnd,
            'allowed_end_at' => date('Y-m-d H:i:s', $allowedEndTs),
        ], 403);
    }

    $timeRemaining = max(0, $allowedEndTs - $nowTs);

    // 3.6 ถ้ามี session ที่ยังไม่ปิด ให้ใช้ตัวเดิม
    $st = $pdo->prepare("
        SELECT session_id
        FROM examsession
        WHERE student_id = ? AND slot_id = ? AND end_time IS NULL
        ORDER BY start_time DESC
        LIMIT 1
    ");
    $st->execute([$student_id, $slot_id]);
    if ($existing = $st->fetchColumn()) {
        out([
            'status'            => 'success',
            'session_id'        => (int)$existing,
            'slot_id'           => $slot_id,
            'examset_id'        => (int)($reg['examset_id'] ?? 0),
            'duration_minutes'  => $durMin,
            'exam_start_at'     => $examStart,
            'slot_end_at'       => $slotEnd,
            'allowed_end_at'    => date('Y-m-d H:i:s', $allowedEndTs),
            'time_remaining'    => $timeRemaining,
            'server_time'       => date('Y-m-d H:i:s'),
        ]);
    }

    // 3.7 หา attempt ถัดไป (ต่อคน)
    $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no),0) + 1 FROM examsession WHERE student_id = ?");
    $st->execute([$student_id]);
    $nextAttempt = (int)$st->fetchColumn();
    if ($nextAttempt <= 0) $nextAttempt = 1;

    // 3.8 บันทึก session ใหม่ (trigger ฝั่ง DB จะคำนวณ deadline_at/validate เวลาให้อีกชั้น)
    $st = $pdo->prepare("
        INSERT INTO examsession (student_id, slot_id, start_time, attempt_no)
        VALUES (?, ?, NOW(), ?)
    ");
    $st->execute([$student_id, $slot_id, $nextAttempt]);
    $session_id = (int)$pdo->lastInsertId();

    out([
        'status'            => 'success',
        'session_id'        => $session_id,
        'slot_id'           => $slot_id,
        'examset_id'        => (int)($reg['examset_id'] ?? 0),
        'duration_minutes'  => $durMin,
        'exam_start_at'     => $examStart,
        'slot_end_at'       => $slotEnd,
        'allowed_end_at'    => date('Y-m-d H:i:s', $allowedEndTs),
        'time_remaining'    => $timeRemaining,
        'server_time'       => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    error_log('[start_exam.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
