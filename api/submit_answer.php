<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Submit-Answer-Version: 2025-10-11-0826'); // ← เวอร์ชัน
error_reporting(E_ALL);
ini_set('display_errors', '0');
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

/** information_schema helpers */
function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare("
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1
  ");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

/* ---------- DEBUG: /api/submit_answer.php?ping=1 ---------- */
if (isset($_GET['ping'])) {
    header('X-Script-Path: ' . __FILE__);
    out(['ok' => true, 'file' => __FILE__, 'time' => date('c')]);
}

/* ---------- JWT ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Missing token'], 401);
}
try {
    $decoded = decodeToken($m[1]);
} catch (Throwable $e) {
    error_log('🔑 decodeToken error: ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
if (!$decoded) out(['status' => 'error', 'message' => 'Unauthorized'], 403);

$claims     = (array)$decoded;
$role       = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($role !== 'student' || $student_id === '') out(['status' => 'error', 'message' => 'Unauthorized'], 403);

/* ---------- Body ---------- */
$body               = json_decode(file_get_contents('php://input'), true) ?: [];
$session_id         = asInt($body['session_id'] ?? 0);
$question_id        = asInt($body['question_id'] ?? 0);
$selected_choice_id = isset($body['selected_choice_id']) && $body['selected_choice_id'] !== '' ? asInt($body['selected_choice_id']) : null;
$response_time_sec  = asInt($body['response_time_sec'] ?? 0);
$prev_qid           = asInt($body['prev_question_id'] ?? 0);

if ($session_id <= 0) out(['status' => 'error', 'message' => 'Missing session_id'], 400);

try {
    // โซนเวลา + character set
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+07:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

    /* ---------- column helpers ---------- */
    $qTextCol = hasColumn($pdo, 'question', 'question_text') ? 'question_text'
        : (hasColumn($pdo, 'question', 'content') ? 'content'
            : (hasColumn($pdo, 'question', 'text') ? 'text' : null));
    if (!$qTextCol) $qTextCol = "CAST(question_id AS CHAR)";
    $hasDifficulty = hasColumn($pdo, 'question', 'item_difficulty');

    /* ---------- โหลด session (เทียบ student_id แบบ byte-for-byte) ---------- */
    $sqlSession = "
    SELECT se.session_id, se.student_id, se.slot_id, se.start_time, se.end_time,
           se.questions_answered, se.correct_count, se.answer_pattern,
           se.question_ids, se.ability_est, se.last_difficulty, se.avg_response_time,
           s.exam_date, s.start_time AS slot_start_time, s.end_time AS slot_end_time, s.examset_id,
           es.duration_minutes, es.easy_count, es.medium_count, es.hard_count
    FROM examsession se
    LEFT JOIN exam_slots s ON s.id = se.slot_id
    LEFT JOIN examset es   ON es.examset_id = s.examset_id
    WHERE se.session_id = ?
      AND CAST(se.student_id AS BINARY) = CAST(? AS BINARY)
    LIMIT 1
  ";
    $st = $pdo->prepare($sqlSession);
    $st->execute([$session_id, $student_id]);
    $S = $st->fetch(PDO::FETCH_ASSOC);
    if (!$S) out(['status' => 'error', 'message' => 'Session not found'], 404);
    if (!empty($S['end_time'])) out(['status' => 'finished', 'message' => 'Session already ended'], 200);

    /* ---------- เวลาที่อนุญาต ---------- */
    $examStart = $S['exam_date'] . ' ' . $S['slot_start_time'];
    $slotEnd   = $S['exam_date'] . ' ' . $S['slot_end_time'];
    $durMin    = asInt($S['duration_minutes'] ?? 0);
    $calcEnd   = $durMin > 0 ? date('Y-m-d H:i:s', strtotime("$examStart +{$durMin} minutes")) : $slotEnd;

    $allowedEndTs  = min(strtotime($slotEnd ?: '2099-12-31 23:59:59'), strtotime($calcEnd ?: '2099-12-31 23:59:59'));
    $nowTs         = time();
    $timeRemaining = max(0, $allowedEndTs - $nowTs);
    if ($timeRemaining <= 0) {
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

    /* ---------- จำนวนข้อทั้งหมด (มาจาก examset) ---------- */
    $maxQ = asInt(($S['easy_count'] ?? 0) + ($S['medium_count'] ?? 0) + ($S['hard_count'] ?? 0));
    if ($maxQ <= 0) $maxQ = 15; // fallback

    /* ---------- สถิติจาก answer ---------- */
    $hasIsCorrect = hasColumn($pdo, 'answer', 'is_correct');
    if ($hasIsCorrect) {
        $sqlAgg = "
      SELECT COUNT(*) AS answered, COALESCE(SUM(a.is_correct),0) AS correct
      FROM answer a
      WHERE a.session_id = ?
    ";
    } else {
        $sqlAgg = "
      SELECT COUNT(*) AS answered,
             SUM(CASE WHEN BINARY a.selected_choice = BINARY q.correct_choice THEN 1 ELSE 0 END) AS correct
      FROM answer a
      JOIN question q ON q.question_id = a.question_id
      WHERE a.session_id = ?
    ";
    }
    $agg = $pdo->prepare($sqlAgg);
    $agg->execute([$session_id]);
    $sum = $agg->fetch(PDO::FETCH_ASSOC) ?: ['answered' => 0, 'correct' => 0];
    $answered_count = (int)$sum['answered'];
    $correct_count  = (int)$sum['correct'];

    // รายการคำถามที่ตอบไปแล้ว (กัน question_id ซ้ำ)
    $ansQ = $pdo->prepare("SELECT question_id FROM answer WHERE session_id=?");
    $ansQ->execute([$session_id]);
    $answeredIds = array_map('intval', array_column($ansQ->fetchAll(PDO::FETCH_ASSOC), 'question_id'));

    // รายการ hash ของโจทย์ที่ใช้ไปแล้ว (กันข้อความซ้ำ)
    $hSt = $pdo->prepare("
    SELECT DISTINCT q.question_text_hash
    FROM answer a
    JOIN question q ON q.question_id = a.question_id
    WHERE a.session_id = ? AND q.question_text_hash IS NOT NULL
  ");
    $hSt->execute([$session_id]);
    $answeredHashes = array_values(array_filter(array_map(fn($r) => (string)$r[0], $hSt->fetchAll(PDO::FETCH_NUM))));

    $ability       = (float)($S['ability_est'] ?? 0.0);
    $answerPattern = (string)($S['answer_pattern'] ?? '');
    $avgResp       = asInt($S['avg_response_time'] ?? 0);

    /* ===================================================================
     1) ถ้ามีส่งคำตอบมา → บันทึกลง answer
     ===================================================================*/
    if ($selected_choice_id !== null) {
        if ($question_id <= 0) out(['status' => 'error', 'message' => 'Missing question_id'], 400);

        // ตรวจสอบ choice
        $cSt = $pdo->prepare("SELECT choice_id, question_id, label FROM choice WHERE choice_id = ? LIMIT 1");
        $cSt->execute([$selected_choice_id]);
        $C = $cSt->fetch(PDO::FETCH_ASSOC);
        if (!$C) out(['status' => 'error', 'message' => 'Choice not found'], 404);
        if (asInt($C['question_id']) !== $question_id) out(['status' => 'error', 'message' => 'Choice does not belong to question'], 400);

        // ดึงคำตอบที่ถูกต้องของข้อ
        $qSt = $pdo->prepare("SELECT correct_choice, " . ($hasDifficulty ? 'item_difficulty' : '0 AS item_difficulty') . " FROM question WHERE question_id=? LIMIT 1");
        $qSt->execute([$question_id]);
        $Q = $qSt->fetch(PDO::FETCH_ASSOC);
        if (!$Q) out(['status' => 'error', 'message' => 'Question not found'], 404);

        $selected_label = strtoupper(substr((string)$C['label'], 0, 1));
        $correct_label  = strtoupper(substr((string)$Q['correct_choice'], 0, 1));
        $isCorrect      = (int)(strcasecmp($selected_label, $correct_label) === 0);

        // upsert คำตอบ (มี trigger ช่วย normalize/คำนวณซ้ำอีกชั้น)
        $ins = $pdo->prepare("
      INSERT INTO answer (session_id, question_id, selected_choice, is_correct, answered_at, response_time)
      VALUES (:sid, :qid, :sel, :isc, NOW(), :rt)
      ON DUPLICATE KEY UPDATE
        selected_choice = VALUES(selected_choice),
        is_correct      = VALUES(is_correct),
        answered_at     = VALUES(answered_at),
        response_time   = VALUES(response_time)
    ");
        $ins->execute([
            ':sid' => $session_id,
            ':qid' => $question_id,
            ':sel' => $selected_label,
            ':isc' => $isCorrect,
            ':rt'  => $response_time_sec
        ]);

        // รีเฟรชสถิติ
        $agg->execute([$session_id]);
        $sum2 = $agg->fetch(PDO::FETCH_ASSOC) ?: ['answered' => 0, 'correct' => 0];
        $answered_count = (int)$sum2['answered'];
        $correct_count  = (int)$sum2['correct'];
        $score          = round($correct_count * 100.0 / max(1, $maxQ), 2);

        // ปรับสถิติ session
        $avgResp = $avgResp <= 0
            ? $response_time_sec
            : (int)round(($avgResp * max(0, $answered_count - 1) + $response_time_sec) / max(1, $answered_count));
        $answerPattern .= $isCorrect ? '1' : '0';
        $lastDiff = $hasDifficulty ? (float)($Q['item_difficulty'] ?? 0) : 0.0;
        $ability  = max(-3, min(3, $ability + ($isCorrect ? 0.5 : -0.5)));

        // sync examsession
        $sets   = [];
        $params = [':sid' => $session_id];
        foreach (
            [
                'questions_answered' => [$answered_count, ':qa'],
                'correct_count'      => [$correct_count, ':cc'],
                'answer_pattern'     => [$answerPattern, ':pat'],
                'ability_est'        => [$ability, ':ab'],
                'last_difficulty'    => [$lastDiff, ':ld'],
                'avg_response_time'  => [$avgResp, ':avg'],
                'score'              => [$score, ':sc'],
            ] as $col => [$val, $ph]
        ) {
            if (hasColumn($pdo, 'examsession', $col)) {
                $sets[] = "$col = $ph";
                $params[$ph] = $val;
            }
        }
        if ($sets) {
            $upd = $pdo->prepare("UPDATE examsession SET " . implode(',', $sets) . " WHERE session_id=:sid");
            $upd->execute($params);
        }

        // ครบจำนวนข้อ → จบสอบเลย
        if ($answered_count >= $maxQ) {
            if (hasColumn($pdo, 'examsession', 'end_time')) {
                $pdo->prepare("UPDATE examsession SET end_time=NOW() WHERE session_id=?")->execute([$session_id]);
            }
            out([
                'status' => 'finished',
                'score'  => $score,
                'answered_count' => $answered_count,
                'correct_count'  => $correct_count,
                'total_questions' => $maxQ,
                'time_remaining' => $timeRemaining
            ]);
        }

        // เก็บ id/hash ที่เพิ่งใช้
        if (!in_array($question_id, $answeredIds, true)) $answeredIds[] = $question_id;
        $h2 = $pdo->prepare("SELECT question_text_hash FROM question WHERE question_id=?");
        $h2->execute([$question_id]);
        $ph = (string)$h2->fetchColumn();
        if ($ph) {
            $answeredHashes[] = $ph;
            $answeredHashes = array_values(array_unique($answeredHashes));
        }
    }

    // ถ้า client ลืมส่ง prev_question_id มา ให้ใช้ question_id เดิมเป็น prev_qid
    if ($selected_choice_id === null && $question_id > 0 && $prev_qid === 0) {
        $prev_qid = $question_id;
    }
    // กันรีเฟรช/ย้อนทันที: กัน hash ของ prev_qid ด้วย
    if ($prev_qid > 0) {
        $h2 = $pdo->prepare("SELECT question_text_hash FROM question WHERE question_id=?");
        $h2->execute([$prev_qid]);
        $ph = (string)$h2->fetchColumn();
        if ($ph) {
            $answeredHashes[] = $ph;
            $answeredHashes = array_values(array_unique($answeredHashes));
        }
    }

    /* ---------- นับโควตาความยากแบบไม่ซ้ำข้อความ ---------- */
    $diffCountStmt = $pdo->prepare("
    SELECT
      COUNT(DISTINCT CASE WHEN q.item_difficulty <= 0.33 THEN q.question_text_hash END) AS e_cnt,
      COUNT(DISTINCT CASE WHEN q.item_difficulty >  0.33 AND q.item_difficulty <= 0.66 THEN q.question_text_hash END) AS m_cnt,
      COUNT(DISTINCT CASE WHEN q.item_difficulty >  0.66 THEN q.question_text_hash END) AS h_cnt
    FROM answer a
    JOIN question q ON q.question_id = a.question_id
    WHERE a.session_id = ?
  ");
    $diffCountStmt->execute([$session_id]);
    $dc = $diffCountStmt->fetch(PDO::FETCH_ASSOC) ?: ['e_cnt' => 0, 'm_cnt' => 0, 'h_cnt' => 0];

    // เป้าหมายจาก examset (0/NULL = ไม่บังคับ)
    $targetE = (int)($S['easy_count']   ?? 0);
    $targetM = (int)($S['medium_count'] ?? 0);
    $targetH = (int)($S['hard_count']   ?? 0);

    $needE = $targetE > 0 ? max(0, $targetE - (int)$dc['e_cnt']) : 0;
    $needM = $targetM > 0 ? max(0, $targetM - (int)$dc['m_cnt']) : 0;
    $needH = $targetH > 0 ? max(0, $targetH - (int)$dc['h_cnt']) : 0;

    /* ===================================================================
     2) ขอ “ข้อถัดไป”
     ===================================================================*/
    $diffExpr = '(CASE WHEN q.item_difficulty <= 0.33 THEN -1
                   WHEN q.item_difficulty <= 0.66 THEN  0
                   ELSE 1 END)';

    $targetBuckets = [];
    if ($needE > 0) $targetBuckets[] = -1;
    if ($needM > 0) $targetBuckets[] =  0;
    if ($needH > 0) $targetBuckets[] =  1;

    if (!$targetBuckets) {
        if ($ability >=  0.5)     $targetBuckets = [1, 0, -1];
        elseif ($ability <= -0.5) $targetBuckets = [-1, 0, 1];
        else                      $targetBuckets = [0, 1, -1];
    }
    $bucketCond = '(' . implode(' OR ', array_map(fn($b) => "$diffExpr = $b", $targetBuckets)) . ')';

    // exclude = id ที่ใช้แล้ว + prev_qid
    $exclude = $answeredIds;
    if ($prev_qid > 0) $exclude[] = $prev_qid;
    $exclude = array_values(array_unique(array_map('intval', $exclude)));

    $excludeIdSql = '';
    $idParams = [];
    if ($exclude) {
        $excludeIdSql = ' AND q.question_id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
        $idParams = $exclude;
    }

    // กัน hash ซ้ำใน session
    $excludeHashSql = '';
    $hashParams = [];
    if (!empty($answeredHashes)) {
        $excludeHashSql = ' AND (q.question_text_hash IS NULL OR q.question_text_hash NOT IN (' .
            implode(',', array_fill(0, count($answeredHashes), '?')) . '))';
        $hashParams = $answeredHashes;
    }

    $qTextSelect = ($qTextCol === "CAST(question_id AS CHAR)")
        ? "$qTextCol AS question_text"
        : "q.$qTextCol AS question_text";

    // 2.1 เลือกตาม bucket
    $sql1 = "SELECT q.question_id, $qTextSelect
           FROM question q
           WHERE $bucketCond
             $excludeIdSql
             $excludeHashSql
           ORDER BY RAND()
           LIMIT 1";
    $st1 = $pdo->prepare($sql1);
    $params1 = array_merge($idParams, $hashParams);
    $st1->execute($params1);
    $Qn = $st1->fetch(PDO::FETCH_ASSOC);

    // 2.2 ถ้ายังไม่เจอ เลือกจากทั้งคลัง
    if (!$Qn) {
        $sql2 = "SELECT q.question_id, $qTextSelect
             FROM question q
             WHERE 1=1
               $excludeIdSql
               $excludeHashSql
             ORDER BY RAND()
             LIMIT 1";
        $st2 = $pdo->prepare($sql2);
        $params2 = array_merge($idParams, $hashParams);
        $st2->execute($params2);
        $Qn = $st2->fetch(PDO::FETCH_ASSOC);
    }

    // 2.3 ไม่มีเหลือแล้ว → ปิดสอบ
    if (!$Qn) {
        $score = round($correct_count * 100.0 / max(1, $maxQ), 2);
        if (hasColumn($pdo, 'examsession', 'score')) {
            $pdo->prepare("UPDATE examsession SET end_time=NOW(), score=:sc WHERE session_id=:sid")
                ->execute([':sc' => $score, ':sid' => $session_id]);
        } else {
            $pdo->prepare("UPDATE examsession SET end_time=NOW() WHERE session_id=:sid")
                ->execute([':sid' => $session_id]);
        }
        out([
            'status'          => 'finished',
            'message'         => 'No more questions',
            'score'           => $score,
            'time_remaining'  => $timeRemaining,
            'answered_count'  => $answered_count,
            'correct_count'   => $correct_count,
            'total_questions' => $maxQ
        ]);
    }

    // โหลดตัวเลือกของข้อ
    $ch = $pdo->prepare("SELECT choice_id,label,content FROM choice WHERE question_id=? ORDER BY label ASC");
    $ch->execute([$Qn['question_id']]);
    $choices = array_map(fn($r) => [
        'choice_id'   => (int)$r['choice_id'],
        'label'       => (string)$r['label'],
        'choice_text' => (string)($r['content'] ?? ''),
    ], $ch->fetchAll(PDO::FETCH_ASSOC));

    out([
        'status'          => 'continue',
        'time_remaining'  => $timeRemaining,
        'max_questions'   => $maxQ,
        'question'        => [
            'question_id'   => (int)$Qn['question_id'],
            'question_text' => (string)$Qn['question_text'],
            'choices'       => $choices
        ],
        'total_questions' => $maxQ,
        'answered_count'  => $answered_count,
        'correct_count'   => $correct_count
    ]);
} catch (Throwable $e) {
    error_log('[submit_answer.php] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
