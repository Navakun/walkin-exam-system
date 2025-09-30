<?php

declare(strict_types=1);

require_once '../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Methods: OPTIONS, POST");

// Preflight (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once '../config/db.php'; // ให้แน่ใจว่า $pdo และ $jwt_key ถูกตั้งไว้

// ---------- [1] JWT ----------
function getBearerToken(): ?string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    return null;
}

try {
    if (empty($jwt_key)) {
        throw new Exception('JWT key not configured');
    }
    $jwt = getBearerToken();
    if (!$jwt) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "ไม่ได้ส่ง Token"]);
        exit;
    }
    $decoded = JWT::decode($jwt, new Key($jwt_key, 'HS256'));
    // ถ้ามี role ใน token ให้ตรวจ
    if (isset($decoded->role) && $decoded->role !== 'teacher') {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "สิทธิ์ไม่เพียงพอ"]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Token ไม่ถูกต้อง: " . $e->getMessage()]);
    exit;
}

// ---------- [2] รับข้อมูล ----------
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "รูปแบบข้อมูลไม่ถูกต้อง"]);
    exit;
}

$required = ['question_id', 'question_text', 'choices', 'correct_choice', 'difficulty'];
foreach ($required as $k) {
    if (!array_key_exists($k, $data)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "ข้อมูลที่ส่งมาไม่ครบถ้วน ($k)"]);
        exit;
    }
}

$questionId    = (int)$data['question_id'];
$questionText  = trim((string)$data['question_text']);
$choices       = $data['choices'];
$correctChoice = strtoupper(trim((string)$data['correct_choice']));
$difficulty    = (float)$data['difficulty'];

// ---------- [3] Validation ----------
$validDifficulties = [0.15, 0.5, 0.85];
if (!in_array($difficulty, $validDifficulties, true)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ค่าความยากต้องเป็น 0.15, 0.5 หรือ 0.85 เท่านั้น"]);
    exit;
}

if (!is_array($choices) || count($choices) < 2) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ต้องมีตัวเลือกอย่างน้อย 2 ข้อ"]);
    exit;
}

$validLabels = ['A', 'B', 'C', 'D', 'E'];
$normalizedChoices = [];
foreach ($choices as $label => $content) {
    $lbl = strtoupper(trim((string)$label));
    if (!in_array($lbl, $validLabels, true)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "พบ label ไม่ถูกต้อง: " . htmlspecialchars($lbl)]);
        exit;
    }
    $normalizedChoices[$lbl] = trim((string)$content);
}

if (!array_key_exists($correctChoice, $normalizedChoices)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ตัวเลือกคำตอบที่ถูกต้องต้องอยู่ในชุดตัวเลือกที่ให้มา"]);
    exit;
}

// ---------- [4] อัปเดตฐานข้อมูล ----------
try {
    // ตั้งค่า PDO error mode (ถ้ายังไม่ได้ตั้งใน db.php)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->beginTransaction();

    // *** จุดแก้หลัก: ใช้ item_difficulty แทน difficulty ***
    $sqlUpdateQuestion = "UPDATE question 
                          SET question_text = ?, correct_choice = ?, item_difficulty = ?
                          WHERE question_id = ?";
    $stmtQuestion = $pdo->prepare($sqlUpdateQuestion);
    $stmtQuestion->execute([$questionText, $correctChoice, $difficulty, $questionId]);

    // UPSERT choices (ต้องมี UNIQUE KEY (question_id, label) หรือ PK คู่)
    $sqlUpsertChoice = "INSERT INTO choice (question_id, label, content)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE content = VALUES(content)";
    $stmtChoice = $pdo->prepare($sqlUpsertChoice);

    foreach ($normalizedChoices as $label => $content) {
        $stmtChoice->execute([$questionId, $label, $content]);
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "แก้ไขคำถามสำเร็จ"]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);

    // ช่วย debug ชื่อคอลัมน์ผิดโดยเจาะจงขึ้น
    $msg = $e->getMessage();
    if (strpos($msg, 'Unknown column') !== false) {
        $msg .= " (ตรวจชื่อคอลัมน์ในตาราง question/choice และ index (UNIQUE KEY) ของ choice ด้วย)";
    }
    echo json_encode(['status' => 'error', 'message' => "เกิดข้อผิดพลาด: $msg"]);
}
