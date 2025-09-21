<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';
require_once '../vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// ===== [1] ตรวจสอบ JWT =====
function getBearerToken()
{
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) return null;
    if (!preg_match('/Bearer\s+(\S+)/', $headers['Authorization'], $matches)) return null;
    return $matches[1];
}

$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing token']);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key("your-secret-key", 'HS256'));
    if (!isset($decoded->role) || $decoded->role !== 'student') {
        throw new Exception('Access denied');
    }
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception('Missing student_id');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// ===== [2] รับ examset_id =====
$input = json_decode(file_get_contents('php://input'), true);
$examset_id = (int)($input['examset_id'] ?? 0);
if (!$examset_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing examset_id']);
    exit;
}

// ===== [3] ตรวจสอบสิทธิ์ลงทะเบียนและการชำระเงิน =====
$stmt = $pdo->prepare('
    SELECT esr.id, esr.payment_status
    FROM exam_slot_registrations esr
    JOIN examset es ON es.id = ?
    JOIN exam_slots s ON s.id = es.slot_id
    WHERE esr.student_id = ? AND esr.slot_id = s.id
    LIMIT 1
');
$stmt->execute([$examset_id, $student_id]);
$registration = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$registration) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'คุณยังไม่ได้ลงทะเบียนสอบ']);
    exit;
}

if ($registration['payment_status'] !== 'free' && $registration['payment_status'] !== 'paid') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ยังไม่ได้ชำระเงิน']);
    exit;
}

// ===== [4] ตรวจสอบว่ามี session ค้างอยู่ไหม =====
$stmt = $pdo->prepare('
    SELECT session_id 
    FROM examsession 
    WHERE student_id = ? AND examset_id = ? AND end_time IS NULL
');
$stmt->execute([$student_id, $examset_id]);
if ($existing = $stmt->fetchColumn()) {
    echo json_encode(['status' => 'ok', 'message' => 'Session already exists', 'session_id' => $existing]);
    exit;
}

// ===== [5] ดึง config =====
$stmt = $pdo->prepare('SELECT easy_count, medium_count, hard_count FROM exam_config WHERE id = 1 LIMIT 1');
$stmt->execute();
$config = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$config) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Exam config not found']);
    exit;
}

// ===== [6] สุ่มคำถาม =====
function fetchQuestions($pdo, $condition, $limit)
{
    $stmt = $pdo->prepare("
        SELECT question_id 
        FROM question 
        WHERE $condition 
        ORDER BY RAND() 
        LIMIT $limit
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$easy = fetchQuestions($pdo, 'item_difficulty < 0', (int)$config['easy_count']);
$medium = fetchQuestions($pdo, 'item_difficulty = 0', (int)$config['medium_count']);
$hard = fetchQuestions($pdo, 'item_difficulty > 0', (int)$config['hard_count']);
$question_ids = array_merge($easy, $medium, $hard);
shuffle($question_ids);

if (count($question_ids) < 5) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'จำนวนคำถามไม่พอ']);
    exit;
}

// ===== [7] บันทึกลง examsession =====
$stmt = $pdo->prepare('
    INSERT INTO examsession (student_id, examset_id, start_time, question_ids) 
    VALUES (?, ?, NOW(), ?)
');
$stmt->execute([$student_id, $examset_id, json_encode($question_ids)]);
$session_id = $pdo->lastInsertId();

echo json_encode([
    'status' => 'ok',
    'session_id' => $session_id,
    'question_ids' => $question_ids
]);
