<?php
// ส่งผลสอบ session_id ไปให้อาจารย์ (mockup: log, future: email/แจ้งเตือน)
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';

// ตรวจสอบ token (student)
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? '';
if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
    // TODO: validate JWT จริงจัง (mockup ผ่าน)
} else {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "No token provided"]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$session_id = $data['session_id'] ?? null;
if (!$session_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing session_id"]);
    exit;
}

// mockup: log การส่ง (ในอนาคตอาจส่งอีเมลหรือแจ้งเตือน)
error_log("[send_result_to_teacher] session_id=$session_id ส่งผลสอบให้อาจารย์แล้ว");

// สามารถเพิ่ม logic ส่งอีเมล/แจ้งเตือนจริงได้ที่นี่

// ตอบกลับสำเร็จ
http_response_code(200);
echo json_encode(["status" => "success", "message" => "ส่งข้อมูลผลสอบให้อาจารย์เรียบร้อยแล้ว"]);
