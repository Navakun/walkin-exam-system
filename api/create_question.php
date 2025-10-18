<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/db.php';

/* ========== 1) ตรวจ JWT ========== */
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (empty($jwt_key)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'JWT key not configured']);
    exit;
}
if (!$authHeader || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่ได้ส่ง Token']);
    exit;
}
try {
    $decoded = JWT::decode($m[1], new Key($jwt_key, 'HS256'));
    // ถ้าต้องการบังคับ role เป็นอาจารย์ ให้แก้ตรงนี้
    // $role = strtolower($decoded->role ?? '');
    // if (!in_array($role, ['teacher','instructor'], true)) { ... }
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง: ' . $e->getMessage()]);
    exit;
}

/* ========== 2) รับอินพุต ========== */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'รูปแบบข้อมูลไม่ใช่ JSON', 'debug_raw' => $raw]);
    exit;
}

$questionText    = trim((string)($data['question_text'] ?? ''));
$choices         = $data['choices'] ?? null;     // คาดรูปแบบ: { "A":"...", "B":"...", "C":"...", "D":"..." }
$correctChoice   = strtoupper(trim((string)($data['correct_choice'] ?? '')));
$difficultyLevel = $data['difficulty_level'] ?? null; // 0.150 / 0.500 / 0.850

if (
    $questionText === '' ||
    !is_array($choices) ||
    !isset($choices['A'], $choices['B'], $choices['C'], $choices['D']) ||
    !in_array($correctChoice, ['A', 'B', 'C', 'D'], true)
) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ข้อมูลที่ส่งมาไม่ครบถ้วน']);
    exit;
}

// ปรับค่า difficulty ให้เป็นตัวเลขที่คาดหวัง
$difficulty = is_numeric($difficultyLevel) ? (float)$difficultyLevel : 0.500;

/* ========== 3) บันทึกลงฐานข้อมูล (ใช้ชื่อตาราง/คอลัมน์ตัวเล็กให้ตรง DB) ========== */
try {
    $pdo->beginTransaction();

    // ตาราง: question (ไม่ใช่ Question)
    $sqlQ = "INSERT INTO question (question_text, correct_choice, item_difficulty, total_attempts, correct_attempts, created_at)
             VALUES (:qt, :cc, :diff, 0, 0, NOW())";
    $stmtQ = $pdo->prepare($sqlQ);
    $stmtQ->execute([
        ':qt'   => $questionText,
        ':cc'   => $correctChoice,
        ':diff' => $difficulty,
    ]);
    $question_id = (int)$pdo->lastInsertId();

    // ตาราง: choice (ไม่ใช่ Choice) / คอลัมน์: choice_label, choice_text
    $sqlC = "INSERT INTO choice (question_id, choice_label, choice_text) VALUES (:qid, :lbl, :txt)";
    $stmtC = $pdo->prepare($sqlC);

    foreach (['A', 'B', 'C', 'D'] as $lbl) {
        $txt = trim((string)$choices[$lbl]);
        $stmtC->execute([
            ':qid' => $question_id,
            ':lbl' => $lbl,
            ':txt' => $txt,
        ]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'เพิ่มคำถามสำเร็จ', 'question_id' => $question_id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
}
