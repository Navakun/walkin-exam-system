<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php'; // ถ้ามีอยู่แล้วในโปรเจกต์

function out($o, int $code = 200)
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===== Auth (Bearer) ===== */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบใหม่อีกครั้ง'], 401);
}
$claims = decodeToken($m[1]);
if (!$claims) out(['status' => 'error', 'message' => 'โทเคนไม่ถูกต้อง'], 401);
$student_id = (string)($claims->student_id ?? $claims->sub ?? '');
if ($student_id === '') out(['status' => 'error', 'message' => 'โทเคนไม่ระบุรหัสนิสิต'], 401);

/* ===== Body ===== */
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$booking_id = (int)($body['booking_id'] ?? 0);
$reason     = trim((string)($body['reason'] ?? ''));

if ($booking_id <= 0) out(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วน'], 400);

try {
    $pdo = pdo();
    $pdo->exec("SET time_zone = '+07:00'");

    // 1) ดึง booking + วันสอบ (exam_date) และตรวจสิทธิ์ + สถานะ
    $sql = "
    SELECT b.id, b.student_id, b.slot_id, b.status,
           s.exam_date
    FROM exam_booking b
    JOIN exam_slots s ON s.id = b.slot_id
    WHERE b.id = :bid
    LIMIT 1
  ";
    $st = $pdo->prepare($sql);
    $st->execute([':bid' => $booking_id]);
    $bk = $st->fetch(PDO::FETCH_ASSOC);

    if (!$bk) out(['status' => 'error', 'message' => 'ไม่พบข้อมูลการจอง'], 404);
    if ($bk['student_id'] !== $student_id) out(['status' => 'error', 'message' => 'ไม่มีสิทธิ์ยกเลิกการจองนี้'], 403);
    if ($bk['status'] !== 'booked') out(['status' => 'error', 'message' => 'การจองนี้ถูกยกเลิกแล้วหรือไม่อยู่ในสถานะยกเลิกได้'], 400);

    // 2) เช็คเงื่อนไข ≥ 3 วันก่อนวันสอบ
    $today = new DateTime('today');                      // 00:00 ของวันนี้
    $exam  = new DateTime($bk['exam_date'] . ' 00:00:00'); // 00:00 ของวันสอบ
    $diffDays = (int)$today->diff($exam)->format('%r%a'); // อาจเป็นลบถ้าเลยวันสอบไปแล้ว
    if ($diffDays < 3) {
        out(['status' => 'error', 'message' => 'ต้องยกเลิกล่วงหน้าอย่างน้อย 3 วันก่อนวันสอบ'], 400);
    }

    // 3) ยกเลิก (txn กันแข่งกันกด)
    $pdo->beginTransaction();

    $u = $pdo->prepare("
    UPDATE exam_booking
    SET status='cancelled',
        cancelled_at = NOW(),
        cancel_reason = :rs
    WHERE id = :bid AND student_id = :sid AND status='booked'
  ");
    $u->execute([
        ':rs'  => ($reason !== '' ? $reason : null),
        ':bid' => $booking_id,
        ':sid' => $student_id
    ]);

    if ($u->rowCount() === 0) {
        $pdo->rollBack();
        out(['status' => 'error', 'message' => 'ยกเลิกไม่สำเร็จ (สถานะถูกเปลี่ยนก่อนหน้า?)'], 409);
    }

    $pdo->commit();
    out(['status' => 'success', 'message' => 'ยกเลิกการจองสำเร็จ']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
