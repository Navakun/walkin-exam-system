<?php
// api/get_registration_status.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

/* ===== Auth (teacher only) ===== */
$hdrs  = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth  = $hdrs['authorization'] ?? '';
if (!preg_match('/bearer\s+(\S+)/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}
$claims = decodeToken($m[1]);
if (!$claims) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ']);
    exit;
}
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
if (!in_array($role, ['teacher', 'instructor'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล']);
    exit;
}

try {
    // ให้เวลาเป็นโซนไทย (ถ้า DB เก็บ UTC)
    $pdo->exec("SET time_zone = '+07:00'");

    // ดึงจากตารางลงทะเบียนหลัก + slot + student (+ examset ถ้ามี)
    $sql = "
        SELECT
            r.id                AS reg_id,
            r.student_id,
            s.name              AS student_name,
            r.slot_id,
            r.registered_at,
            r.created_at,
            r.attempt_no,
            r.fee_amount,
            r.payment_status,
            r.payment_ref,

            sl.exam_date,
            sl.start_time,
            sl.end_time,
            TIMESTAMP(sl.exam_date, sl.start_time) AS v_start_at,
            TIMESTAMP(sl.exam_date, sl.end_time)   AS v_end_at,

            COALESCE(es.title,'ยังไม่กำหนดชุดข้อสอบ') AS exam_title,

            /* เคยสอบจบของ ‘รายการนี้’ ไหม: คนเดียวกัน + end_time มี + เวลา session อยู่ในช่วงสอบของ slot */
            (
              SELECT CASE WHEN COUNT(*)>0 THEN 1 ELSE 0 END
              FROM examsession x
              WHERE x.student_id = r.student_id
                AND x.end_time IS NOT NULL
                AND x.start_time BETWEEN TIMESTAMP(sl.exam_date, sl.start_time)
                                     AND     TIMESTAMP(sl.exam_date, sl.end_time)
            ) AS has_completed_this,

            /* ครั้งล่าสุดที่เคยสอบ (ทั้งหมดของนิสิต) */
            COALESCE((
              SELECT MAX(x2.attempt_no)
              FROM examsession x2
              WHERE x2.student_id = r.student_id
            ), 0) AS last_attempt

        FROM exam_slot_registrations r
        JOIN student    s  ON s.student_id = r.student_id
        JOIN exam_slots sl ON sl.id        = r.slot_id
        LEFT JOIN examset es ON es.examset_id = sl.examset_id
        ORDER BY TIMESTAMP(sl.exam_date, sl.start_time) DESC, r.id DESC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $nowTs = time();
    $out = array_map(function (array $r) use ($nowTs) {
        $startAt = $r['v_start_at'] ?? null;
        $endAt   = $r['v_end_at']   ?? null;

        $st = trim((string)($r['start_time'] ?? ''));
        $et = trim((string)($r['end_time'] ?? ''));
        $examTimeText = $st . ((strlen($st) && strlen($et)) ? ' - ' : '') . $et;

        $startTs   = $startAt ? strtotime($startAt) : null;
        $isFuture  = $startTs ? ($startTs > $nowTs) : null;
        $daysUntil = $startTs ? (int) floor(($startTs - $nowTs) / 86400) : null;

        $last = (int)($r['last_attempt'] ?? 0);

        return [
            'booking_id'     => (int)$r['reg_id'],     // คีย์เดิมของ UI แต่คือ id ของ registration
            'student_id'     => $r['student_id'],
            'student_name'   => $r['student_name'],
            'exam_date'      => $r['exam_date'],
            'exam_time'      => $examTimeText,
            'start_at'       => $startAt ?: null,
            'end_at'         => $endAt   ?: null,
            'exam_title'     => $r['exam_title'],
            'status'         => $r['payment_status'],  // ใช้สถานะชำระเงินเป็นตัวแทน
            'scheduled_at'   => $r['registered_at'] ?: $r['created_at'] ?: null,
            'has_completed'  => (int)($r['has_completed_this'] ?? 0), // ใช้ของ “รายการนี้” เท่านั้น
            'last_attempt'   => $last,
            'next_attempt'   => $last + 1,
            'is_future'      => $isFuture,
            'days_until'     => $daysUntil,
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'registrations' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
