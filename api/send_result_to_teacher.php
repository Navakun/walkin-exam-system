<?php
// ส่งผลสอบ session_id ไปให้อาจารย์ (mockup)
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';

// อ่าน Authorization header ให้ครอบคลุม
$h = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $h['Authorization'] ?? $h['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "No token provided"]);
    exit;
}
$token = $m[1]; // TODO: validate JWT จริงจัง

// อ่าน session_id จาก JSON body หรือจาก GET (กันพลาด)
$payload = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id = $payload['session_id'] ?? ($_GET['session_id'] ?? null);

if (!$session_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing session_id"]);
    exit;
}

// mock: log ว่าส่งแล้ว (อนาคตค่อยต่ออีเมล/ไลน์)
error_log("[send_result_to_teacher] session_id=$session_id ส่งผลสอบให้อาจารย์แล้ว");

// ตอบกลับสำเร็จ
echo json_encode(["status" => "success", "message" => "ส่งข้อมูลผลสอบให้อาจารย์เรียบร้อยแล้ว"]);
