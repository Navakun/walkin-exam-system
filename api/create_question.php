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

if (empty($jwt_key)) {
    throw new Exception('JWT key not configured');
}

if (!$authHeader) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "ไม่ได้ส่ง Token"]);
    exit();
}

list($jwt) = sscanf($authHeader, 'Bearer %s');

try {
    $decoded = JWT::decode($jwt, new Key($jwt_key, 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token ไม่ถูกต้อง: " . $e->getMessage()]);
    exit();
}
// --- สิ้นสุดการตรวจสอบ Token ---



$raw = file_get_contents("php://input");
error_log("RAW BODY: " . $raw);
$data = json_decode($raw, true);
error_log("DECODED DATA: " . print_r($data, true));

if (
    !isset($data['question_text']) ||
    !isset($data['choices']) ||
    !isset($data['correct_choice']) ||
    !isset($data['difficulty_level'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน", "debug_raw" => $raw, "debug_data" => $data]);
    exit();
}

$questionText = $data['question_text'];
$choices = $data['choices'];
$correctChoice = $data['correct_choice'];
$difficultyLevel = $data['difficulty_level'];

// --- 3. บันทึกข้อมูลลงฐานข้อมูลด้วย Transaction (PDO) ---
$pdo->beginTransaction();
try {
    // ขั้นตอนที่ 1: เพิ่มคำถามลงในตาราง Question
    $sqlInsertQuestion = "INSERT INTO Question (question_text, correct_choice, item_difficulty) VALUES (?, ?, ?)";
    $stmtQuestion = $pdo->prepare($sqlInsertQuestion);
    $stmtQuestion->execute([$questionText, $correctChoice, $difficultyLevel]);
    // ดึง question_id ของคำถามที่เพิ่งสร้าง
    $newQuestionId = $pdo->lastInsertId();

    // ขั้นตอนที่ 2: เพิ่มตัวเลือกทั้งหมดลงในตาราง Choice
    $sqlInsertChoice = "INSERT INTO Choice (question_id, label, content) VALUES (?, ?, ?)";
    $stmtChoice = $pdo->prepare($sqlInsertChoice);
    foreach ($choices as $label => $content) {
        $stmtChoice->execute([$newQuestionId, $label, $content]);
    }

    // ถ้าทุกอย่างสำเร็จ ให้ยืนยันการทำรายการ
    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "เพิ่มคำถามสำเร็จ"]);
} catch (Exception $e) {
    // ถ้ายกเลิก ให้ย้อนกลับทั้งหมด
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
}
?>