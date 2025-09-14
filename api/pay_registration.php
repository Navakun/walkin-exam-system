<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ================== ตรวจสอบ JWT ==================
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'ไม่พบ token']);
    exit;
}

$token = $matches[1];
try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception("Token ไม่มี student_id");
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Token ไม่ถูกต้องหรือหมดอายุ','debug'=>$e->getMessage()]);
    exit;
}

// ================== รับข้อมูล ==================
$registration_id = $_POST['registration_id'] ?? null;
$ref_no = $_POST['ref_no'] ?? null;
$slipFile = null;

// Debug ข้อมูลที่รับมา
error_log("==[pay_registration.php]==");
error_log("student_id: $student_id");
error_log("registration_id: $registration_id");
error_log("ref_no: $ref_no");

if (!$registration_id || !$ref_no) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'ข้อมูลไม่ครบถ้วน (registration_id, ref_no)']);
    exit;
}

// ================== อัปโหลดไฟล์ (ถ้ามี) ==================
if (!empty($_FILES['slip_file']['name'])) {
    $uploadDir = __DIR__ . '/../uploads/slips/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    $ext = pathinfo($_FILES['slip_file']['name'], PATHINFO_EXTENSION);
    $fileName = 'slip_' . time() . '_' . $student_id . '.' . $ext;
    $filePath = $uploadDir . $fileName;

    if (move_uploaded_file($_FILES['slip_file']['tmp_name'], $filePath)) {
        $slipFile = 'uploads/slips/' . $fileName;
    } else {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'อัปโหลดสลิปไม่สำเร็จ']);
        exit;
    }
} else {
    $slipFile = ''; // fallback ถ้าไม่ได้อัปโหลด
}

try {
    $pdo->beginTransaction();

    // ===== ตรวจสอบว่า registration มีจริงและยัง pending =====
    $stmt = $pdo->prepare("SELECT * FROM exam_slot_registrations 
                           WHERE id=? AND student_id=? AND payment_status='pending'");
    $stmt->execute([$registration_id, $student_id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg) {
        throw new Exception("ไม่พบรายการลงทะเบียนที่รอชำระเงิน");
    }

    // ===== ตรวจสอบว่ามี payment pending อยู่หรือไม่ =====
    $stmt = $pdo->prepare("SELECT * FROM payments 
                           WHERE registration_id=? AND student_id=? AND status='pending'");
    $stmt->execute([$registration_id, $student_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($payment) {
        // มี payment แล้ว → อัปเดต
        error_log("Updating existing payment_id: " . $payment['payment_id']);
        $stmt = $pdo->prepare("UPDATE payments 
            SET status='paid', ref_no=?, slip_file=?, paid_at=NOW() 
            WHERE payment_id=?");
        $stmt->execute([$ref_no, $slipFile, $payment['payment_id']]);
    } else {
        // ไม่มี → แทรกใหม่
        error_log("Inserting new payment for registration_id: $registration_id");
        $stmt = $pdo->prepare("INSERT INTO payments 
            (student_id, registration_id, amount, method, status, paid_at, ref_no, slip_file, created_at)
            VALUES (?, ?, ?, ?, 'paid', NOW(), ?, ?, NOW())");
        $stmt->execute([
            $student_id,
            $registration_id,
            $reg['fee_amount'],
            'manual', // หรือ method ที่ส่งจาก client
            $ref_no,
            $slipFile
        ]);
    }

    // ===== อัปเดตสถานะใน exam_slot_registrations =====
    $stmt = $pdo->prepare("UPDATE exam_slot_registrations SET payment_status='paid' WHERE id=?");
    $stmt->execute([$registration_id]);

    $pdo->commit();

    echo json_encode([
        'status'=>'success',
        'message'=>'ชำระเงินสำเร็จ',
        'registration_id'=>$registration_id,
        'ref_no'=>$ref_no,
        'slip_file'=>$slipFile
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    error_log("❌ Payment Error: " . $e->getMessage());
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
