<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once __DIR__ . '/../config/db.php';

/* -------- auth: header แบบ case-insensitive -------- */
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = '';
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') {
        $authHeader = $v;
        break;
    }
}
if (!$authHeader || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token or bad format', 'error_code' => 'NO_OR_BAD_TOKEN']);
    exit;
}

$token = $m[1];
$decoded = decodeToken($token); // หรือ verifyJwtToken() ถ้าโปรเจ็กต์ใช้ตัวนั้น
if (!$decoded || ($decoded['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden', 'error_code' => 'NOT_TEACHER']);
    exit;
}

/* -------- main -------- */
try {
    // สถานะที่ถือว่า "นับเป็นการจองที่กินที่นั่ง"
    $ACTIVE_PAY_STATUSES = ['free', 'pending', 'paid', 'waived'];
    $ph = implode(',', array_fill(0, count($ACTIVE_PAY_STATUSES), '?'));

    $sql = "
    SELECT
      es.id,
      es.exam_date,
      es.start_time,
      es.end_time,
      es.start_at,
      es.end_at,
      es.max_seats,
      es.reg_open_at,
      es.reg_close_at,
      es.created_by,
      e.title AS examset_title,
      COALESCE(b.booked_count, 0) AS booked_count,
      GREATEST(es.max_seats - COALESCE(b.booked_count, 0), 0) AS seats_left,
      /* สะดวกให้ UI ตัดสินใจ */
      CASE WHEN COALESCE(b.booked_count,0) >= es.max_seats THEN 1 ELSE 0 END AS is_full,
      CASE
        WHEN es.reg_open_at IS NULL OR es.reg_close_at IS NULL THEN 1
        WHEN NOW() BETWEEN es.reg_open_at AND es.reg_close_at THEN 1
        ELSE 0
      END AS is_reg_open
    FROM exam_slots es
    LEFT JOIN examset e ON e.examset_id = es.examset_id
    LEFT JOIN (
      SELECT slot_id, COUNT(*) AS booked_count
      FROM exam_slot_registrations
      WHERE payment_status IN ($ph)
      GROUP BY slot_id
    ) b ON b.slot_id = es.id
    ORDER BY es.start_at ASC, es.exam_date ASC, es.start_time ASC
  ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($ACTIVE_PAY_STATUSES);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'slots' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'SERVER_ERROR',
        'error_code' => 'QUERY_FAILED',
        'debug' => $e->getMessage()
    ]);
}
