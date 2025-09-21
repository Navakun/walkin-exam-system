<?php
// get_adaptive_question.php
// สำหรับการสอบแบบ Adaptive CAT

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0'); error_reporting(E_ALL);

require_once '../config/db.php';
require_once '../vendor/autoload.php';
require_once 'helpers/jwt_helper.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function fail(int $code, string $msg, array $extra = []): void {
  http_response_code($code);
  echo json_encode(['status' => 'error', 'message' => $msg] + $extra);
  exit;
}

function ok(array $payload): void {
  echo json_encode(['status' => 'success'] + $payload);
  exit;
}

// ตรวจสอบ JWT
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
  fail(401, 'Unauthorized');
}
$token = $matches[1];
$decoded = decodeToken($token);
if (!$decoded || !isset($decoded->student_id)) {
  fail(401, 'Unauthorized');
}
$student_id = (int)$decoded->student_id;

// รับ session_id
$input = json_decode(file_get_contents('php://input'), true);
$session_id = isset($input['session_id']) ? (int)$input['session_id'] : 0;
if ($session_id <= 0) fail(400, 'Session ID ไม่ถูกต้อง');

// ตรวจสอบ session
$st = $pdo->prepare("SELECT * FROM examsession WHERE session_id = ? AND student_id = ?");
$st->execute([$session_id, $student_id]);
$session = $st->fetch(PDO::FETCH_ASSOC);
if (!$session) fail(403, 'ไม่พบ session หรือไม่มีสิทธิ์เข้าถึง');

// ดึงคำตอบก่อนหน้า
$answers = $pdo->prepare("SELECT a.question_id, q.item_difficulty, a.selected_choice, c.is_correct
  FROM answer a
  JOIN question q ON a.question_id = q.question_id
  LEFT JOIN choice c ON a.question_id = c.question_id AND a.selected_choice = c.choice_id
  WHERE a.session_id = ?");
$answers->execute([$session_id]);
$records = $answers->fetchAll(PDO::FETCH_ASSOC);

$answered_ids = []; $correct = 0; $total = 0; $sum_difficulty = 0.0;
foreach ($records as $r) {
  if (!empty($r['selected_choice'])) {
    $answered_ids[] = (int)$r['question_id'];
    $total++;
    $sum_difficulty += floatval($r['item_difficulty']);
    if ($r['is_correct']) $correct++;
  }
}

// คำนวนระดับความยากข้อถัดไป
$avg_difficulty = $total > 0 ? $sum_difficulty / $total : 0.5;

if ($total > 0) {
  if ($correct / $total > 0.8) {
    $avg_difficulty += 0.15;
  } elseif ($correct / $total < 0.5) {
    $avg_difficulty -= 0.15;
  }
}

$avg_difficulty = max(0.0, min(1.0, $avg_difficulty));

// หา question ถัดไปตามระดับความยาก
$st = $pdo->prepare("SELECT question_id, question_text, item_difficulty FROM question
  WHERE item_difficulty IS NOT NULL
    AND ABS(item_difficulty - :target) <= 0.2
    AND question_id NOT IN (" . implode(',', $answered_ids ?: [0]) . ")
  ORDER BY ABS(item_difficulty - :target), RAND()
  LIMIT 1");
$st->execute([':target' => $avg_difficulty]);
$question = $st->fetch(PDO::FETCH_ASSOC);

if (!$question) fail(404, 'ไม่มีคำถามที่เหมาะสมเหลืออยู่');

// ดึง choices
$st = $pdo->prepare("SELECT choice_id, label, content FROM choice WHERE question_id = ? ORDER BY label ASC");
$st->execute([$question['question_id']]);
$choices = $st->fetchAll(PDO::FETCH_ASSOC);

ok([
  'question' => [
    'question_id'   => (int)$question['question_id'],
    'question_text' => $question['question_text'],
    'choices'       => array_map(fn($c) => [
      'choice_id'   => (int)$c['choice_id'],
      'choice_text' => $c['content']
    ], $choices),
    'item_difficulty' => floatval($question['item_difficulty'])
  ]
]);
