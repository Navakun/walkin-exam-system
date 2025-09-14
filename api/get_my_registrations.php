<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');

// =====================
// 🔹 ตรวจสอบ JWT
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


try {
    $sql = "
        SELECT 
            r.id AS registration_id,
            r.slot_id,
            IFNULL(s.exam_date, '') AS slot_date,
            IFNULL(s.start_time, '') AS start_time,
            IFNULL(s.end_time, '') AS end_time,
            r.attempt_no,
            r.fee_amount,
            r.payment_status AS reg_payment_status,  -- จาก registrations
            p.payment_id,
            p.amount AS payment_amount,
            p.status AS payment_status_db,           -- จาก payments
            p.method,
            p.ref_no,
            p.slip_file,
            p.paid_at,
            p.created_at AS payment_created
        FROM exam_slot_registrations r
        LEFT JOIN exam_slots s ON r.slot_id = s.id
        LEFT JOIN payments p 
            ON r.id = p.registration_id 
           AND r.student_id = p.student_id
        WHERE r.student_id = ?
        ORDER BY s.exam_date DESC, s.start_time DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$student_id]);
    $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // แปลงข้อมูลและเพิ่มการป้องกัน null/undefined
    $formatted_registrations = array_map(function($row) {
        return [
            'registration_id' => $row['registration_id'] ?? 0,
            'slot_id' => $row['slot_id'] ?? 0,
            'slot_date' => $row['slot_date'] ?? '',
            'start_time' => $row['start_time'] ?? '',
            'end_time' => $row['end_time'] ?? '',
            'attempt_no' => $row['attempt_no'] ?? 1,
            'fee_amount' => $row['fee_amount'] ?? 0,
            'payment_status' => $row['payment_status_db'] ?? $row['reg_payment_status'] ?? 'unknown',
            'payment_id' => $row['payment_id'] ?? null,
            'payment_amount' => $row['payment_amount'] ?? 0,
            'method' => $row['method'] ?? null,
            'ref_no' => $row['ref_no'] ?? null,
            'slip_file' => $row['slip_file'] ?? null,
            'paid_at' => $row['paid_at'] ?? null,
            'payment_created' => $row['payment_created'] ?? null
        ];
    }, $registrations);

    echo json_encode([
        'status' => 'success',
        'registrations' => $formatted_registrations
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'=>'error',
        'message'=>'SERVER_ERROR',
        'debug'=>$e->getMessage()
    ]);
}
