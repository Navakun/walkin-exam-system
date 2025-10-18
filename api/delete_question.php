<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

// ---- Auth (ต้องเป็นอาจารย์) ----
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
if (!preg_match('/bearer\s+(\S+)/i', $hdrs['authorization'] ?? '', $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token']);
    exit;
}
$claims = decodeToken($m[1]);
if (!$claims || !in_array(strtolower($claims['role'] ?? ''), ['teacher', 'instructor'], true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

// ---- Input ----
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$question_id = isset($body['question_id']) ? (int)$body['question_id'] : 0;
if ($question_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'question_id is required']);
    exit;
}

try {
    // เปิดทรานแซกชัน
    $pdo->beginTransaction();

    // 1) ลบตัวเลือกก่อน (หาก FK ยังไม่ได้ตั้ง ON DELETE CASCADE)
    // ถ้าตาราง choice มี FK ON DELETE CASCADE แล้ว บรรทัดนี้ไม่จำเป็น แต่ไม่เสียหาย
    $stmtC = $pdo->prepare('DELETE FROM choice WHERE question_id = :qid');
    $stmtC->execute([':qid' => $question_id]);

    // 2) ลบคำถาม (ชื่อตาราง = question ตัวเล็ก)
    $stmtQ = $pdo->prepare('DELETE FROM question WHERE question_id = :qid');
    $stmtQ->execute([':qid' => $question_id]);

    // ตรวจว่ามีการลบจริงหรือไม่
    if ($stmtQ->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Question not found']);
        exit;
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'ลบคำถามเรียบร้อย', 'question_id' => $question_id], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'SERVER_ERROR', 'debug' => $e->getMessage()]);
}
