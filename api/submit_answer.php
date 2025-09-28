<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

/* เขียน log ไว้ข้างไฟล์นี้ให้อ่านง่าย */
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/submit_answer_error.log');

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

/** เช็คว่าคอลัมน์มีจริงไหม (ใช้ information_schema รองรับ prepared ได้) */
function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $sql = "
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = ?
          AND COLUMN_NAME  = ?
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

/* ---------- JWT ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
$decoded = decodeToken($m[1]);
if (!$decoded) out(['status' => 'error', 'message' => 'Unauthorized'], 403);

$claims = (array)$decoded;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($role !== 'student' || $student_id === '') out(['status' => 'error', 'message' => 'Unauthorized'], 403);

/* ---------- Body ---------- */
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id         = asInt($body['session_id'] ?? 0);
$question_id        = asInt($body['question_id'] ?? 0);
$selected_choice_id = isset($body['selected_choice_id']) && $body['selected_choice_id'] !== '' ? asInt($body['selected_choice_id']) : null;
$response_time_sec  = asInt($body['response_time_sec'] ?? 0);
if ($session_id <= 0) out(['status' => 'error', 'message' => 'Missing session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    /* คอลัมน์ข้อความคำถาม */
    $qTextCol = hasColumn($pdo, 'question', 'question_text')
        ? 'question_text'
        : (hasColumn($pdo, 'question', 'content')
            ? 'content'
            : (hasColumn($pdo, 'question', 'text') ? 'text' : null));
    if (!$qTextCol) $qTextCol = "CAST(question_id AS CHAR)"; // กันพังสุดๆ

    /* คอลัมน์เฉลย: รองรับ correct_label / correct_choice (A/B/C/…) และ correct_choice_id */
    $correctLabelCol = hasColumn($pdo, 'question', 'correct_label')
        ? 'correct_label'
        : (hasColumn($pdo, 'question', 'correct_choice') ? 'correct_choice' : null);
    $correctIdCol    = hasColumn($pdo, 'question', 'correct_choice_id') ? 'correct_choice_id' : null;

    $hasDifficulty   = hasColumn($pdo, 'question', 'item_difficulty');

    /* ดึง session + slot */
    $st = $pdo->prepare("
        SELECT se.session_id,se.student_id,se.slot_id,se.start_time,se.end_time,
               se.questions_answered,se.correct_count,se.answer_pattern,se.question_ids,
               se.ability_est,se.last_difficulty,se.avg_response_time,
               s.exam_date, s.start_time AS slot_start_time, s.end_time AS slot_end_time, s.examset_id,
               es.duration_minutes, es.easy_count, es.medium_count, es.hard_count
        FROM examsession se
        JOIN exam_slots s ON s.id = se.slot_id
        LEFT JOIN examset es ON es.examset_id = s.examset_id
        WHERE se.session_id = ? AND se.student_id = ?
        LIMIT 1
    ");
    $st->execute([$session_id, $student_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);
    if (!$S) out(['status' => 'error', 'message' => 'Session not found'], 404);
    if (!empty($S['end_time'])) out(['status' => 'finished', 'message' => 'Session already ended'], 200);

    /* เวลาอนุญาต */
    $examStart = $S['exam_date'] . ' ' . $S['slot_start_time'];
    $slotEnd   = $S['exam_date'] . ' ' . $S['slot_end_time'];
    $durMin    = asInt($S['duration_minutes'] ?? 0);
    $calcEnd   = $durMin > 0 ? date('Y-m-d H:i:s', strtotime("$examStart +{$durMin} minutes")) : $slotEnd;
    $allowedEndTs = min(strtotime($slotEnd), strtotime($calcEnd));
    $nowTs        = time();
    $timeRemaining = max(0, $allowedEndTs - $nowTs);
    if ($timeRemaining <= 0 || $nowTs >= $allowedEndTs) {
        $qa = asInt($S['questions_answered'] ?? 0);
        $cc = asInt($S['correct_count'] ?? 0);
        $score = $qa > 0 ? round(($cc / $qa) * 100, 2) : null;

        if (hasColumn($pdo, 'examsession', 'score')) {
            $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
                ->execute([':sc' => $score, ':sid' => $session_id]);
        } else {
            $pdo->prepare("UPDATE examsession SET end_time=NOW() WHERE session_id=:sid")
                ->execute([':sid' => $session_id]);
        }
        out(['status' => 'finished', 'message' => 'Time is over', 'score' => $score, 'time_remaining' => 0]);
    }

    /* state เดิม */
    $maxQ = asInt(($S['easy_count'] ?? 0) + ($S['medium_count'] ?? 0) + ($S['hard_count'] ?? 0));
    if ($maxQ <= 0) $maxQ = 15;

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

    /* ===== ถ้าผู้ใช้ “ส่งคำตอบ” ===== */
    if ($selected_choice_id !== null) {
        if ($question_id <= 0) out(['status' => 'error', 'message' => 'Missing question_id'], 400);

        $cSt = $pdo->prepare("SELECT choice_id,question_id,label FROM choice WHERE choice_id=? LIMIT 1");
        $cSt->execute([$selected_choice_id]);
        $C = $cSt->fetch(PDO::FETCH_ASSOC);
        if (!$C) out(['status' => 'error', 'message' => 'Choice not found'], 404);
        if (asInt($C['question_id']) !== $question_id) out(['status' => 'error', 'message' => 'Choice does not belong to question'], 400);

        $cols = "question_id";
        if ($hasDifficulty)    $cols .= ",item_difficulty";
        if ($correctLabelCol)  $cols .= ",$correctLabelCol";     // correct_label หรือ correct_choice
        if ($correctIdCol)     $cols .= ",$correctIdCol";        // correct_choice_id

        $qSt = $pdo->prepare("SELECT $cols FROM question WHERE question_id=? LIMIT 1");
        $qSt->execute([$question_id]);
        $Q = $qSt->fetch(PDO::FETCH_ASSOC);
        if (!$Q) out(['status' => 'error', 'message' => 'Question not found'], 404);

        // ตัดสินถูก/ผิด (รองรับ 3 แบบ)
        $isCorrect = false;
        if ($correctLabelCol) {
            $isCorrect = strcasecmp((string)$Q[$correctLabelCol], (string)$C['label']) === 0;
        } elseif ($correctIdCol) {
            $isCorrect = (asInt($Q[$correctIdCol]) === $selected_choice_id);
        }

        $questionsAnswered++;
        if ($isCorrect) $correctCount++;
        $answerPattern .= $isCorrect ? '1' : '0';
        if (!in_array($question_id, $answeredIds, true)) $answeredIds[] = $question_id;

        if ($response_time_sec > 0) {
            $avgResp = $avgResp <= 0
                ? $response_time_sec
                : (int)round(($avgResp * ($questionsAnswered - 1) + $response_time_sec) / $questionsAnswered);
        }
        $lastDiff = $hasDifficulty ? (float)($Q['item_difficulty'] ?? 0) : 0.0;
        $ability  = max(-3, min(3, $ability + ($isCorrect ? 0.5 : -0.5)));

        /* อัปเดตแบบ dynamic ตามคอลัมน์ที่มีจริง */
        $sets = [];
        $params = [':sid' => $session_id];
        foreach (
            [
                'questions_answered' => [$questionsAnswered, ':qa'],
                'correct_count'      => [$correctCount,      ':cc'],
                'answer_pattern'     => [$answerPattern,     ':pat'],
                'question_ids'       => [json_encode(array_values($answeredIds)), ':qids'],
                'ability_est'        => [$ability,          ':ab'],
                'last_difficulty'    => [$lastDiff,         ':ld'],
                'avg_response_time'  => [$avgResp,          ':avg'],
            ] as $col => [$val, $ph]
        ) {
            if (hasColumn($pdo, 'examsession', $col)) {
                $sets[] = "$col = $ph";
                $params[$ph] = $val;
            }
        }
        if ($sets) {
            $pdo->prepare("UPDATE examsession SET " . implode(',', $sets) . " WHERE session_id=:sid")->execute($params);
        }

        /* ครบจำนวนข้อ → จบสอบ */
        if ($questionsAnswered >= $maxQ) {
            $score = round(($correctCount / max(1, $questionsAnswered)) * 100, 2);
            if (hasColumn($pdo, 'examsession', 'score')) {
                $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
                    ->execute([':sc' => $score, ':sid' => $session_id]);
            } else {
                $pdo->prepare("UPDATE examsession SET end_time=NOW() WHERE session_id=:sid")
                    ->execute([':sid' => $session_id]);
            }
            out(['status' => 'finished', 'score' => $score, 'time_remaining' => $timeRemaining]);
        }
    }

    /* ===== เลือกข้อถัดไป ===== */
    // รับ prev_question_id เผื่อกันซ้ำข้อล่าสุดแบบทันที (จาก client)
    $prev_qid = asInt($body['prev_question_id'] ?? 0);

    // map item_difficulty 0..1 -> บัคเก็ต -1/0/1 (ง่าย/กลาง/ยาก)
    $diffExpr = '(CASE WHEN q.item_difficulty <= 0.33 THEN -1
                   WHEN q.item_difficulty <= 0.66 THEN  0
                   ELSE 1 END)';

    $bucketCond = '1=1';
    if ($hasDifficulty) {
        if ($ability >=  0.5)      $bucketCond = "$diffExpr >= 0";  // กลาง/ยาก
        elseif ($ability <= -0.5)  $bucketCond = "$diffExpr <= 0";  // ง่าย/กลาง
        else                       $bucketCond = "$diffExpr = 0";   // กลาง
    }

    // สร้างรายการที่จะ exclude: ข้อที่ตอบไปแล้ว + ข้อก่อนหน้าทันที
    $excludeIds = $answeredIds;
    if ($prev_qid > 0) $excludeIds[] = $prev_qid;

    // สร้าง placeholder สำหรับ NOT IN
    $excludeSql = '';
    $params = [];
    if (count($excludeIds) > 0) {
        $excludeSql = ' AND q.question_id NOT IN (' . implode(',', array_fill(0, count($excludeIds), '?')) . ')';
        $params = array_map('intval', $excludeIds);
    }

    // ฟิลด์ข้อความคำถาม
    $qTextSelect = ($qTextCol === "CAST(question_id AS CHAR)")
        ? "$qTextCol AS question_text"
        : "q.$qTextCol AS question_text";

    // 1) พยายามสุ่มจาก bucket เป้าหมายก่อน
    $sql1 = "SELECT q.question_id, $qTextSelect
         FROM question q
         WHERE $bucketCond $excludeSql
         ORDER BY RAND()
         LIMIT 1";
    $st = $pdo->prepare($sql1);
    $st->execute($params);
    $Qn = $st->fetch(PDO::FETCH_ASSOC);

    // 2) ถ้าไม่เจอ ให้สุ่มจากคลังที่ยังไม่เคยให้เลย
    if (!$Qn) {
        $sql2 = "SELECT q.question_id, $qTextSelect
             FROM question q
             WHERE 1=1 $excludeSql
             ORDER BY RAND()
             LIMIT 1";
        $st2 = $pdo->prepare($sql2);
        $st2->execute($params);
        $Qn = $st2->fetch(PDO::FETCH_ASSOC);
    }

    // 3) ถ้ายังไม่เหลือจริง ๆ → จบสอบ
    if (!$Qn) {
        $score = round(($correctCount / max(1, $questionsAnswered)) * 100, 2);
        if (hasColumn($pdo, 'examsession', 'score')) {
            $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
                ->execute([':sc' => $score, ':sid' => $session_id]);
        } else {
            $pdo->prepare("UPDATE examsession SET end_time=NOW() WHERE session_id=:sid")
                ->execute([':sid' => $session_id]);
        }
        out([
            'status'         => 'finished',
            'message'        => 'No more questions',
            'score'          => $score,
            'time_remaining' => $timeRemaining,
            'max_questions'  => $maxQ,
            'total_questions' => $maxQ,
            'answered_count'  => $questionsAnswered
        ]);
    }

    // ดึงตัวเลือก
    $ch = $pdo->prepare("SELECT choice_id,label,content FROM choice WHERE question_id=? ORDER BY label ASC");
    $ch->execute([$Qn['question_id']]);
    $choices = array_map(fn($r) => [
        'choice_id'   => (int)$r['choice_id'],
        'label'       => (string)$r['label'],
        'choice_text' => (string)($r['content'] ?? ''),
    ], $ch->fetchAll(PDO::FETCH_ASSOC));

    // ส่งกลับ (แนบ max_questions เพื่อให้ progress bar ตรง)
    out([
        'status'         => 'continue',
        'time_remaining' => $timeRemaining,
        'max_questions'  => $maxQ,
        'question'       => [
            'question_id'   => (int)$Qn['question_id'],
            'question_text' => (string)$Qn['question_text'],
            'choices'       => $choices
        ],
        'total_questions' => $maxQ,
        'answered_count' => $questionsAnswered
    ]);


    // ตัวเลือกของคำถาม
    $ch = $pdo->prepare("SELECT choice_id,label,content FROM choice WHERE question_id=? ORDER BY label ASC");
    $ch->execute([$Qn['question_id']]);
    $choices = array_map(fn($r) => [
        'choice_id'   => (int)$r['choice_id'],
        'label'       => (string)$r['label'],
        'choice_text' => (string)($r['content'] ?? ''),
    ], $ch->fetchAll(PDO::FETCH_ASSOC));

    out([
        'status'         => 'continue',
        'time_remaining' => $timeRemaining,
        'question'       => [
            'question_id'   => (int)$Qn['question_id'],
            'question_text' => (string)$Qn['question_text'],
            'choices'       => $choices
        ],
        'total_questions' => $maxQ,
        'answered_count' => $questionsAnswered
    ]);
} catch (Throwable $e) {
    error_log('[submit_answer.php] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
