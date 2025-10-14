<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once __DIR__ . '/../config/db.php'; // ระวังพาธ ให้ชี้ถูกไฟล์

// ---------- ตรวจ JWT ----------
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided', 'error_code' => 'NO_TOKEN']);
    exit;
}
$token = $m[1] ?? '';
$decoded = decodeToken($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized', 'error_code' => 'BAD_TOKEN']);
    exit;
}

try {
    // ให้เวลาอยู่ที่ +07:00 (ถ้าฐานข้อมูลตั้งเป็น UTC)
    pdo()->exec("SET time_zone = '+07:00'");

    /*
   * หลักการ:
   * - เลือก slot ที่ยังไม่หมดเวลา (end_at > NOW())
   * - เปิดลงทะเบียน “ตอนนี้” (ถ้า reg_open/close เป็น NULL ให้ถือว่าเปิดเสมอ)
   * - นับที่นั่งเฉพาะสถานะที่ “กินเก้าอี้จริง”: paid, free, waived, booked
   *   (ถ้าองค์กรคุณต้องการนับ pending ว่าจองที่นั่งไว้ด้วย ให้เติม 'pending' ลง IN() ได้)
   */
    $sql = "
    SELECT
      es.id               AS slot_id,
      es.start_at,
      es.end_at,
      es.max_seats,
      es.reg_open_at,
      es.reg_close_at,
      CAST(
        COALESCE(SUM(
          CASE
            WHEN r.payment_status IN ('paid','free','waived','booked') THEN 1
            ELSE 0
          END
        ), 0) AS UNSIGNED
      ) AS booked,
      CAST(
        GREATEST(0, es.max_seats - COALESCE(SUM(
          CASE
            WHEN r.payment_status IN ('paid','free','waived','booked') THEN 1
            ELSE 0
          END
        ), 0)) AS UNSIGNED
      ) AS seats_left
    FROM exam_slots es
    LEFT JOIN exam_slot_registrations r
      ON r.slot_id = es.id
    WHERE
      es.end_at > NOW()
      AND (es.reg_open_at IS NULL OR NOW() >= es.reg_open_at)
      AND (es.reg_close_at IS NULL OR NOW() <= es.reg_close_at)
    GROUP BY es.id
    HAVING seats_left > 0
    ORDER BY es.start_at ASC
  ";

    $rows = pdo()->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'now'    => date('Y-m-d H:i:s'),
        'slots'  => $rows, // มี start_at / end_at ให้ฝั่งหน้าเว็บฟอร์แมทเอง
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error'], JSON_UNESCAPED_UNICODE);
}
