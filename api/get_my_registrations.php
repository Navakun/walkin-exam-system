<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 🔹 ตรวจสอบ JWT
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

try {
    // 🔹 ดึงข้อมูลการลงทะเบียนทั้งหมด (current + history)
    $stmt = $pdo->prepare("
        SELECT 
            es.exam_date AS slot_date,
            es.start_time,
            es.end_time,
            r.attempt_no,
            r.fee_amount,
            r.payment_status,
            r.registered_at,
            p.ref_no,
            p.status AS payment_db_status,
            p.paid_at,
            r.id AS registration_id,
            eb.id AS booking_id
        FROM exam_slot_registrations r
        JOIN exam_slots es ON r.slot_id = es.id
        JOIN exam_booking eb ON eb.slot_id = es.id AND eb.student_id = r.student_id
        LEFT JOIN payments p ON p.registration_id = r.id
        WHERE r.student_id = ?
        ORDER BY es.exam_date DESC, es.start_time DESC
    ");
    $stmt->execute([$student_id]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 🔹 หาว่าอันไหนคือ current registration
    $now = new DateTime();
    $current = null;
    foreach ($registrations as $reg) {
        $examEnd = new DateTime($reg['slot_date'].' '.$reg['end_time']);
        if ($examEnd > $now) { // ยังไม่สอบ
            $current = $reg;
            break;
        }
    }

    echo json_encode([
        'status' => 'success',
        'current' => $current,   // ✅ สถานะปัจจุบัน
        'registrations' => $registrations // ✅ ประวัติทั้งหมด
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'SERVER_ERROR','debug'=>$e->getMessage()]);
}
