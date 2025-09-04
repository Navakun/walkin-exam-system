<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: POST");

include '../config/db.php';

// ==== [1] ตรวจสอบ Token ====
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

// ==== [2] รับข้อมูล ====
$data = json_decode(file_get_contents("php://input"), true);

if (
    !isset($data['question_id']) ||
    !isset($data['question_text']) ||
    !isset($data['choices']) ||
    !isset($data['correct_choice']) ||
    !isset($data['difficulty'])
) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน"]);
    exit();
}

$questionId = (int) $data['question_id'];
$questionText = trim($data['question_text']);
$choices = $data['choices'];
$correctChoice = strtoupper(trim($data['correct_choice']));
$difficulty = floatval($data['difficulty']);

// ==== [3] Validation ====
$validDifficulties = [0.15, 0.5, 0.85];
if (!in_array($difficulty, $validDifficulties, true)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ค่าความยากต้องเป็น 0.15, 0.5 หรือ 0.85 เท่านั้น"]);
    exit();
}

if (!is_array($choices) || count($choices) < 2) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ต้องมีตัวเลือกอย่างน้อย 2 ข้อ"]);
    exit();
}

$validLabels = ['A', 'B', 'C', 'D', 'E']; // อัปเดตเพิ่มได้ตามจริง
foreach ($choices as $label => $content) {
    $label = strtoupper(trim($label));
    if (!in_array($label, $validLabels)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "พบ label ไม่ถูกต้อง: " . htmlspecialchars($label)]);
        exit();
    }
}

if (!array_key_exists($correctChoice, $choices)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ตัวเลือกคำตอบที่ถูกต้องต้องอยู่ในชุดตัวเลือกที่ให้มา"]);
    exit();
}

// ==== [4] อัปเดตข้อมูล ====
$pdo->beginTransaction();
try {
    $sqlUpdateQuestion = "UPDATE question SET question_text = ?, correct_choice = ?, difficulty = ? WHERE question_id = ?";
    $stmtQuestion = $pdo->prepare($sqlUpdateQuestion);
    $stmtQuestion->execute([$questionText, $correctChoice, $difficulty, $questionId]);

    $sqlUpdateChoice = "UPDATE choice SET content = ? WHERE question_id = ? AND label = ?";
    $stmtChoice = $pdo->prepare($sqlUpdateChoice);
    foreach ($choices as $label => $content) {
        $stmtChoice->execute([$content, $questionId, strtoupper(trim($label))]);
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "แก้ไขคำถามสำเร็จ"]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
?>
