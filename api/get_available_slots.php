<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* -------- ทำให้ fatal/error ตอบเป็น JSON -------- */
set_error_handler(function ($sev, $msg, $file, $line) {
  throw new ErrorException($msg, 0, $sev, $file, $line);
});
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'HTTP_500', 'debug' => $e['message'] . ' @' . $e['file'] . ':' . $e['line']]);
  }
});

/* -------- includes -------- */
require_once __DIR__ . '/../config/db.php';          // ให้ได้ $pdo (PDO instance)
/* ถ้าต้องการบังคับให้นศ.มี token ให้เปิดใช้บล็อคด้านล่าง */
$requireJwt = false; // <-- เปลี่ยนเป็น true ถ้าจะตรวจ JWT
if ($requireJwt) {
  require_once __DIR__ . '/../api/helpers/jwt_helper.php';
  $headers = function_exists('getallheaders') ? getallheaders() : [];
  if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token', 'code' => 'NO_TOKEN']);
    exit;
  }
  $token = $m[1];
  $decoded = decodeToken($token);
  if (!$decoded) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Bad token', 'code' => 'BAD_TOKEN']);
    exit;
  }
}

try {
  /* ให้ MySQL ใช้ +07:00 */
  $pdo->exec("SET time_zone = '+07:00'");

  /* สถานะที่ “กินเก้าอี้จริง” ปรับได้ตามนโยบาย */
  $ACTIVE = ['free', 'pending', 'paid', 'waived'];  // ถ้าจะนับ booked ให้เติม 'booked'
  $ph = implode(',', array_fill(0, count($ACTIVE), '?'));

  /* ใช้ซับคิวรีนับคนจองกันการนับซ้ำจาก LEFT JOIN */
  $sql = "
    SELECT
      es.id            AS slot_id,
      es.exam_date, es.start_time, es.end_time,
      es.start_at, es.end_at,
      es.max_seats,
      es.reg_open_at, es.reg_close_at,
      COALESCE(b.booked_count,0) AS booked,
      GREATEST(es.max_seats - COALESCE(b.booked_count,0), 0) AS seats_left
    FROM exam_slots es
    LEFT JOIN (
      SELECT slot_id, COUNT(*) AS booked_count
      FROM exam_slot_registrations
      WHERE payment_status IN ($ph)
      GROUP BY slot_id
    ) b ON b.slot_id = es.id
    WHERE
      es.end_at > NOW()                                -- สล็อตยังไม่หมด
      AND (es.reg_open_at IS NULL OR NOW() >= es.reg_open_at)
      AND (es.reg_close_at IS NULL OR NOW() <= es.reg_close_at)
    HAVING seats_left > 0
    ORDER BY es.start_at ASC
  ";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($ACTIVE);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode([
    'status' => 'success',
    'now'    => date('Y-m-d H:i:s'),
    'slots'  => $rows
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status' => 'error', 'message' => 'SERVER_ERROR', 'debug' => $e->getMessage()]);
}
