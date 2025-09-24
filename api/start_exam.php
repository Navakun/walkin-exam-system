<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php'; // ต้องมีฟังก์ชัน decodeToken($jwt)

/** ส่งออก JSON + status code */
function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- อ่านและตรวจ JWT ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
$decoded = decodeToken($m[1]);
if (!$decoded || (($decoded->role ?? '') !== 'student')) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 403);
}
$student_id = (string)($decoded->student_id ?? '');
if ($student_id === '') out(['status' => 'error', 'message' => 'Missing student_id'], 403);

/* ---------- รับ slot_id จาก body ---------- */
$body    = json_decode(file_get_contents('php://input'), true);
$slot_id = (int)($body['slot_id'] ?? 0);
if ($slot_id <= 0) out(['status' => 'error', 'message' => 'Missing slot_id'], 400);

/* ---------- กระบวนการหลัก ---------- */
try {
    // ให้ MySQL ใช้เวลาไทย (สำหรับคำนวณ NOW() ฯลฯ)
    $pdo->exec("SET time_zone = '+07:00'");

    // 1) ตรวจว่าลงทะเบียน slot นี้จริง + ดึงข้อมูลเวลารอบสอบและ duration ของชุดข้อสอบ
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
    if (!$reg) out(['status' => 'error', 'message' => 'ยังไม่ได้ลงทะเบียนรอบนี้'], 403);

    // 2) ต้องชำระเงินแล้ว (หรือ free/waived)
    if (!in_array($reg['payment_status'], ['free', 'paid', 'waived'], true)) {
        out(['status' => 'error', 'message' => 'ยังไม่ชำระเงินหรือรอการตรวจสอบ'], 403);
    }

    // 3) คำนวณเวลาที่อนุญาต (ฝั่งเซิร์ฟเวอร์)
    $examStart = $reg['exam_date'] . ' ' . $reg['start_time']; // DATETIME string
    $slotEnd   = $reg['exam_date'] . ' ' . $reg['end_time'];
    $durMin    = (int)($reg['duration_minutes'] ?? 0);

    $calcByDurationEnd = $durMin > 0
        ? date('Y-m-d H:i:s', strtotime($examStart . " +{$durMin} minutes"))
        : $slotEnd;
    $allowedEndTs = min(strtotime($slotEnd), strtotime($calcByDurationEnd));
    $nowTs        = time();

    // ถ้ายังไม่ถึงเวลาเริ่มสอบ ให้บอกเวลาที่เหลือก่อนเริ่ม
    if ($nowTs < strtotime($examStart)) {
        out([
            'status'          => 'not_started',
            'message'         => 'ยังไม่ถึงเวลาเริ่มสอบ',
            'exam_start_at'   => $examStart,
            'starts_in'       => max(0, strtotime($examStart) - $nowTs), // วินาทีก่อนเริ่ม
        ], 403);
    }

    $timeRemaining = max(0, $allowedEndTs - $nowTs);

    // 4) ถ้ามี session ที่ยังไม่ปิดอยู่ ให้ใช้ตัวเดิม
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
            'time_remaining'    => $timeRemaining
        ]);
    }

    // 5) สร้าง attempt ถัดไป
    $st = $pdo->prepare("SELECT COALESCE(MAX(attempt_no),0) + 1 FROM examsession WHERE student_id = ?");
    $st->execute([$student_id]);
    $nextAttempt = (int)$st->fetchColumn();
    if ($nextAttempt <= 0) $nextAttempt = 1;

    // 6) บันทึก session ใหม่ (version ใช้ slot_id)
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
        'time_remaining'    => $timeRemaining
    ]);
} catch (Throwable $e) {
    error_log('[start_exam.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
