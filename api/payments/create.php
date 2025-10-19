<?php

declare(strict_types=1);

// api/payments/create.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../../config/db.php';            // <-- ขึ้น 2 ชั้น
require_once __DIR__ . '/../helpers/jwt_helper.php';      // helpers อยู่ชั้นบน api/

function json_error(int $code, string $msg, array $extra = []): never
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== Auth (นิสิต) =====
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth = $hdrs['authorization'] ?? '';
if (!preg_match('/bearer\s+(\S+)/i', $auth, $m)) json_error(401, 'ไม่ได้ส่ง Token');
$claims = decodeToken($m[1]);
if (!$claims) json_error(401, 'Token ไม่ถูกต้องหรือหมดอายุ');
$studentId = (string)($claims['student_id'] ?? $claims['sid'] ?? '');
if ($studentId === '') json_error(403, 'เฉพาะนิสิตเท่านั้น');

// ===== Read JSON =====
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) json_error(400, 'รูปแบบข้อมูลไม่ถูกต้อง');

$registrationId = (int)($body['registration_id'] ?? 0);
if ($registrationId <= 0) json_error(422, 'registration_id ไม่ถูกต้อง');

// ===== Load registration & slot =====
$sql = "
SELECT r.id AS registration_id, r.student_id, r.slot_id, r.fee_amount, r.payment_status, r.payment_ref,
       sl.exam_date, sl.start_time, sl.end_time
FROM exam_slot_registrations r
JOIN exam_slots sl ON sl.id = r.slot_id
WHERE r.id = :rid
LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':rid' => $registrationId]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reg) json_error(404, 'ไม่พบข้อมูลการลงทะเบียน');
if ((string)$reg['student_id'] !== $studentId) json_error(403, 'ไม่สามารถชำระแทนผู้อื่นได้');

$payStatus = strtolower((string)$reg['payment_status']);
if (in_array($payStatus, ['paid', 'free', 'waived'], true)) {
    json_error(409, 'รายการนี้ชำระเงินหรืออยู่ในสถานะยกเว้นแล้ว');
}

// ===== Begin Tx =====
$pdo->beginTransaction();
try {
    // อัปเดตสถานะเป็น pending ถ้ายังไม่ใช่
    if ($payStatus !== 'pending') {
        $u = $pdo->prepare("UPDATE exam_slot_registrations SET payment_status='pending' WHERE id=:rid");
        $u->execute([':rid' => $registrationId]);
    }

    // 1) ลองหา payment เดิมที่ pending ของ registration นี้ก่อน (ทำให้ idempotent)
    $sel = $pdo->prepare("SELECT payment_id, ref_no, amount, status 
                          FROM payments 
                          WHERE registration_id=:rid AND status='pending'
                          ORDER BY payment_id DESC LIMIT 1");
    $sel->execute([':rid' => $registrationId]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // มีอยู่แล้ว -> ใช้ตัวเดิม
        $paymentId = (int)$existing['payment_id'];
        $refNo     = (string)$existing['ref_no'];
        $amountVal = (float)$existing['amount'];
    } else {
        // 2) ยังไม่มี -> สร้างใหม่ พร้อมกันชน unique_ref_no
        $amountVal = (float)$reg['fee_amount'];

        // base ref (ไม่ต้อง unique แน่ๆในรอบแรก เราจะวนจนกว่าจะสำเร็จ)
        $base = 'REG' . date('ymd') . str_pad((string)$registrationId, 6, '0', STR_PAD_LEFT);

        $ins = $pdo->prepare("
            INSERT INTO payments (student_id, registration_id, amount, method, status, ref_no, created_at)
            VALUES (:sid, :rid, :amt, 'simulate', 'pending', :ref, NOW())
        ");

        $attempts = 0;
        do {
            $attempts++;
            // เพิ่มสุ่มสั้นๆ กันชน (ครั้งแรกไม่มี suffix)
            $refNoTry = $attempts === 1 ? $base : ($base . substr(strval(mt_rand(100, 999)), -3));
            try {
                $ins->execute([
                    ':sid' => $studentId,
                    ':rid' => $registrationId,
                    ':amt' => $amountVal,
                    ':ref' => $refNoTry,
                ]);
                $refNo = $refNoTry;
                $paymentId = (int)$pdo->lastInsertId();
                break; // สำเร็จเลิกวน
            } catch (PDOException $e) {
                // 1062 = duplicate entry -> วนใหม่
                if ($e->getCode() !== '23000' || stripos($e->getMessage(), 'duplicate') === false) {
                    throw $e; // ไม่ใช่ชน unique -> โยนต่อ
                }
                if ($attempts >= 5) { // กันวนยาวเกินไป
                    throw new RuntimeException('ไม่สามารถสร้างเลขอ้างอิงที่ไม่ซ้ำได้ ลองใหม่อีกครั้ง');
                }
            }
        } while (true);

        // ผูก ref เข้า registration หากยังว่าง
        $upd = $pdo->prepare("UPDATE exam_slot_registrations 
                              SET payment_ref=:ref 
                              WHERE id=:rid AND (payment_ref IS NULL OR payment_ref='')");
        $upd->execute([':ref' => $refNo, ':rid' => $registrationId]);
    }

    $pdo->commit();

    // ===== สร้าง QR =====
    $promptpayId = '0105546111234'; // TODO: ใส่ PromptPay จริง
    $amount = number_format($amountVal, 2, '.', '');
    $payload = "PROMPTPAY|A={$promptpayId}|AMT={$amount}|REF={$refNo}";
    $qrUrl   = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($payload);

    echo json_encode([
        'status'  => 'success',
        'payment_id' => $paymentId,
        'ref_no'  => $refNo,
        'amount'  => (float)$amountVal,
        'registration' => [
            'id' => (int)$reg['registration_id'],
            'slot_date'  => $reg['exam_date'],
            'start_time' => $reg['start_time'],
            'end_time'   => $reg['end_time'],
        ],
        'qr' => [
            'payload'   => $payload,
            'image_url' => $qrUrl,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    json_error(500, 'เริ่มการชำระเงินล้มเหลว: ' . $e->getMessage());
}
