<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST");

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


// --- 2. รับข้อมูลจาก Frontend ---
$data = json_decode(file_get_contents("php://input"), true);

if (
    !isset($data['question_text']) ||
    !isset($data['choices']) ||
    !isset($data['correct_choice']) ||
    !isset($data['difficulty_level'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน"]);
    exit();
}

$questionText = $data['question_text'];
$choices = $data['choices'];
$correctChoice = $data['correct_choice'];
$difficultyLevel = $data['difficulty_level'];

// --- 3. บันทึกข้อมูลลงฐานข้อมูลด้วย Transaction ---
$conn->begin_transaction();

try {
    // ขั้นตอนที่ 1: เพิ่มคำถามลงในตาราง Question
    $sqlInsertQuestion = "INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES (?, ?, ?)";
    $stmtQuestion = $conn->prepare($sqlInsertQuestion);
    $stmtQuestion->bind_param("ssi", $questionText, $correctChoice, $difficultyLevel);
    $stmtQuestion->execute();
    
    // ดึง question_id ของคำถามที่เพิ่งสร้าง
    $newQuestionId = $conn->insert_id;

    // ขั้นตอนที่ 2: เพิ่มตัวเลือกทั้งหมดลงในตาราง Choice
    $sqlInsertChoice = "INSERT INTO Choice (question_id, label, content) VALUES (?, ?, ?)";
    $stmtChoice = $conn->prepare($sqlInsertChoice);

    foreach ($choices as $label => $content) {
        $stmtChoice->bind_param("iss", $newQuestionId, $label, $content);
        $stmtChoice->execute();
    }

    // ถ้าทุกอย่างสำเร็จ ให้ยืนยันการทำรายการ
    $conn->commit();
    echo json_encode(["status" => "success", "message" => "เพิ่มคำถามสำเร็จ"]);

} catch (Exception $e) {
    // ถ้ายกเลิก ให้ย้อนกลับทั้งหมด
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
}

$conn->close();
?>