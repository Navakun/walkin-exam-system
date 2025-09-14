<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');

// Debug error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==================
// 🔹 ตรวจสอบ JWT
// ==================
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

// ==================
// 🔹 รับข้อมูลจาก body
// ==================
$data = json_decode(file_get_contents("php://input"), true);
$registration_id = $data['registration_id'] ?? null;
$ref_no = $data['ref_no'] ?? null;

if (!$registration_id || !$ref_no) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'ข้อมูลไม่ครบถ้วน (registration_id, ref_no)']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ตรวจสอบ registration
    $stmt = $pdo->prepare("
        SELECT * FROM exam_slot_registrations 
        WHERE id=? AND student_id=? AND payment_status='pending'
    ");
    $stmt->execute([$registration_id,$student_id]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if(!$reg) throw new Exception("ไม่พบรายการลงทะเบียนที่รอชำระเงิน");

    // อัปเดตตาราง payments
    $stmt = $pdo->prepare("
        UPDATE payments 
        SET status='paid', ref_no=?, paid_at=NOW() 
        WHERE registration_id=? AND student_id=? AND status='pending'
    ");
    $stmt->execute([$ref_no,$registration_id,$student_id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception("ไม่พบรายการชำระเงินที่ต้องอัปเดต");
    }

    // อัปเดต registration
    $stmt = $pdo->prepare("
        UPDATE exam_slot_registrations 
        SET payment_status='paid' 
        WHERE id=?
    ");
    $stmt->execute([$registration_id]);

    $pdo->commit();

    echo json_encode([
        'status'=>'success',
        'message'=>'ชำระเงินสำเร็จ',
        'registration_id'=>$registration_id,
        'ref_no'=>$ref_no
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
