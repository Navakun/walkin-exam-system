<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST"); // ใช้ POST สำหรับการลบ

include '../config/db.php';

// --- 1. ตรวจสอบ Token ของอาจารย์ ---
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef"; // <-- ใช้ Key ของ "อาจารย์"

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "ไม่ได้ส่ง Token"]);
    exit();
}

list($jwt) = sscanf($authHeader, 'Bearer %s');

try {
    $decoded = JWT::decode($jwt, new Key($secret_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token ไม่ถูกต้อง: " . $e->getMessage()]);
    exit();
}
// --- สิ้นสุดการตรวจสอบ Token ---

// --- 2. รับข้อมูล question_id ที่จะลบ ---
$data = json_decode(file_get_contents("php://input"));

if (!isset($data->question_id)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ question_id']);
    exit();
}

$questionId = $data->question_id;

// --- 3. ทำการลบข้อมูล (PDO) ---
try {
    $sql = "DELETE FROM Question WHERE question_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$questionId]);
    if ($stmt->rowCount() > 0) {
        echo json_encode(['status' => 'success', 'message' => 'ลบคำถามสำเร็จ']);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบคำถามที่ต้องการลบ']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>