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

/* 1) ตรวจ JWT */
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
    // ต้องการบังคับ role = teacher ก็เพิ่มตรวจ role ตรงนี้ได้
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง: ' . $e->getMessage()]);
    exit;
}

/* 2) รับ/ตรวจอินพุต */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$questionText    = trim((string)($data['question_text'] ?? ''));
$choices         = $data['choices'] ?? null;        // {"A":"...", "B":"...", "C":"...", "D":"..."}
$correctChoice   = strtoupper(trim((string)($data['correct_choice'] ?? '')));
$difficultyLevel = $data['difficulty_level'] ?? 0.500;

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

$difficulty = is_numeric($difficultyLevel) ? (float)$difficultyLevel : 0.500;

/* 3) บันทึกลงฐานข้อมูล (ชื่อตาราง/คอลัมน์ให้ตรงกับสคีมาของคุณ) */
try {
    $pdo->beginTransaction();

    // <-- ตาราง question (ตัวเล็ก) คอลัมน์ตามสคีมาของคุณ
    $sqlQ = "INSERT INTO question (question_text, correct_choice, item_difficulty, total_attempts, correct_attempts, created_at)
             VALUES (:qt, :cc, :diff, 0, 0, NOW())";
    $stmtQ = $pdo->prepare($sqlQ);
    $stmtQ->execute([
        ':qt'   => $questionText,
        ':cc'   => $correctChoice,
        ':diff' => $difficulty,
    ]);
    $question_id = (int)$pdo->lastInsertId();

    // <-- ตาราง choice ใช้คอลัมน์ label, content (ตาม DDL ที่คุณส่งมา)
    $sqlC = "INSERT INTO choice (question_id, label, content) VALUES (:qid, :lbl, :txt)";
    $stmtC = $pdo->prepare($sqlC);
    foreach (['A', 'B', 'C', 'D'] as $lbl) {
        $stmtC->execute([
            ':qid' => $question_id,
            ':lbl' => $lbl,
            ':txt' => trim((string)$choices[$lbl]),
        ]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'เพิ่มคำถามสำเร็จ', 'question_id' => $question_id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage()]);
}
