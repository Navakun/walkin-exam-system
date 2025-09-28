<?php
// api/get_registration_status.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

// -------- auth (teacher only) --------
$hdrs  = function_exists('getallheaders') ? getallheaders() : [];
$auth  = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
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
if (($claims['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล']);
    exit;
}

try {
    // -------- main query --------
    // ตารางจริงของคุณ:
    // exam_booking: id, student_id, slot_id, status, created_at, updated_at
    // exam_slots   : id, exam_date, start_time, end_time, (examset_id อาจมี/อาจไม่มี)
    // student      : student_id, name
    // examset      : examset_id, title
    // examsession  : มี attempt_no, start_time, end_time ฯลฯ (ไม่พึ่ง examset_id อีกต่อไป)

    $sql = "
    SELECT
      b.id                             AS booking_id,
      s.student_id                     AS student_id,
      s.name                           AS student_name,

      sl.exam_date                     AS exam_date,
      sl.start_time                    AS start_time,
      sl.end_time                      AS end_time,

      COALESCE(es.title,'ยังไม่กำหนดชุดข้อสอบ') AS exam_title,
      b.status                         AS status,
      b.created_at                     AS scheduled_at,

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
    ORDER BY sl.exam_date DESC, sl.start_time DESC, b.id DESC
  ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // shape สำหรับหน้าเว็บ
    $out = array_map(function (array $r) {
        $start = $r['start_time'] ?? '';
        $end   = $r['end_time']   ?? '';
        $examTime = trim($start . (strlen($start) && strlen($end) ? ' - ' : '') . $end);
        $last = (int)($r['last_attempt'] ?? 0);

        return [
            'booking_id'   => (int)$r['booking_id'],
            'student_id'   => $r['student_id'],
            'student_name' => $r['student_name'],
            'exam_date'    => $r['exam_date'],
            'exam_time'    => $examTime,
            'exam_title'   => $r['exam_title'],
            'status'       => $r['status'],
            'scheduled_at' => $r['scheduled_at'] ?? null,
            'has_completed' => (int)($r['has_completed'] ?? 0),
            'last_attempt' => $last,
            'next_attempt' => $last + 1,
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
