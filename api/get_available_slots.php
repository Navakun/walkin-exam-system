<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// ❗ อย่าพ่น error ออกหน้าจอ (เพื่อไม่ให้ปน JSON)
error_reporting(E_ALL);
ini_set('display_errors', '0');
require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once '../config/db.php';

// ==================
// 🔹 ตรวจสอบ JWT
// ==================
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'No token provided',
        'error_code' => 'NO_TOKEN'
    ]);
    exit;
}

$token = $matches[1];
$decoded = decodeToken($token);
if (!$decoded) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized',
        'error_code' => 'BAD_TOKEN'
    ]);
    exit;
}

try {
    // ------------------------------------------------------------------
    // เลือกเฉพาะ slot ที่ "เปิดลงทะเบียนอยู่ตอนนี้" และ "ยังไม่เต็ม"
    // ปรับชื่อฟิลด์ให้ส่งเป็น slot_id (มาจาก es.id)
    // นับที่นั่งเฉพาะ payment_status ที่กินเก้าอี้จริง
    // ------------------------------------------------------------------
    $sql = "
    SELECT
      es.id AS slot_id,
      es.exam_date,
      es.start_time,
      es.end_time,
      es.max_seats,
      es.reg_open_at,
      es.reg_close_at,
      CAST(COALESCE(SUM(CASE
          WHEN r.payment_status IN ('booked','paid') THEN 1
          ELSE 0
      END),0) AS UNSIGNED) AS booked,
      CAST((es.max_seats - COALESCE(SUM(CASE
          WHEN r.payment_status IN ('booked','paid') THEN 1
          ELSE 0
      END),0)) AS UNSIGNED) AS seats_left
    FROM exam_slots es
    LEFT JOIN exam_slot_registrations r
      ON r.slot_id = es.id
    WHERE NOW() BETWEEN es.reg_open_at AND es.reg_close_at
    GROUP BY es.id
    HAVING seats_left > 0
    ORDER BY es.exam_date, es.start_time
    ";

    $stmt  = pdo()->query($sql);
    $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ส่ง JSON เดียวเท่านั้น ห้ามมี echo อย่างอื่นก่อน/หลัง
    echo json_encode([
        'status' => 'success',
        'now'    => date('Y-m-d H:i:s'),
        'slots'  => $slots,          // จะเป็น array ว่าง [] ถ้าไม่มี
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
