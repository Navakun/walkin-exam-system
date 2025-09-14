<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ==================
// JWT ตรวจสอบ
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
    if (!$student_id) throw new Exception("token ไม่มี student_id");
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Token ไม่ถูกต้องหรือหมดอายุ','debug'=>$e->getMessage()]);
    exit;
}

// ==================
// รับ slot_id
// ==================
$data = json_decode(file_get_contents("php://input"), true);
$slot_id = $data['slot_id'] ?? null;
if (!$slot_id) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'ไม่พบ slot_id']);
    exit;
}

try {
    // ตรวจสอบซ้ำ
    $stmt=$pdo->prepare("SELECT 1 FROM exam_booking WHERE student_id=? AND slot_id=?");
    $stmt->execute([$student_id,$slot_id]);
    if($stmt->fetch()) throw new Exception("คุณได้ลงทะเบียนรอบนี้แล้ว");

    // ตรวจสอบ slot
    $stmt=$pdo->prepare("
        SELECT es.*, (SELECT COUNT(*) FROM exam_booking WHERE slot_id=es.id) AS booked_count
        FROM exam_slots es WHERE es.id=?
    ");
    $stmt->execute([$slot_id]);
    $slot=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$slot) throw new Exception("ไม่พบข้อมูลรอบสอบ");

    $now=new DateTime();
    if($now<new DateTime($slot['reg_open_at'])) throw new Exception("ยังไม่ถึงเวลาลงทะเบียน");
    if($now>new DateTime($slot['reg_close_at'])) throw new Exception("หมดเขตลงทะเบียน");
    if($slot['booked_count']>=$slot['max_seats']) throw new Exception("ที่นั่งเต็มแล้ว");

    // ตรวจ Policy
    $stmt=$pdo->query("
        SELECT * FROM policies
        WHERE effective_from<=CURDATE() AND effective_to>=CURDATE()
        ORDER BY effective_from DESC LIMIT 1
    ");
    $policy=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$policy) throw new Exception("ไม่พบ Policy ที่ใช้งานอยู่");

    $stmt=$pdo->prepare("
        SELECT COUNT(*) FROM exam_slot_registrations
        WHERE student_id=? AND fee_amount=0
    ");
    $stmt->execute([$student_id]);
    $used_attempts=$stmt->fetchColumn();
    $attempt_no=$used_attempts+1;
    $free_attempts=(int)$policy['free_attempts'];
    $fee_per_extra=(float)$policy['fee_per_extra'];

    if($used_attempts<$free_attempts){
        $fee_amount=0.00;
        $payment_status="free";
    }else{
        $fee_amount=$fee_per_extra;
        $payment_status="pending";
    }

    // Insert DB
    $pdo->beginTransaction();

    $stmt=$pdo->prepare("INSERT INTO exam_booking(student_id,slot_id,scheduled_at,status) VALUES(?,?,NOW(),'registered')");
    $stmt->execute([$student_id,$slot_id]);
    $booking_id=$pdo->lastInsertId();

    $stmt=$pdo->prepare("INSERT INTO exam_slot_registrations(student_id,slot_id,attempt_no,fee_amount,payment_status,registered_at) VALUES(?,?,?,?,?,NOW())");
    $stmt->execute([$student_id,$slot_id,$attempt_no,$fee_amount,$payment_status]);
    $registration_id=$pdo->lastInsertId();

    if($payment_status==="pending"){
        $stmt=$pdo->prepare("INSERT INTO payments(student_id,registration_id,amount,status,created_at) VALUES(?,?,?,'pending',NOW())");
        $stmt->execute([$student_id,$registration_id,$fee_amount]);
    }

    $pdo->commit();

    echo json_encode([
        'status'=>'success',
        'message'=>$payment_status==='free'
            ? "ลงทะเบียนสำเร็จ (สิทธิ์ฟรีครั้งที่ {$attempt_no})"
            : "ลงทะเบียนสำเร็จ (กรุณาชำระเงิน {$fee_amount} บาท)",
        'booking_id'=>$booking_id,
        'registration_id'=>$registration_id,
        'fee_amount'=>$fee_amount,
        'payment_status'=>$payment_status
    ]);

}catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
require_once '../config/db.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');

// ✅ Debug Error
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ==================
// 🔹 ตรวจสอบ JWT
// ==================
$headers = getallheaders();
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}

