<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// ---------- ตรวจ JWT ----------
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.+)$/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}
$token = $m[1];

try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) {
        throw new Exception('Token ไม่มี student_id');
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ', 'debug' => $e->getMessage()]);
    exit;
}

// ---------- รับข้อมูล ----------
$registration_id = isset($_POST['registration_id']) ? (int)$_POST['registration_id'] : 0;
$ref_no = isset($_POST['ref_no']) ? trim((string)$_POST['ref_no']) : '';
$slipFile = null;

// ตรวจความถูกต้อง ref_no (ตัวเลข >= 10 หลัก)
if ($registration_id <= 0 || $ref_no === '' || !preg_match('/^\d{10,}$/', $ref_no)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลไม่ครบถ้วนหรือรูปแบบอ้างอิงไม่ถูกต้อง (registration_id, ref_no)']);
    exit;
}

// ---------- อัปโหลดไฟล์ (ถ้ามี) ----------
$relSlipPath = ''; // path ที่จะเก็บใน DB (relative)
if (!empty($_FILES['slip_file']['name'])) {
    $allowed = ['image/jpeg', 'image/jpg', 'image/png', 'application/pdf'];
    $type = mime_content_type($_FILES['slip_file']['tmp_name']);
    if (!in_array($type, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ชนิดไฟล์ไม่อนุญาต (อนุญาต .jpg .jpeg .png .pdf)']);
        exit;
    }
    if ((int)$_FILES['slip_file']['size'] > 5 * 1024 * 1024) { // 5MB
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'ขนาดไฟล์เกิน 5 MB']);
        exit;
    }

    $uploadDir = __DIR__ . '/../uploads/slips/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $ext = strtolower(pathinfo($_FILES['slip_file']['name'], PATHINFO_EXTENSION));
    $fname = 'slip_' . date('Ymd_His') . '_' . preg_replace('/\D/', '', $student_id) . '.' . $ext;
    $dest = $uploadDir . $fname;

    if (!move_uploaded_file($_FILES['slip_file']['tmp_name'], $dest)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'อัปโหลดสลิปไม่สำเร็จ']);
        exit;
    }
    $relSlipPath = 'uploads/slips/' . $fname;
}

try {
    $pdo->beginTransaction();

    // ล็อกแถว registration (กันยิงพร้อมกัน)
    $stmt = $pdo->prepare("SELECT * FROM exam_slot_registrations 
                           WHERE id = ? AND student_id = ? FOR UPDATE");
    $stmt->execute([$registration_id, $student_id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        throw new Exception('ไม่พบรายการลงทะเบียนของคุณ');
    }
    if (!in_array($reg['payment_status'], ['pending', 'free', 'waived'], true)) {
        // ถ้าจ่ายแล้ว/ยกเลิกแล้ว ไม่ให้จ่ายซ้ำ
        throw new Exception('สถานะปัจจุบันไม่สามารถชำระเงินได้');
    }
    if ($reg['payment_status'] !== 'pending') {
        // หากเป็น free/waived ก็ไม่ควรเข้ามาที่ endpoint นี้
        throw new Exception('รายการนี้ไม่มีค่าธรรมเนียมที่ต้องชำระ');
    }

    // กัน ref_no ซ้ำ: ถ้า ref_no นี้ถูกใช้โดย registration อื่น → error
    $stmt = $pdo->prepare("SELECT payment_id, registration_id FROM payments WHERE ref_no = ? LIMIT 1");
    $stmt->execute([$ref_no]);
    $dup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($dup && (int)$dup['registration_id'] !== (int)$registration_id) {
        throw new Exception('เลขที่อ้างอิงนี้ถูกใช้กับรายการอื่นแล้ว');
    }

    // มี payment pending อยู่ก่อนแล้วหรือไม่
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE registration_id = ? AND student_id = ? LIMIT 1");
    $stmt->execute([$registration_id, $student_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    $now = date('Y-m-d H:i:s');
    if ($payment) {
        // อัปเดตเป็น paid พร้อมใส่ ref_no / slip
        $stmt = $pdo->prepare("UPDATE payments
                               SET status='paid', ref_no=?, slip_file=?, paid_at=?, method=COALESCE(method,'manual')
                               WHERE payment_id=?");
        $stmt->execute([$ref_no, $relSlipPath, $now, $payment['payment_id']]);
    } else {
        // แทรกใหม่เป็น paid
        $stmt = $pdo->prepare("INSERT INTO payments
            (student_id, registration_id, amount, method, status, paid_at, ref_no, slip_file, created_at)
            VALUES (?, ?, ?, 'manual', 'paid', ?, ?, ?, ?)");
        $stmt->execute([
            $student_id,
            $registration_id,
            $reg['fee_amount'],
            $now,
            $ref_no,
            $relSlipPath,
            $now
        ]);
    }

    // อัปเดตสถานะ registration
    $stmt = $pdo->prepare("UPDATE exam_slot_registrations SET payment_status='paid' WHERE id = ?");
    $stmt->execute([$registration_id]);

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'ชำระเงินสำเร็จ',
        'registration_id' => $registration_id,
        'ref_no' => $ref_no,
        'slip_file' => $relSlipPath
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log('❌ pay_registration error: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
