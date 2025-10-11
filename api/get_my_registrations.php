<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ---------- Auth ----------
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) && isset($headers['authorization'])) {
    $headers['Authorization'] = $headers['authorization'];
}
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing token'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // $jwt_key มาจาก config/db.php
    $decoded = JWT::decode($m[1], new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? $decoded->sub ?? null;
    if (!$student_id) {
        throw new Exception('Token has no student_id');
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token', 'debug' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // โซนเวลาให้ตรง
    $pdo->exec("SET time_zone = '+07:00'");

    // payments ล่าสุดต่อ registration
    $latestPaymentSql = "
    SELECT p.*
    FROM payments p
    JOIN (
      SELECT registration_id, student_id, MAX(COALESCE(paid_at, created_at)) AS mx
      FROM payments
      GROUP BY registration_id, student_id
    ) lastp
      ON lastp.registration_id = p.registration_id
     AND lastp.student_id      = p.student_id
     AND lastp.mx              = COALESCE(p.paid_at, p.created_at)
  ";

    // booking ปัจจุบัน (สถานะ booked เท่านั้น) และสถานะล่าสุดของ booking (จะใช้ชี้วัด cancelled)
    // - active_booking: ตัวที่ยัง booked (ไว้ยกเลิก)
    // - any_booking  : สถานะล่าสุดอะไรก็ได้ (ไว้ตัดสินใจ effective_status)
    $sql = "
    SELECT 
      r.id                     AS registration_id,
      r.student_id,
      r.slot_id,
      r.attempt_no,
      r.fee_amount,
      r.payment_status         AS reg_payment_status,

      s.exam_date              AS slot_date,
      s.start_time,
      s.end_time,

      ap.payment_id,
      ap.amount                AS payment_amount,
      ap.status                AS payment_status_db,
      ap.method,
      ap.ref_no,
      ap.slip_file,
      ap.paid_at,
      ap.created_at            AS payment_created,

      ab.id                    AS booking_id_active,   -- ใช้ตอนกดยกเลิก
      ab.status                AS booking_status_active,

      lb.id                    AS booking_id_latest,
      lb.status                AS booking_status_latest
    FROM exam_slot_registrations r
    LEFT JOIN exam_slots s
      ON s.id = r.slot_id
    LEFT JOIN ($latestPaymentSql) ap
      ON ap.registration_id = r.id
     AND ap.student_id      = r.student_id
    LEFT JOIN exam_booking ab
      ON ab.student_id = r.student_id
     AND ab.slot_id    = r.slot_id
     AND ab.status     = 'booked'
    LEFT JOIN exam_booking lb
      ON lb.student_id = r.student_id
     AND lb.slot_id    = r.slot_id
     AND lb.updated_at = (
        SELECT MAX(updated_at)
        FROM exam_booking b2
        WHERE b2.student_id = r.student_id AND b2.slot_id = r.slot_id
     )
    WHERE r.student_id = :sid
    ORDER BY s.exam_date DESC, s.start_time DESC, r.id DESC
  ";

    $st = $pdo->prepare($sql);
    $st->execute([':sid' => $student_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    $today = new DateTimeImmutable('today');

    foreach ($rows as $row) {
        // แปลง/กันค่า null
        $slotDate = $row['slot_date'] ?? null;
        $start    = $row['start_time'] ?? null;
        $end      = $row['end_time'] ?? null;

        // 1) ตัดสินสถานะจากหลายแหล่ง -> effective_status
        $regStatus   = strtolower((string)($row['reg_payment_status'] ?? ''));
        $payStatus   = strtolower((string)($row['payment_status_db'] ?? '')); // paid/pending/cancelled/...
        $bookActive  = strtolower((string)($row['booking_status_active'] ?? ''));
        $bookLatest  = strtolower((string)($row['booking_status_latest'] ?? ''));

        // กฎรวมสถานะ
        // - ถ้าจองถูกยกเลิกล่าสุด หรือ registration ถูกตั้ง cancelled => cancelled
        // - ถ้ามีการชำระที่สถานะ = paid => paid
        // - ถ้าไม่ใช่สองเงื่อนไขบน ให้ยึด r.payment_status (free/pending/...)
        $effective = 'unknown';

        if ($bookLatest === 'cancelled' || $regStatus === 'cancelled') {
            $effective = 'cancelled';
        } elseif ($payStatus === 'paid') {
            $effective = 'paid';
        } elseif (in_array($regStatus, ['free', 'pending', 'waived'], true)) {
            $effective = $regStatus;
        } elseif ($payStatus) {
            // มีสถานะจาก payments แต่ไม่ใช่ paid ก็ส่งกลับไปตามนั้น (เช่น pending/failed)
            $effective = $payStatus;
        } else {
            $effective = $regStatus ?: 'unknown';
        }

        // 2) ห่างวันสอบกี่วัน (เทียบแค่วันที่)
        $daysLeft = null;
        if (!empty($slotDate)) {
            try {
                $examDate = new DateTimeImmutable($slotDate);
                $diff     = $today->diff($examDate);
                // ถ้าวันสอบ >= วันนี้ ใช้จำนวนวันข้างหน้า, ถ้าผ่านแล้วเป็นค่าติดลบ
                $daysLeft = (int)$diff->format('%r%a');
            } catch (Throwable $e) {
                $daysLeft = null;
            }
        }

        // 3) เงื่อนไขยกเลิกได้: สถานะยังไม่ paid/ไม่ cancelled และห่างวันสอบ ≥ 3 วัน
        $canCancel = false;
        if (is_int($daysLeft)) {
            if ($daysLeft >= 3 && !in_array($effective, ['paid', 'cancelled'], true)) {
                $canCancel = true;
            }
        }

        $out[] = [
            'registration_id'   => (int)($row['registration_id'] ?? 0),
            'slot_id'           => (int)($row['slot_id'] ?? 0),
            'slot_date'         => $slotDate ?: '',
            'start_time'        => $start ?: '',
            'end_time'          => $end ?: '',
            'attempt_no'        => (int)($row['attempt_no'] ?? 1),
            'fee_amount'        => (float)($row['fee_amount'] ?? 0),

            // สถานะรวมสุดท้าย -> ให้ฝั่งหน้าเว็บเอาไปแสดง/ตัดสินปุ่ม
            'payment_status'    => $effective,

            // ข้อมูล payment ล่าสุด (ถ้ามี)
            'payment_id'        => isset($row['payment_id']) ? (int)$row['payment_id'] : null,
            'payment_amount'    => isset($row['payment_amount']) ? (float)$row['payment_amount'] : null,
            'method'            => $row['method'] ?? null,
            'ref_no'            => $row['ref_no'] ?? null,
            'slip_file'         => $row['slip_file'] ?? null,
            'paid_at'           => $row['paid_at'] ?? null,
            'payment_created'   => $row['payment_created'] ?? null,

            // booking ปัจจุบัน (ใช้ยิง cancel_booking.php) + สถานะล่าสุดของ booking
            'booking_id'        => isset($row['booking_id_active']) ? (int)$row['booking_id_active'] : null,
            'booking_status'    => $bookActive ?: null,
            'booking_status_latest' => $bookLatest ?: null,

            // ช่วยการเรนเดอร์
            'days_until_exam'   => $daysLeft,
            'can_cancel'        => $canCancel,
        ];
    }

    echo json_encode(['status' => 'success', 'registrations' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SERVER_ERROR', 'debug' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
