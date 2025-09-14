<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// =====================
// ตรวจสอบ JWT
// =====================
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

// =====================
// รับข้อมูลจาก body
// =====================
$data = json_decode(file_get_contents("php://input"), true);
$slot_id = $data['slot_id'] ?? null;

if (!$slot_id) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'slot_id ไม่ถูกต้อง']);
    exit;
}

try {
    $pdo->beginTransaction();

    // ดึงข้อมูล slot
    $stmt = $pdo->prepare("SELECT * FROM exam_slots WHERE id=?");
    $stmt->execute([$slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) throw new Exception("ไม่พบข้อมูลรอบสอบ");
    if (new DateTime() < new DateTime($slot['reg_open_at']) || new DateTime() > new DateTime($slot['reg_close_at'])) {
        throw new Exception("ยังไม่ถึงเวลาหรือเลยเวลาลงทะเบียน");
    }

    // ตรวจสอบที่นั่ง
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE slot_id=?");
    $stmt->execute([$slot_id]);
    $booked = $stmt->fetchColumn();
    if ($booked >= $slot['max_seats']) throw new Exception("ที่นั่งเต็มแล้ว");

    // ตรวจสอบว่าลงทะเบียนไปแล้วหรือยัง
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE slot_id=? AND student_id=?");
    $stmt->execute([$slot_id,$student_id]);
    if ($stmt->fetchColumn() > 0) throw new Exception("คุณได้ลงทะเบียนรอบนี้แล้ว");

    // เช็คสิทธิ์ฟรีจาก policies (ถ้ามี)
    $stmt = $pdo->query("SELECT * FROM exam_policies ORDER BY effective_from DESC LIMIT 1");
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE student_id=?");
    $stmt->execute([$student_id]);
    $attempts = $stmt->fetchColumn();

    $is_free = $policy && $attempts < $policy['free_attempts'];
    $fee_amount = $is_free ? 0 : ($policy['fee_per_extra'] ?? 300);

    // บันทึกการลงทะเบียน
    $stmt = $pdo->prepare("
        INSERT INTO exam_slot_registrations (student_id, slot_id, attempt_no, payment_status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $payment_status = $is_free ? 'free' : 'pending';
    $stmt->execute([$student_id, $slot_id, $attempts+1, $payment_status]);
    $registration_id = $pdo->lastInsertId();

    // ถ้าไม่ฟรี → บันทึกตาราง payments ด้วย
    if (!$is_free) {
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                student_id, 
                registration_id, 
                amount, 
                status, 
                created_at
            ) VALUES (
                :student_id,
                :registration_id,
                :amount,
                :status,
                NOW()
            )
        ");
        
        $status = 'pending';
        $stmt->execute([
            'student_id' => $student_id,
            'registration_id' => $registration_id,
            'amount' => $fee_amount,
            'status' => $status
        ]);
        
        $payment_id = $pdo->lastInsertId();
        
        // อัปเดต paid_at ถ้าสถานะเป็น paid
        if (empty($paid_at) && $status === 'paid') {
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET paid_at = NOW() 
                WHERE payment_id = :payment_id
            ");
            $stmt->execute(['payment_id' => $payment_id]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'status'=>'success',
        'message'=>'ลงทะเบียนสำเร็จ',
        'registration_id'=>$registration_id,
        'slot_id'=>$slot_id,
        'payment_status'=>$payment_status,
        'fee_amount'=>$fee_amount
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
