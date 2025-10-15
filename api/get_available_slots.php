<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

date_default_timezone_set('Asia/Bangkok');
ini_set('display_errors', '0');
error_reporting(E_ALL);

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

require_once __DIR__ . '/../config/db.php';   // ต้องนิยาม $pdo = new PDO(...)

/* ถ้าต้องการเปิดสาธารณะ ให้นศ.เรียกได้ ไม่ต้องตรวจ JWT */
$requireJwt = false;
if ($requireJwt) {
  require_once __DIR__ . '/../api/helpers/jwt_helper.php';
  $h = function_exists('getallheaders') ? getallheaders() : [];
  if (!isset($h['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $h['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'NO_TOKEN']);
    exit;
  }
  if (!decodeToken($m[1] ?? '')) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'BAD_TOKEN']);
    exit;
  }
}

try {
  $pdo->exec("SET time_zone = '+07:00'");

  // สถานะที่ “กินเก้าอี้จริง” ปรับได้ตามนโยบาย
  $ACTIVE = ['free', 'pending', 'paid', 'waived']; // ถ้าจะนับ booked เพิ่ม 'booked'
  $ph = implode(',', array_fill(0, count($ACTIVE), '?'));

  /*
    สร้าง start_at / end_at คำนวณจากคอลัมน์ที่มีอยู่:
      start_at = CAST(CONCAT(exam_date,' ',start_time) AS DATETIME)
      end_at   = ถ้า end_time <= start_time แปลว่าข้ามเที่ยงคืน => +1 วัน
  */
  $sql = "
    SELECT
      t.slot_id,
      t.exam_date, t.start_time, t.end_time,
      t.start_at, t.end_at,
      t.max_seats, t.reg_open_at, t.reg_close_at,
      COALESCE(b.booked_count,0) AS booked,
      GREATEST(t.max_seats - COALESCE(b.booked_count,0), 0) AS seats_left
    FROM (
      SELECT
        es.id AS slot_id,
        es.exam_date, es.start_time, es.end_time,
        CAST(CONCAT(es.exam_date,' ',es.start_time) AS DATETIME) AS start_at,
        CASE
          WHEN es.end_time <= es.start_time
            THEN DATE_ADD(CAST(CONCAT(es.exam_date,' ',es.end_time) AS DATETIME), INTERVAL 1 DAY)
          ELSE CAST(CONCAT(es.exam_date,' ',es.end_time) AS DATETIME)
        END AS end_at,
        es.max_seats,
        es.reg_open_at, es.reg_close_at
      FROM exam_slots es
    ) t
    LEFT JOIN (
      SELECT slot_id, COUNT(*) AS booked_count
      FROM exam_slot_registrations
      WHERE payment_status IN ($ph)
      GROUP BY slot_id
    ) b ON b.slot_id = t.slot_id
    WHERE
      t.end_at > NOW()
      AND (t.reg_open_at IS NULL OR NOW() >= t.reg_open_at)
      AND (t.reg_close_at IS NULL OR NOW() <= t.reg_close_at)
    HAVING seats_left > 0
    ORDER BY t.start_at ASC
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
