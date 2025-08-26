<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST");

include '../config/db.php';

// --- [เพิ่ม] 1. ตรวจสอบ Token ของอาจารย์ ---
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


// --- [เพิ่ม] 2. รับและตรวจสอบข้อมูลที่แก้ไขแล้ว ---
$data = json_decode(file_get_contents("php://input"), true);

if (
    !isset($data['question_id']) ||
    !isset($data['question_text']) ||
    !isset($data['choices']) ||
    !isset($data['correct_choice']) ||
    !isset($data['difficulty_level'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน"]);
    exit();
}

$questionId = $data['question_id'];
$questionText = $data['question_text'];
$choices = $data['choices'];
$correctChoice = $data['correct_choice'];
$difficultyLevel = $data['difficulty_level'];
// --- สิ้นสุดการรับและตรวจสอบข้อมูล ---


// --- 3. บันทึกข้อมูลด้วย Transaction (ส่วนนี้ถูกต้องแล้ว) ---
$conn->begin_transaction();
try {
    // 1. อัปเดตตาราง Question
    $sqlUpdateQuestion = "UPDATE Question SET question_text = ?, correct_choice = ?, difficulty_level = ? WHERE question_id = ?";
    $stmtQuestion = $conn->prepare($sqlUpdateQuestion);
    $stmtQuestion->bind_param("ssii", $questionText, $correctChoice, $difficultyLevel, $questionId);
    $stmtQuestion->execute();

    // 2. อัปเดตตาราง Choice
    $sqlUpdateChoice = "UPDATE Choice SET content = ? WHERE question_id = ? AND label = ?";
    $stmtChoice = $conn->prepare($sqlUpdateChoice);
    foreach ($choices as $label => $content) {
        $stmtChoice->bind_param("sis", $content, $questionId, $label);
        $stmtChoice->execute();
    }

    $conn->commit();
    echo json_encode(["status" => "success", "message" => "แก้ไขคำถามสำเร็จ"]);

} catch (Exception $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}

$conn->close();
?>