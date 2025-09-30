<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/get_result_error.log');

require_once __DIR__ . '/../config/db.php';

function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}
function asInt($v): int
{
    return (int)$v;
}

function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $sql = "SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function hasTable(PDO $pdo, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/* --------- input --------- */
$session_id = isset($_GET['session_id'])
    ? asInt($_GET['session_id'])
    : asInt($_POST['session_id'] ?? 0);

if ($session_id <= 0) out(['status' => 'error', 'message' => 'ไม่พบ session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // เตรียมคอลัมน์แบบยืดหยุ่น
    $cols = ['student_id', 'start_time', 'end_time'];
    foreach (['examset_id', 'question_ids', 'questions_answered', 'correct_count', 'score'] as $c) {
        if (hasColumn($pdo, 'examsession', $c)) $cols[] = $c;
    }

    $st = $pdo->prepare("SELECT se." . implode(', se.', $cols) . "
                         FROM examsession se WHERE se.session_id = :sid LIMIT 1");
    $st->execute([':sid' => $session_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);
    if (!$S) out(['status' => 'error', 'message' => 'ไม่พบ session'], 404);

    $student_id = (string)($S['student_id'] ?? '');
    $examset_id = $S['examset_id'] ?? null;
    $start_time = $S['start_time'] ?? null;
    $end_time   = $S['end_time']   ?? null;

    // ดึงคำตอบ
    $answers = [];
    if (hasTable($pdo, 'answer')) {
        $stA = $pdo->prepare("SELECT * FROM answer WHERE session_id = :sid ORDER BY answer_id");
        $stA->execute([':sid' => $session_id]);
        $answers = $stA->fetchAll(PDO::FETCH_ASSOC);
    }
    $answer_count = count($answers);

    // จำนวนข้อรวม: question_ids > questions_answered > count(answers) > default
    $total_questions = 0;
    if (!empty($S['question_ids'])) {
        $arr = json_decode((string)$S['question_ids'], true);
        if (is_array($arr)) $total_questions = count($arr);
    }
    if ($total_questions <= 0 && isset($S['questions_answered'])) {
        $total_questions = asInt($S['questions_answered']);
    }
    if ($total_questions <= 0 && $answer_count > 0) {
        $total_questions = $answer_count;
    }
    if ($total_questions <= 0) {
        $total_questions = 5; // fallback
    }

    // ✅ นับถูก: answers > examsession.correct_count > เดาจาก score
    $correct_count = null;

    if ($answer_count > 0 && isset($answers[0]['is_correct'])) {
        // นับจากคำตอบล่าสุดที่มี is_correct
        $c = 0;
        foreach ($answers as $a) {
            if ((int)($a['is_correct'] ?? 0) === 1) $c++;
        }
        $correct_count = $c;
    } elseif (array_key_exists('correct_count', $S)) {
        // ใช้ค่าที่เซสชันสะสมไว้ (ถ้ามี)
        $correct_count = (int)$S['correct_count'];
    } elseif (isset($S['score']) && is_numeric($S['score']) && $total_questions > 0) {
        // เดาจาก score: ถ้าเป็นจำนวนข้อถูก (<= total_questions) ให้ใช้ตรงๆ
        // ถ้าไม่ใช่ ให้ตีความเป็นเปอร์เซ็นต์ 0..100
        $score = (float)$S['score'];
        if ($score >= 0 && $score <= $total_questions && fmod($score, 1.0) === 0.0) {
            $correct_count = (int)$score;  // จำนวนข้อถูก
        } elseif ($score >= 0 && $score <= 100) {
            $correct_count = (int)round(($score / 100) * $total_questions);  // เปอร์เซ็นต์
        } else {
            $correct_count = 0;
        }
    }

    // กัน null
    if ($correct_count === null) $correct_count = 0;

    // (ถ้าต้องใช้)
    $wrong_count = max(0, $total_questions - $correct_count);

    // สถานะสอบเสร็จ: ถ้ามี end_time ถือว่าเสร็จ; ไม่งั้นเช็คจำนวนคำตอบ
    $completed = !empty($end_time) ? true : ($answer_count >= $total_questions);

    // duration (มิลลิวินาที)
    $duration_ms = null;
    if (!empty($start_time) && !empty($end_time)) {
        $stDt = new DateTime($start_time, new DateTimeZone('Asia/Bangkok'));
        $enDt = new DateTime($end_time,   new DateTimeZone('Asia/Bangkok'));
        $duration_ms = max(0, ($enDt->getTimestamp() - $stDt->getTimestamp()) * 1000);
    }

    out([
        'status'  => 'success',
        'message' => null,
        'data' => [
            'student_id'      => $student_id,
            'session_id'      => $session_id,
            'examset_id'      => $examset_id,
            'total_questions' => $total_questions,
            'answers'         => $answers,
            'answer_count'    => $answer_count,
            'correct_count'   => $correct_count,   // จำนวนข้อที่ถูกต้อง
            'completed'       => $completed,
            'start_time'      => $start_time,
            'end_time'        => $end_time,
            'duration_ms'     => $duration_ms,
            'score'           => (isset($S['score']) && is_numeric($S['score'])) ? (float)$S['score'] : null
        ]
    ]);
} catch (Throwable $e) {
    error_log('[get_result.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
}
