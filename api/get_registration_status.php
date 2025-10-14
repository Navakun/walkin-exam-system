<?php
// api/get_registration_status.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

/* ========== auth (teacher only, ยืดหยุ่นเคลม + header case) ========== */
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth = $hdrs['authorization'] ?? '';
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
    // ให้ DB ใช้เขตเวลาไทย (ถ้าฐานข้อมูลตั้งเป็น UTC)
    $pdo->exec("SET time_zone = '+07:00'");

    /*
     * ตารางที่ใช้อยู่:
     * - exam_booking   : id, student_id, slot_id, status, created_at, updated_at
     * - exam_slots     : id, exam_date, start_time, end_time, (start_at, end_at ถ้ามี), examset_id
     * - student        : student_id, name
     * - examset        : examset_id, title
     * - examsession    : attempt_no, start_time, end_time, student_id
     *
     * หมายเหตุ: ถ้ามีคอลัมน์ start_at/end_at แล้วจะใช้คอลัมน์นั้นเป็นหลัก (Fallback กลับไป date+time ถ้ายังไม่มี)
     */

    $sql = "
      SELECT
        b.id                              AS booking_id,
        s.student_id                      AS student_id,
        s.name                            AS student_name,

        sl.exam_date,
        sl.start_time,
        sl.end_time,
        /* ถ้ามี start_at/end_at ให้ดึงออกมาเลย */
        sl.start_at,
        sl.end_at,

        COALESCE(es.title,'ยังไม่กำหนดชุดข้อสอบ') AS exam_title,
        b.status                          AS status,
        b.created_at                      AS scheduled_at,

        /* เคยสอบ ‘จบ’ แล้วไหม (ดูจาก end_time) — ไม่อิง examset_id */
        (
          SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END
          FROM examsession x
          WHERE x.student_id = s.student_id
            AND x.end_time IS NOT NULL
        ) AS has_completed,

        /* ครั้งล่าสุดที่เคยสอบ — ไม่อิง examset_id */
        COALESCE((
          SELECT MAX(x2.attempt_no)
          FROM examsession x2
          WHERE x2.student_id = s.student_id
        ), 0) AS last_attempt
      FROM exam_booking b
      JOIN student     s  ON s.student_id = b.student_id
      JOIN exam_slots  sl ON sl.id        = b.slot_id
      LEFT JOIN examset es ON es.examset_id = sl.examset_id
      /* เรียงโดยใช้ start_at ถ้ามี ไม่งั้น fallback */
      ORDER BY
        COALESCE(sl.start_at, TIMESTAMP(sl.exam_date, sl.start_time)) DESC,
        b.id DESC
    ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // แปลงให้ง่ายต่อการแสดงผล
    $nowTs = time();
    $out = array_map(function (array $r) use ($nowTs) {
        // สร้าง start/end ที่เป็น datetime (เผื่อบางแถวยังไม่มี start_at/end_at)
        $startAt = $r['start_at'] ?? null;
        $endAt   = $r['end_at']   ?? null;

        if (!$startAt) {
            $startAt = trim(($r['exam_date'] ?? '') . ' ' . ($r['start_time'] ?? ''));
        }
        if (!$endAt) {
            $endAt = trim(($r['exam_date'] ?? '') . ' ' . ($r['end_time'] ?? ''));
        }

        // exam_time แบบสั้น ๆ สำหรับแสดง
        $examTimeText = '';
        if (!empty($r['start_time']) || !empty($r['end_time'])) {
            $st = ($r['start_time'] ?? '');
            $et = ($r['end_time'] ?? '');
            $examTimeText = trim($st . (strlen($st) && strlen($et) ? ' - ' : '') . $et);
        }

        $startTs = strtotime($startAt) ?: null;
        $isFuture = $startTs ? ($startTs > $nowTs) : null;
        $daysUntil = $startTs ? (int)floor(($startTs - $nowTs) / 86400) : null;

        $last = (int)($r['last_attempt'] ?? 0);

        return [
            'booking_id'     => (int)$r['booking_id'],
            'student_id'     => $r['student_id'],
            'student_name'   => $r['student_name'],
            'exam_date'      => $r['exam_date'],
            'exam_time'      => $examTimeText,   // HH:MM:SS - HH:MM:SS
            'start_at'       => $startAt ?: null,
            'end_at'         => $endAt   ?: null,
            'exam_title'     => $r['exam_title'],
            'status'         => $r['status'],
            'scheduled_at'   => $r['scheduled_at'] ?? null,
            'has_completed'  => (int)($r['has_completed'] ?? 0),
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
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูล: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