$token = $matches[1];
try {
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception("Token ไม่มี student_id");
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ', 'debug' => $e->getMessage()]);
    exit;
}

// ==================
// 🔹 รับข้อมูล slot_id
// ==================
$data = json_decode(file_get_contents("php://input"), true);
$slot_id = $data['slot_id'] ?? null;
if (!$slot_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ slot_id']);
    exit;
}

try {
    // ==================
    // 🔹 ตรวจสอบการลงทะเบียนซ้ำ
    // ==================
    $stmt = $pdo->prepare("SELECT 1 FROM exam_booking WHERE student_id = ? AND slot_id = ?");
    $stmt->execute([$student_id, $slot_id]);
    if ($stmt->fetch()) throw new Exception("คุณได้ลงทะเบียนรอบนี้แล้ว");

    // ==================
    // 🔹 ตรวจสอบข้อมูลรอบสอบ
    // ==================
    $stmt = $pdo->prepare("
        SELECT es.*, 
               (SELECT COUNT(*) FROM exam_booking WHERE slot_id = es.id) AS booked_count
        FROM exam_slots es
        WHERE es.id = ?
    ");
    $stmt->execute([$slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) throw new Exception("ไม่พบข้อมูลรอบสอบ");

    $now = new DateTime();
    $reg_open = new DateTime($slot['reg_open_at']);
    $reg_close = new DateTime($slot['reg_close_at']);
    $exam_end = new DateTime($slot['exam_date'] . ' ' . $slot['end_time']);

    if ($now < $reg_open) throw new Exception("ยังไม่ถึงเวลาลงทะเบียน");
    if ($now > $reg_close) throw new Exception("หมดเขตลงทะเบียน");
    if ($now > $exam_end) throw new Exception("เลยเวลาสอบแล้ว");
    if ($slot['booked_count'] >= $slot['max_seats']) throw new Exception("ที่นั่งเต็มแล้ว");

    // ==================
    // 🔹 ตรวจสอบ Policy
    // ==================
    $stmt = $pdo->prepare("
        SELECT * FROM policies
        WHERE effective_from <= CURDATE() AND effective_to >= CURDATE()
        ORDER BY effective_from DESC LIMIT 1
    ");
    $stmt->execute();
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$policy) throw new Exception("ไม่พบ Policy ที่ใช้งานอยู่");

    // นับจำนวนครั้งที่สอบฟรีแล้ว
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM exam_slot_registrations
        WHERE student_id = ? AND fee_amount = 0
    ");
    $stmt->execute([$student_id]);
    $used_attempts = $stmt->fetchColumn();

    $attempt_no = $used_attempts + 1;
    $free_attempts = (int)$policy['free_attempts'];
    $fee_per_extra = (float)$policy['fee_per_extra'];

    if ($used_attempts < $free_attempts) {
        $fee_amount = 0.00;
        $payment_status = "free";
    } else {
        $fee_amount = $fee_per_extra;
        $payment_status = "pending"; // ต้องไปจ่ายเงิน
    }

    // ==================
    // 🔹 บันทึกข้อมูล
    // ==================
    $pdo->beginTransaction();

    // 1) บันทึก exam_booking
    $stmt = $pdo->prepare("
        INSERT INTO exam_booking (student_id, slot_id, scheduled_at, status)
        VALUES (?, ?, NOW(), 'registered')
    ");
    $stmt->execute([$student_id, $slot_id]);
    $booking_id = $pdo->lastInsertId();

    // 2) บันทึก exam_slot_registrations
    $stmt = $pdo->prepare("
        INSERT INTO exam_slot_registrations 
            (student_id, slot_id, attempt_no, fee_amount, payment_status, registered_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$student_id, $slot_id, $attempt_no, $fee_amount, $payment_status]);
    $registration_id = $pdo->lastInsertId();

    // 3) ถ้าเกินสิทธิ์ฟรี → insert payments
    if ($payment_status === "pending") {
        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, registration_id, amount, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$student_id, $registration_id, $fee_amount]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => $payment_status === 'free' 
            ? "ลงทะเบียนสำเร็จ (ใช้สิทธิ์สอบฟรีครั้งที่ {$attempt_no})"
            : "ลงทะเบียนสำเร็จ (รอชำระเงินจำนวน {$fee_amount} บาท)",
        'booking_id' => $booking_id,
        'registration_id' => $registration_id,
        'fee_amount' => $fee_amount,
        'payment_status' => $payment_status
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
