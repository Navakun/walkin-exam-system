<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/jwt_helper.php'; // ถ้ามีไว้ถอด token; ไม่มีก็ใช้ firebase-jwt แทน

// ---------- helper: write server log ----------
function jerr($msg, $ctx = [])
{
    error_log('[payments/create] ' . $msg . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE)));
}

// ---------- auth: student ----------
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth = $hdrs['authorization'] ?? '';
if (!preg_match('/bearer\s+(\S+)/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่ได้ส่ง Token']);
    exit;
}
$claims = null;
try {
    // ถ้าใช้ firebase-jwt:
    // $decoded = Firebase\JWT\JWT::decode($m[1], new Firebase\JWT\Key($jwt_key,'HS256'));
    // $claims = (array)$decoded;
    $claims = decodeToken($m[1]); // ของคุณเอง
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}
$studentId = (string)($claims['sid'] ?? $claims['student_id'] ?? '');
if ($studentId === '') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบสิทธิ์นิสิตใน Token']);
    exit;
}

// ---------- read json body ----------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$registrationId = isset($data['registration_id']) ? (int)$data['registration_id'] : 0;
if ($registrationId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'registration_id ไม่ถูกต้อง']);
    exit;
}

// ---------- util: safe unique ref generator (string only) ----------
function generateRefNo(PDO $pdo): string
{
    // ความยาว 10 ตัวเลข (string)
    // รูปแบบ: 2 หลักท้ายปี + 8 หลักสุ่ม
    $yy = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('y');
    $tries = 0;
    do {
        $rand = (string) random_int(0, 99999999); // 0..8หลัก
        $rand = str_pad($rand, 8, '0', STR_PAD_LEFT);  // >>> ใส่ string เสมอ
        $ref  = $yy . $rand;                           // รวมเป็น 10 หลัก
        // ตรวจ unique
        $stmt = $pdo->prepare('SELECT 1 FROM payments WHERE ref_no = ? LIMIT 1');
        $stmt->execute([$ref]);
        $exists = (bool)$stmt->fetchColumn();
        $tries++;
        if ($tries > 10) { // กันลูปยาว
            throw new RuntimeException('ไม่สามารถสร้างเลขอ้างอิงที่ไม่ซ้ำได้');
        }
    } while ($exists);
    return $ref;
}

// ---------- optional: สร้าง PromptPay QR payload อย่างง่าย ----------
function buildPromptPayPayload(string $ppId, float $amount, string $refNo): string
{
    // นี่เป็น payload ตัวอย่าง/จำลอง (ไม่ทำ CRC/EMV เต็ม)
    // ถ้าคุณมีตัวสร้าง EMVCo จริงให้แทนที่ฟังก์ชันนี้
    $amt = number_format($amount, 2, '.', '');
    return "PROMPTPAY|ACC=$ppId|AMT=$amt|REF=$refNo";
}

try {
    // timezone
    $pdo->exec("SET time_zone = '+07:00'");

    // 1) ดึงรายการลงทะเบียนของนิสิต
    $sql = "SELECT r.id, r.student_id, r.slot_id, r.fee_amount, r.payment_status,
                   s.exam_date, s.start_time, s.end_time
            FROM exam_slot_registrations r
            JOIN exam_slots s ON s.id = r.slot_id
            WHERE r.id = ? AND r.student_id = ?
            LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$registrationId, $studentId]);
    $reg = $st->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบรายการลงทะเบียนของคุณ']);
        exit;
    }

    $fee = (float)$reg['fee_amount'];
    $pstatus = strtolower((string)$reg['payment_status']);

    if ($pstatus === 'cancelled') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'รายการนี้ถูกยกเลิกแล้ว']);
        exit;
    }
    if ($pstatus === 'paid' || $fee <= 0.0) {
        // ไม่ต้องจ่าย
        echo json_encode([
            'status' => 'success',
            'message' => 'ไม่ต้องชำระเงิน',
            'already_paid' => ($pstatus === 'paid'),
            'registration_id' => (int)$reg['id'],
            'amount' => $fee,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) มี payment pending เดิมอยู่ไหม? → ส่งกลับเดิมเพื่อกันซ้ำ
    $st2 = $pdo->prepare("SELECT payment_id, ref_no, amount, status, created_at
                          FROM payments
                          WHERE registration_id = ? AND status = 'pending'
                          ORDER BY payment_id DESC LIMIT 1");
    $st2->execute([$registrationId]);
    $pOld = $st2->fetch(PDO::FETCH_ASSOC);
    if ($pOld) {
        // ต่ออายุหมดเวลาอีก 15 นาทีจากตอนนี้
        $expireAt = (new DateTime('+15 minutes', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM);
        $payload  = buildPromptPayPayload('0812345678', (float)$pOld['amount'], $pOld['ref_no']);
        echo json_encode([
            'status'       => 'success',
            'reused'       => true,
            'payment_id'   => (int)$pOld['payment_id'],
            'registration_id' => (int)$reg['id'],
            'amount'       => (float)$pOld['amount'],
            'ref_no'       => $pOld['ref_no'],
            'qr_payload'   => $payload,
            'account'      => '0812345678',
            'expire_at'    => $expireAt,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) สร้าง payment ใหม่
    $pdo->beginTransaction();

    // สร้างเลขอ้างอิง (แก้ให้เป็น string เสมอ)
    $refNo = generateRefNo($pdo);

    $ins = $pdo->prepare("INSERT INTO payments
            (student_id, registration_id, amount, method, status, ref_no, created_at)
            VALUES (?, ?, ?, 'simulate', 'pending', ?, NOW())");
    $ins->execute([$studentId, $registrationId, $fee, $refNo]);
    $paymentId = (int)$pdo->lastInsertId();

    // อาจอัพเดทสถานะ registration → pending (กันกรณีเดิมเป็น free)
    $upd = $pdo->prepare("UPDATE exam_slot_registrations
                          SET payment_status = 'pending', payment_ref = ?
                          WHERE id = ? AND student_id = ?");
    $upd->execute([$refNo, $registrationId, $studentId]);

    $pdo->commit();

    // เตรียม QR payload + เวลา expire 15 นาที
    $expireAt = (new DateTime('+15 minutes', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM);
    $payload  = buildPromptPayPayload('0812345678', $fee, $refNo);

    echo json_encode([
        'status'          => 'success',
        'payment_id'      => $paymentId,
        'registration_id' => (int)$reg['id'],
        'amount'          => $fee,
        'ref_no'          => $refNo,
        'qr_payload'      => $payload,
        'account'         => '0812345678',        // PromptPay ที่รับเงิน (ตัวอย่าง)
        'expire_at'       => $expireAt
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jerr('EXCEPTION', ['err' => $e->getMessage(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการสร้างรายการชำระเงิน'], JSON_UNESCAPED_UNICODE);
}
