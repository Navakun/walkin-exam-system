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

    // ===== ดึงข้อมูล slot =====
    $stmt = $pdo->prepare("SELECT * FROM exam_slots WHERE id = ?");
    $stmt->execute([$slot_id]);
    $slot = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$slot) throw new Exception("ไม่พบข้อมูลรอบสอบ");
    $now = new DateTime();
    if ($now < new DateTime($slot['reg_open_at']) || $now > new DateTime($slot['reg_close_at'])) {
        throw new Exception("ยังไม่ถึงเวลาหรือเลยเวลาลงทะเบียน");
    }

    // ===== ตรวจสอบจำนวนที่นั่งเต็มหรือยัง =====
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE slot_id = ?");
    $stmt->execute([$slot_id]);
    $booked = $stmt->fetchColumn();
    if ($booked >= $slot['max_seats']) throw new Exception("ที่นั่งเต็มแล้ว");

    // ===== ตรวจสอบว่านักศึกษาลงรอบนี้แล้วหรือยัง =====
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE slot_id = ? AND student_id = ?");
    $stmt->execute([$slot_id, $student_id]);
    if ($stmt->fetchColumn() > 0) throw new Exception("คุณได้ลงทะเบียนรอบนี้แล้ว");

    // ===== ดึง policy ล่าสุดที่ยังมีผลอยู่วันนี้ =====
    $stmt = $pdo->prepare("
        SELECT * FROM exam_policies 
        WHERE CURDATE() BETWEEN effective_from AND effective_to
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$policy) throw new Exception("ไม่พบนโยบายการสอบในช่วงเวลานี้");

    // ===== นับจำนวนครั้งที่เคยสอบแล้ว =====
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $attempts = $stmt->fetchColumn();

    // ===== ตรวจสอบว่าสอบฟรีได้หรือไม่ =====
    $is_free = ($attempts < $policy['free_attempts']);
    $fee_amount = $is_free ? 0 : floatval($policy['fee_per_extra']);
    $payment_status = $is_free ? 'free' : 'pending';

    // ===== บันทึกการลงทะเบียน =====
    $stmt = $pdo->prepare("
        INSERT INTO exam_slot_registrations (student_id, slot_id, attempt_no, fee_amount, payment_status, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $student_id,
        $slot_id,
        $attempts + 1,
        $fee_amount,
        $payment_status
    ]);
    $registration_id = $pdo->lastInsertId();

    // ===== ถ้าไม่ฟรี → สร้าง payment =====
    if (!$is_free) {
        $stmt = $pdo->prepare("
            INSERT INTO payments (student_id, registration_id, amount, status, created_at)
            VALUES (?, ?, ?, 'pending', NOW())
        ");
        $stmt->execute([$student_id, $registration_id, $fee_amount]);
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => 'ลงทะเบียนสำเร็จ',
        'registration_id' => $registration_id,
        'slot_id' => $slot_id,
        'payment_status' => $payment_status,
        'fee_amount' => $fee_amount,
        'attempt_no' => $attempts + 1
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
