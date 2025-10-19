<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

// ====== CONFIG ======
const PROMPTPAY_ID = '0635732448'; // ใส่เบอร์พร้อมเพย์ผู้รับ (ไม่มีขีด/เว้นวรรค)
const QR_EXPIRE_MIN = 15;

// ====== auth (student) ======
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
if (!preg_match('/bearer\s+(\S+)/i', $hdrs['authorization'] ?? '', $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'no token']);
    exit;
}
$claims = decodeToken($m[1]);
$studentId = $claims['student_id'] ?? $claims['sid'] ?? null;
if (!$studentId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'bad token']);
    exit;
}

// ====== read input ======
$raw = file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$regId = (int)($in['registration_id'] ?? 0);
if ($regId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'registration_id required']);
    exit;
}

// ====== find registration ======
$pdo->exec("SET time_zone = '+07:00'");
$stmt = $pdo->prepare("
  SELECT id, student_id, fee_amount, payment_status
  FROM exam_slot_registrations
  WHERE id = ? LIMIT 1
");
$stmt->execute([$regId]);
$reg = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$reg || $reg['student_id'] !== $studentId) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'not your registration']);
    exit;
}
if (!in_array($reg['payment_status'], ['pending', 'free', 'waived'], true) && $reg['payment_status'] !== 'paid') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'invalid payment_status']);
    exit;
}
if ($reg['payment_status'] === 'paid') {
    echo json_encode(['status' => 'success', 'message' => 'already paid']);
    exit;
}

// ====== build ref & amount ======
$amount = (float)$reg['fee_amount'];
if ($amount <= 0 && $reg['payment_status'] === 'free') {
    echo json_encode(['status' => 'success', 'message' => 'free']);
    exit;
}
$ref = 'ESR' . date('ymdHis') . substr(sha1($studentId . $regId . mt_rand()), 0, 6);
$expiresAt = (new DateTime('+' . QR_EXPIRE_MIN . ' minutes'))->format('Y-m-d H:i:s');

/// ====== PromptPay EMV QR builder ======
function crc16($string)
{
    $polynom = 0x1021;
    $result = 0xFFFF;
    $len = strlen($string);
    for ($i = 0; $i < $len; $i++) {
        $result ^= (ord($string[$i]) << 8);
        for ($b = 0; $b < 8; $b++) {
            $result = ($result & 0x8000) ? (($result << 1) ^ $polynom) : ($result << 1);
            $result &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($result), 4, '0', STR_PAD_LEFT));
}

function tlv(string $id, string $value): string
{
    // ใช้ sprintf เพื่อให้ได้สตริงสองหลักเสมอ และไม่โดนเตือน type
    $len = sprintf('%02d', strlen($value));
    return $id . $len . $value;
}

function buildPromptPay(string $ppId, float $amount, string $ref): string
{
    $aid  = tlv('00', 'A000000677010111');            // AID PromptPay
    $proxy = tlv('01', preg_replace('/\D/', '', $ppId)); // เก็บเฉพาะตัวเลข
    $mai  = tlv('29', $aid . $proxy);

    $base =  tlv('00', '01')                          // Payload Format Indicator
        . tlv('01', '11')                           // POI Method (11=dynamic)
        . $mai
        . tlv('52', '0000')                         // MCC (ไม่ระบุ)
        . tlv('53', '764')                          // Currency THB
        . tlv('54', number_format($amount, 2, '.', ''))
        . tlv('58', 'TH')                           // Country
        . tlv('59', 'WalkinExam')                   // Merchant Name (<=25)
        . tlv('60', 'TH');                          // Merchant City

    // Ref (Bill Number) ใน Additional Data Field (62 -> 01)
    $addl = tlv('01', substr($ref, 0, 25));
    $base .= tlv('62', $addl);

    $crcStr = $base . '6304';                        // 63 = CRC, length=04
    $crc = crc16($crcStr);
    return $crcStr . $crc;
}

// ====== insert payment & update registration ======
$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("
    INSERT INTO payments (student_id, registration_id, amount, method, status, paid_at, ref_no, qr_payload, expires_at)
    VALUES (?, ?, ?, 'simulate', 'pending', NULL, ?, ?, ?)
  ");
    $ins->execute([$studentId, $regId, $amount, $ref, $qrPayload, $expiresAt]);

    $upd = $pdo->prepare("UPDATE exam_slot_registrations SET payment_ref = ? WHERE id = ?");
    $upd->execute([$ref, $regId]);

    $pdo->commit();
    echo json_encode([
        'status'      => 'success',
        'payment_id'  => (int)$pdo->lastInsertId(),
        'ref_no'      => $ref,
        'amount'      => number_format($amount, 2, '.', ''),
        'qr_payload'  => $qrPayload,
        'expires_at'  => $expiresAt
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
