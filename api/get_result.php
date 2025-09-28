<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

// เขียน log ไว้ใกล้ไฟล์นี้ (ปิดได้ภายหลัง)
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
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
            AND COLUMN_NAME  = ?
          LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function hasTable(PDO $pdo, string $table): bool
{
    $sql = "SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME   = ?
          LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/* --------- รับ session_id --------- */
$session_id = isset($_GET['session_id'])
    ? asInt($_GET['session_id'])
    : asInt($_POST['session_id'] ?? 0);

if ($session_id <= 0) out(['status' => 'error', 'message' => 'ไม่พบ session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // เตรียมรายชื่อคอลัมน์จาก examsession แบบยืดหยุ่น
    $cols = ['student_id', 'start_time', 'end_time'];
    foreach (['examset_id', 'question_ids', 'questions_answered', 'correct_count', 'score'] as $c) {
        if (hasColumn($pdo, 'examsession', $c)) $cols[] = $c;
    }
    $selectCols = 'se.' . implode(', se.', $cols);

    $sql = "SELECT $selectCols
          FROM examsession se
          WHERE se.session_id = :sid
          LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':sid' => $session_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);

    if (!$S) out(['status' => 'error', 'message' => 'ไม่พบ session'], 404);

    $student_id   = (string)($S['student_id'] ?? '');
    $examset_id   = $S['examset_id'] ?? null;
    $start_time   = $S['start_time'] ?? null;
    $end_time     = $S['end_time']   ?? null;

    // ดึงคำตอบทั้งหมด (ตามของเดิม)
    $answers = [];
    $answer_count = 0;
    if (hasTable($pdo, 'answer')) {
        $stA = $pdo->prepare("SELECT * FROM answer WHERE session_id = :sid");
        $stA->execute([':sid' => $session_id]);
        $answers = $stA->fetchAll(PDO::FETCH_ASSOC);
        $answer_count = count($answers);
    }

    // คำนวณจำนวนข้อรวม (fallback หลายทาง)
    $total_questions = 0;
    if (isset($S['questions_answered'])) {
        $total_questions = asInt($S['questions_answered']);
    }
    if ($total_questions <= 0 && !empty($S['question_ids'])) {
        $arr = json_decode($S['question_ids'], true);
        if (is_array($arr)) $total_questions = count($arr);
    }
    if ($total_questions <= 0 && $answer_count > 0) {
        // ถ้าไม่มีทั้งสอง ก็ใช้จำนวนคำตอบเป็นจำนวนข้อขั้นต่ำ
        $total_questions = $answer_count;
    }
    if ($total_questions <= 0) {
        // ค่า default เผื่อกรณีไม่มีอะไรเลย
        $total_questions = 5;
    }

    // คำนวณจำนวนข้อถูก (ลำดับความสำคัญ: correct_count ใน examsession > นับจาก answer.is_correct > แปลงจาก score)
    $correct_count = null;

    if (isset($S['correct_count'])) {
        $correct_count = asInt($S['correct_count']);
    } elseif ($answer_count > 0 && isset($answers[0]['is_correct'])) {
        // นับจากคอลัมน์ is_correct ถ้ามีในตาราง answer
        $c = 0;
        foreach ($answers as $a) if (!empty($a['is_correct'])) $c++;
        $correct_count = $c;
    } elseif (isset($S['score']) && is_numeric($S['score']) && $total_questions > 0) {
        // ถ้า score เป็นเปอร์เซ็นต์ ให้แปลงกลับเป็นข้อที่ถูก (ปัดใกล้สุด)
        $percent = (float)$S['score'];
        if ($percent >= 0 && $percent <= 100) {
            $correct_count = (int)round(($percent / 100) * $total_questions);
        }
    }

    // ถ้ายังหาไม่ได้จริง ๆ ให้ตั้งเป็น 0 เพื่อไม่ให้หน้าร่วง
    if ($correct_count === null) $correct_count = 0;

    // completed
    $completed = ($answer_count >= $total_questions);

    echo json_encode([
        'status'  => 'success',
        'message' => null,
        'data' => [
            'student_id'       => $student_id,
            'session_id'       => $session_id,
            'examset_id'       => $examset_id,        // ถ้าไม่มีคอลัมน์ จะเป็น null
            'total_questions'  => $total_questions,
            'answers'          => $answers,
            'answer_count'     => $answer_count,
            'correct_count'    => $correct_count,     // จำนวนข้อถูก (ไม่ใช่เปอร์เซ็นต์)
            'completed'        => $completed,
            'start_time'       => $start_time,
            'end_time'         => $end_time,
            // เผื่อ front อยากคำนวณคะแนนเอง
            'score'            => (isset($S['score']) && is_numeric($S['score'])) ? (float)$S['score'] : null
        ]
    ]);
} catch (Throwable $e) {
    error_log('[get_result.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
}
