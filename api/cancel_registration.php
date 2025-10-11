<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/cancel_registration_error.log');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

/* ---------------- helpers ---------------- */
function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}
function daysUntil(string $dateYmd): ?int
{
    if (!$dateYmd) return null;
    $today  = new DateTimeImmutable('today');
    $target = DateTimeImmutable::createFromFormat('Y-m-d', substr($dateYmd, 0, 10)) ?: null;
    if (!$target) return null;
    return (int)$today->diff($target)->format('%r%a');
}

/* ---------------- auth (student only) ---------------- */
$h = array_change_key_case(getallheaders() ?: [], CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
try {
    $claims = (array)decodeToken($m[1]);
} catch (Throwable $e) {
    error_log('decodeToken: ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$studentId = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if (!$studentId || !in_array($role, ['student', 'std', 'learner'], true)) {
    out(['status' => 'error', 'message' => 'Forbidden'], 403);
}

/* ---------------- body ---------------- */
$B = getJsonBody();
$registrationId = isset($B['registration_id']) ? (int)$B['registration_id'] : 0;
$bookingId      = isset($B['booking_id'])      ? (int)$B['booking_id']      : 0;
$reason         = trim((string)($B['reason'] ?? ''));

if ($registrationId <= 0) {
    out(['status' => 'error', 'message' => 'Missing registration_id'], 400);
}

/* ---------------- constants from your schema ----------------
   - registration table: exam_slot_registrations
       pk: id
       student_id: varchar(15)
       slot_id: int
       payment_status enum('free','pending','paid','waived','cancelled')
       payment_ref: varchar(100)
   - booking table: exam_booking
       pk: id
       student_id: varchar(15)
       slot_id: int
       status enum('booked','cancelled')
       cancelled_at, cancel_reason
   - slots: exam_slots
       expect column exam_date (DATE/DATETIME)  (ตามที่ใช้อยู่ก่อนหน้า)
---------------------------------------------------------------- */

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+07:00'");

    /* 1) อ่าน registration */
    $sqlReg = "SELECT id, student_id, slot_id, payment_status, payment_ref
             FROM exam_slot_registrations
             WHERE id = :rid AND student_id = :sid
             LIMIT 1";
    $st = $pdo->prepare($sqlReg);
    $st->execute([':rid' => $registrationId, ':sid' => $studentId]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$reg) out(['status' => 'error', 'message' => 'Registration not found'], 404);

    $slotId = (int)$reg['slot_id'];
    $pstat  = strtolower((string)$reg['payment_status']);

    // ป้องกันการยกเลิกกรณีชำระแล้ว / ยกเลิกไปแล้ว
    if ($pstat === 'paid')       out(['status' => 'error', 'message' => 'ชำระเงินแล้ว ไม่สามารถยกเลิกได้'], 400);
    if ($pstat === 'cancelled')  out(['status' => 'success', 'message' => 'Already cancelled']); // ถือว่าสำเร็จอยู่แล้ว

    // อนุญาตเฉพาะ free/pending/waived
    if (!in_array($pstat, ['free', 'pending', 'waived'], true)) {
        out(['status' => 'error', 'message' => "สถานะปัจจุบัน ({$pstat}) ไม่อนุญาตให้ยกเลิก"], 400);
    }

    /* 2) ตรวจสอบกติกา ≥ 3 วันจากวันสอบ */
    $examDate = null;
    if ($slotId > 0) {
        $q = $pdo->prepare("SELECT exam_date FROM exam_slots WHERE id = :sid LIMIT 1");
        $q->execute([':sid' => $slotId]);
        $examDate = (string)$q->fetchColumn();
        $examDate = $examDate ? substr($examDate, 0, 10) : null;
    }
    if ($examDate) {
        $dleft = daysUntil($examDate);
        if ($dleft !== null && $dleft < 3) {
            out(['status' => 'error', 'message' => "ต้องยกเลิกล่วงหน้าอย่างน้อย 3 วัน (เหลือ {$dleft} วัน)"], 400);
        }
    }

    /* 3) หา booking (ถ้าไม่ได้ส่ง booking_id มา) */
    if ($bookingId <= 0) {
        $qb = $pdo->prepare("
      SELECT id
      FROM exam_booking
      WHERE student_id = :sid AND slot_id = :slot AND status = 'booked'
      ORDER BY id DESC
      LIMIT 1
    ");
        $qb->execute([':sid' => $studentId, ':slot' => $slotId]);
        $bookingId = (int)($qb->fetchColumn() ?: 0);
    }

    /* 4) เริ่มทรานแซกชัน แล้วอัปเดต */
    $pdo->beginTransaction();

    // 4.1 ยกเลิก booking (ถ้ามี)
    if ($bookingId > 0) {
        // ตรวจสอบว่าเป็นของนักศึกษาคนนี้จริง
        $chk = $pdo->prepare("SELECT status FROM exam_booking WHERE id=:id AND student_id=:sid LIMIT 1");
        $chk->execute([':id' => $bookingId, ':sid' => $studentId]);
        $bk = $chk->fetch(PDO::FETCH_ASSOC);

        if ($bk) {
            if (strtolower((string)$bk['status']) !== 'cancelled') {
                $ub = $pdo->prepare("
          UPDATE exam_booking
          SET status = 'cancelled',
              cancelled_at = NOW(),
              cancel_reason = :rsn
          WHERE id = :id AND student_id = :sid
        ");
                $ub->execute([':rsn' => $reason, ':id' => $bookingId, ':sid' => $studentId]);
            }
        }
    }

    // 4.2 อัปเดต registration => payment_status = 'cancelled'
    $ur = $pdo->prepare("
    UPDATE exam_slot_registrations
    SET payment_status = 'cancelled'
    WHERE id = :rid AND student_id = :sid
  ");
    $ur->execute([':rid' => $registrationId, ':sid' => $studentId]);

    $pdo->commit();

    out([
        'status'  => 'success',
        'message' => 'Cancelled',
        'data'    => [
            'registration_id' => $registrationId,
            'booking_id'      => $bookingId ?: null,
            'slot_id'         => $slotId,
            'exam_date'       => $examDate,
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        try {
            $pdo->rollBack();
        } catch (Throwable $e2) {
        }
    }
    error_log('[cancel_registration] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
