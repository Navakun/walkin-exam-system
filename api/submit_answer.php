<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

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
    $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $st->execute([$col]);
    return (bool)$st->fetchColumn();
}

$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
$jwt = $m[1];
$decoded = decodeToken($jwt);
if (!$decoded || (($decoded->role ?? '') !== 'student')) out(['status' => 'error', 'message' => 'Unauthorized'], 403);
$student_id = (string)($decoded->student_id ?? '');
if ($student_id === '') out(['status' => 'error', 'message' => 'Missing student_id'], 403);

// body
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id         = asInt($body['session_id'] ?? 0);
$question_id        = asInt($body['question_id'] ?? 0);
$selected_choice_id = isset($body['selected_choice_id']) && $body['selected_choice_id'] !== '' ? asInt($body['selected_choice_id']) : null;
$response_time_sec  = asInt($body['response_time_sec'] ?? 0);
if ($session_id <= 0) out(['status' => 'error', 'message' => 'Missing session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // detect columns in question table (ความยืดหยุ่นเรื่องข้อความ/เฉลย)
    $qHasTextCol   = hasColumn($pdo, 'question', 'question_text') ? 'question_text'
        : (hasColumn($pdo, 'question', 'content') ? 'content' : null);
    $qHasCorrectLbl = hasColumn($pdo, 'question', 'correct_label');
    $qHasCorrectId = hasColumn($pdo, 'question', 'correct_choice_id');
    if (!$qHasCorrectLbl && !$qHasCorrectId) {
        out(['status' => 'error', 'message' => 'ไม่พบคอลัมน์เฉลยในตาราง question (ต้องมี correct_label หรือ correct_choice_id)'], 500);
    }

    // ดึง session + slot + examset
    $st = $pdo->prepare("
    SELECT
      se.session_id, se.student_id, se.slot_id, se.start_time, se.end_time,
      se.questions_answered, se.correct_count, se.answer_pattern, se.question_ids,
      se.ability_est, se.last_difficulty, se.avg_response_time,
      s.exam_date, s.start_time AS slot_start_time, s.end_time AS slot_end_time, s.examset_id,
      es.duration_minutes, es.easy_count, es.medium_count, es.hard_count
    FROM examsession se
    JOIN exam_slots s    ON s.id = se.slot_id
    LEFT JOIN examset es ON es.examset_id = s.examset_id
    WHERE se.session_id = ? AND se.student_id = ?
    LIMIT 1
  ");
    $st->execute([$session_id, $student_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);
    if (!$S) out(['status' => 'error', 'message' => 'Session not found'], 404);
    if (!empty($S['end_time'])) out(['status' => 'finished', 'message' => 'Session already ended'], 200);

    // เวลา
    $examStartStr = $S['exam_date'] . ' ' . $S['slot_start_time'];
    $slotEndStr   = $S['exam_date'] . ' ' . $S['slot_end_time'];
    $durMin       = asInt($S['duration_minutes'] ?? 0);
    $calcByDurationEnd = $durMin > 0 ? date('Y-m-d H:i:s', strtotime("$examStartStr +{$durMin} minutes")) : $slotEndStr;
    $allowedEndTs = min(strtotime($slotEndStr), strtotime($calcByDurationEnd));
    $nowTs        = time();
    $timeRemaining = max(0, $allowedEndTs - $nowTs);
    if ($timeRemaining <= 0 || $nowTs >= $allowedEndTs) {
        $qa = asInt($S['questions_answered'] ?? 0);
        $cc = asInt($S['correct_count'] ?? 0);
        $score = $qa > 0 ? round(($cc / $qa) * 100, 2) : null;
        $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
            ->execute([':sc' => $score, ':sid' => $session_id]);
        out(['status' => 'finished', 'message' => 'Time is over', 'score' => $score, 'time_remaining' => 0]);
    }

    // config จำนวนข้อสูงสุด
    $maxQ = asInt(($S['easy_count'] ?? 0) + ($S['medium_count'] ?? 0) + ($S['hard_count'] ?? 0));
    if ($maxQ <= 0) $maxQ = 15;

    // state เดิม
    $answeredIds = [];
    if (!empty($S['question_ids'])) {
        $tmp = json_decode($S['question_ids'], true);
        if (is_array($tmp)) $answeredIds = array_map('intval', $tmp);
    }
    $questionsAnswered = asInt($S['questions_answered'] ?? 0);
    $correctCount      = asInt($S['correct_count'] ?? 0);
    $ability           = (float)($S['ability_est'] ?? 0.0);
    $answerPattern     = (string)($S['answer_pattern'] ?? '');
    $avgResp           = asInt($S['avg_response_time'] ?? 0);

    // ===== ถ้าผู้ใช้ "ส่งคำตอบ"
    if ($selected_choice_id !== null) {
        if ($question_id <= 0) out(['status' => 'error', 'message' => 'Missing question_id'], 400);

        // ดึง choice (ใช้ schema: label, content)
        $cSt = $pdo->prepare("SELECT choice_id, question_id, label FROM choice WHERE choice_id=? LIMIT 1");
        $cSt->execute([$selected_choice_id]);
        $C = $cSt->fetch(PDO::FETCH_ASSOC);
        if (!$C) out(['status' => 'error', 'message' => 'Choice not found'], 404);
        if (asInt($C['question_id']) !== $question_id) {
            out(['status' => 'error', 'message' => 'Choice does not belong to question'], 400);
        }

        // ดึงข้อมูล question เพื่อดูเฉลย + difficulty
        // สร้าง SELECT ที่ปลอดภัยตามคอลัมน์ที่มีจริง
        $cols = "question_id,item_difficulty";
        if ($qHasCorrectLbl) $cols .= ",correct_label";
        if ($qHasCorrectId)  $cols .= ",correct_choice_id";
        $qSt = $pdo->prepare("SELECT $cols FROM question WHERE question_id=? LIMIT 1");
        $qSt->execute([$question_id]);
        $Q = $qSt->fetch(PDO::FETCH_ASSOC);
        if (!$Q) out(['status' => 'error', 'message' => 'Question not found'], 404);

        // ตัดสิน "ถูก/ผิด"
        $isCorrect = false;
        if ($qHasCorrectLbl) {
            // เทียบ label
            $isCorrect = (strcasecmp((string)$Q['correct_label'], (string)$C['label']) === 0);
        } elseif ($qHasCorrectId) {
            $isCorrect = (asInt($Q['correct_choice_id']) === $selected_choice_id);
        }

        // อัปเดตสถิติ
        $questionsAnswered++;
        if ($isCorrect) $correctCount++;
        $answerPattern .= ($isCorrect ? '1' : '0');

        if (!in_array($question_id, $answeredIds, true)) {
            $answeredIds[] = $question_id;
        }

        // ปรับ ability แบบง่าย ๆ ตามผลลัพธ์
        $k = 0.5;
        $ability += $isCorrect ? $k : -$k;
        $ability = max(-3, min(3, $ability));

        // last_difficulty (อาศัย item_difficulty ใน question)
        $lastDiff = asInt($Q['item_difficulty'] ?? 0);

        // คิดค่าเฉลี่ยเวลา
        if ($response_time_sec > 0) {
            if ($avgResp <= 0) $avgResp = $response_time_sec;
            else $avgResp = (int)round(($avgResp * ($questionsAnswered - 1) + $response_time_sec) / $questionsAnswered);
        }

        // บันทึกลง session
        $up = $pdo->prepare("
      UPDATE examsession SET
        questions_answered = :qa,
        correct_count      = :cc,
        answer_pattern     = :pat,
        question_ids       = :qids,
        ability_est        = :ab,
        last_difficulty    = :ld,
        avg_response_time  = :avg
      WHERE session_id = :sid
    ");
        $up->execute([
            ':qa' => $questionsAnswered,
            ':cc' => $correctCount,
            ':pat' => $answerPattern,
            ':qids' => json_encode(array_values($answeredIds)),
            ':ab' => $ability,
            ':ld' => $lastDiff,
            ':avg' => $avgResp,
            ':sid' => $session_id
        ]);

        // ครบจำนวนข้อ → จบสอบ
        if ($questionsAnswered >= $maxQ) {
            $score = round(($correctCount / max(1, $questionsAnswered)) * 100, 2);
            $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
                ->execute([':sc' => $score, ':sid' => $session_id]);
            out(['status' => 'finished', 'score' => $score, 'time_remaining' => $timeRemaining]);
        }
    }

    // ===== เลือก "ข้อถัดไป" ตาม ability
    $cond = 'q.item_difficulty = 0';
    if ($ability >= 0.5)      $cond = 'q.item_difficulty > 0';
    elseif ($ability <= -0.5) $cond = 'q.item_difficulty < 0';

    $excludeSql = '';
    $params = [];
    if (count($answeredIds) > 0) {
        $excludeSql = ' AND q.question_id NOT IN (' . implode(',', array_fill(0, count($answeredIds), '?')) . ')';
        $params = $answeredIds;
    }

    // เตรียมคอลัมน์ข้อความคำถาม
    $qTextCol = $qHasTextCol ?: 'question_id'; // กันพัง ถ้าไม่มีคอลัมน์ข้อความ
    $sql = "
    SELECT q.question_id, q.$qTextCol AS question_text
    FROM question q
    WHERE $cond $excludeSql
    ORDER BY RAND()
    LIMIT 1
  ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $Qn = $st->fetch(PDO::FETCH_ASSOC);

    if (!$Qn) {
        // ไม่เหลือข้อให้ถาม → จบสอบ
        $score = round(($correctCount / max(1, $questionsAnswered)) * 100, 2);
        $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
            ->execute([':sc' => $score, ':sid' => $session_id]);
        out(['status' => 'finished', 'message' => 'No more questions', 'score' => $score, 'time_remaining' => $timeRemaining]);
    }

    // ดึงตัวเลือกของคำถามตาม schema choice(label,content)
    $ch = $pdo->prepare("SELECT choice_id, label, content FROM choice WHERE question_id=? ORDER BY label ASC");
    $ch->execute([$Qn['question_id']]);
    $choices = array_map(function ($r) {
        return [
            'choice_id'   => (int)$r['choice_id'],
            'label'       => (string)$r['label'],
            'choice_text' => (string)$r['content'],
        ];
    }, $ch->fetchAll(PDO::FETCH_ASSOC));

    out([
        'status'         => 'continue',
        'time_remaining' => $timeRemaining,
        'question'       => [
            'question_id'   => (int)$Qn['question_id'],
            'question_text' => (string)$Qn['question_text'],
            'choices'       => $choices
        ]
    ]);
} catch (Throwable $e) {
    error_log('[submit_answer_adaptive.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
