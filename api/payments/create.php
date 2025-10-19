<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$DEBUG = isset($_GET['dbg']); // เปิดโหมด debug ด้วย .../create.php?dbg=1

// 1) include
require_once __DIR__ . '/../config/db.php';
$jwtHelper = __DIR__ . '/../helpers/jwt_helper.php';
if (is_file($jwtHelper)) require_once $jwtHelper;

// force PDO throw exception (กัน 500 เงียบ)
if ($pdo instanceof PDO) {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
}

// 2) helper log
function jlog($msg, $ctx = [])
{
    error_log('[payments/create] ' . $msg . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE)));
}

// 3) auth: student
try {
    $hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
    $auth = $hdrs['authorization'] ?? '';
    if (!preg_match('/bearer\s+(\S+)/i', $auth, $m)) {
        throw new RuntimeException('ไม่ได้ส่ง Token (Authorization: Bearer ...)');
    }
    $token = $m[1];

    // ถ้ามี helper ของคุณ
    if (function_exists('decodeToken')) {
        $claims = decodeToken($token);
        if (!$claims) throw new RuntimeException('Token ไม่ถูกต้อง/หมดอายุ');
    } else {
        // fallback firebase-jwt (ถ้ามี composer)
        if (!class_exists('Firebase\\JWT\\JWT')) {
            throw new RuntimeException('ไม่พบตัวถอด token (install firebase-jwt หรือใช้ helpers/jwt_helper.php)');
        }
        $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($jwt_key, 'HS256'));
        $claims = (array)$decoded;
    }
    $studentId = (string)($claims['sid'] ?? $claims['student_id'] ?? '');
    if ($studentId === '') throw new RuntimeException('Token ไม่มี student_id/sid');
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => $DEBUG ? $e->getMessage() : 'ไม่ได้รับอนุญาต']);
    exit;
}

// 4) body
try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    $registrationId = (int)($data['registration_id'] ?? 0);
    if ($registrationId <= 0) throw new InvalidArgumentException('registration_id ไม่ถูกต้อง');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $DEBUG ? $e->getMessage() : 'คำขอไม่ถูกต้อง']);
    exit;
}

// 5) util
function generateRefNo(PDO $pdo): string
{
    // 10 หลัก numeric เป็น "สตริงเสมอ"
    $yy = (new DateTime('now', new DateTimeZone('Asia/Bangkok')))->format('y');
    $tries = 0;
    do {
        $rand = (string) random_int(0, 99999999);
        $rand = str_pad($rand, 8, '0', STR_PAD_LEFT); // <<< ใส่ string เสมอ
        $ref  = $yy . $rand;
        $stmt = $pdo->prepare('SELECT 1 FROM payments WHERE ref_no=? LIMIT 1');
        $stmt->execute([$ref]);
        $exist = (bool)$stmt->fetchColumn();
        $tries++;
        if ($tries > 10) throw new RuntimeException('สร้างเลขอ้างอิงไม่สำเร็จ');
    } while ($exist);
    return $ref;
}
function buildPromptPayPayload(string $ppId, float $amount, string $refNo): string
{
    // DEMO payload (ยังไม่ใช่ EMVCo จริง) – ใช้ทดสอบแสดง QR ได้
    $amt = number_format($amount, 2, '.', '');
    return "PROMPTPAY|ACC=$ppId|AMT=$amt|REF=$refNo";
}

// 6) core
try {
    $pdo->exec("SET time_zone = '+07:00'");

    // 6.1 ตรวจรายการลงทะเบียนของนิสิต
    $q = "SELECT r.id, r.student_id, r.fee_amount, r.payment_status,
                 s.exam_date, s.start_time, s.end_time
          FROM exam_slot_registrations r
          JOIN exam_slots s ON s.id = r.slot_id
          WHERE r.id=? AND r.student_id=? LIMIT 1";
    $st = $pdo->prepare($q);
    $st->execute([$registrationId, $studentId]);
    $reg = $st->fetch();
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
        echo json_encode([
            'status' => 'success',
            'message' => 'ไม่ต้องชำระเงิน',
            'already_paid' => ($pstatus === 'paid'),
            'registration_id' => (int)$reg['id'],
            'amount' => $fee
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 6.2 ถ้ามี payment pending เก่า → ใช้อันเดิม
    $st2 = $pdo->prepare("SELECT payment_id, ref_no, amount, status, created_at
                          FROM payments
                          WHERE registration_id=? AND status='pending'
                          ORDER BY payment_id DESC LIMIT 1");
    $st2->execute([$registrationId]);
    if ($old = $st2->fetch()) {
        $expire = (new DateTime('+15 minutes', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM);
        echo json_encode([
            'status' => 'success',
            'reused' => true,
            'payment_id' => (int)$old['payment_id'],
            'registration_id' => (int)$reg['id'],
            'amount' => (float)$old['amount'],
            'ref_no' => $old['ref_no'],
            'qr_payload' => buildPromptPayPayload('0812345678', (float)$old['amount'], $old['ref_no']),
            'account' => '0812345678',
            'expire_at' => $expire
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 6.3 สร้างใหม่
    $pdo->beginTransaction();

    $refNo = generateRefNo($pdo);

    // NOTE: method/status ต้องตรง enum ในตาราง
    $ins = $pdo->prepare("INSERT INTO payments
        (student_id, registration_id, amount, method, status, ref_no, created_at)
        VALUES (?, ?, ?, 'simulate', 'pending', ?, NOW())");
    $ins->execute([$studentId, $registrationId, $fee, $refNo]);
    $paymentId = (int)$pdo->lastInsertId();

    $upd = $pdo->prepare("UPDATE exam_slot_registrations
                          SET payment_status='pending', payment_ref=?
                          WHERE id=? AND student_id=?");
    $upd->execute([$refNo, $registrationId, $studentId]);

    $pdo->commit();

    $expire = (new DateTime('+15 minutes', new DateTimeZone('Asia/Bangkok')))->format(DATE_ATOM);

    echo json_encode([
        'status'          => 'success',
        'payment_id'      => $paymentId,
        'registration_id' => (int)$reg['id'],
        'amount'          => $fee,
        'ref_no'          => $refNo,
        'qr_payload'      => buildPromptPayPayload('0812345678', $fee, $refNo),
        'account'         => '0812345678',
        'expire_at'       => $expire
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    jlog('EXCEPTION', ['msg' => $e->getMessage(), 'line' => $e->getLine()]);
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $DEBUG ? $e->getMessage() : 'เกิดข้อผิดพลาดในการสร้างรายการชำระเงิน'
    ], JSON_UNESCAPED_UNICODE);
}
