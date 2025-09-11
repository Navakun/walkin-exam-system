<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'SERVER_FATAL', 'detail' => $e['message']]);
    }
});

function getBearerToken(): ?string
{
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');
    if (!$auth && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = $v;
                break;
            }
        }
    }
    if (!$auth) return null;
    $auth = trim(preg_replace('/\s+/', ' ', $auth));
    if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return null;
    $tok = preg_replace('/[\x00-\x1F\x7F]/', '', $m[1]);
    return $tok !== '' ? $tok : null;
}

try {
    // (1) Connect to DB
    $dbPath = __DIR__ . '/../config/db.php';
    if (!file_exists($dbPath)) throw new RuntimeException('db.php not found');
    require_once $dbPath;
    if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // (2) Validate Token
    $token = getBearerToken();
    if (!$token || strlen($token) < 16) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_OR_INVALID_TOKEN']);
        exit;
    }
    // (ใส่การ decode JWT หรือ lookup token เพิ่มเติมได้ที่นี่ถ้าต้องการ)

    // (3) Read input
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'INVALID_JSON_BODY']);
        exit;
    }

    $session_id = (int)($input['session_id'] ?? 0);
    $question_id = (int)($input['question_id'] ?? 0);
    $choice_id   = (int)($input['selected_choice_id'] ?? 0);

    if ($session_id <= 0 || $question_id <= 0 || $choice_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_FIELDS']);
        exit;
    }

    // (4) ตรวจสอบ session และดึง examset_id
    $stm = $pdo->prepare('SELECT examset_id FROM examsession WHERE session_id = ? LIMIT 1');
    $stm->execute([$session_id]);
    $examset_id = (int)$stm->fetchColumn();
    if (!$examset_id) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'SESSION_NOT_FOUND']);
        exit;
    }

    // (5) ตรวจว่าคำถามอยู่ในชุดสอบ
    $stm = $pdo->prepare('
            SELECT 1 FROM examset 
            WHERE examset_id = ? 
            AND EXISTS (
                SELECT 1 FROM question WHERE question_id = ? 
            )
        ');
    $stm->execute([$examset_id, $question_id]);
    if (!$stm->fetchColumn()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'QUESTION_NOT_FOUND_IN_EXAMSET']);
        exit;
    }

    // (6) แปลง choice_id → label
    $stm = $pdo->prepare('SELECT label FROM choice WHERE choice_id = ? AND question_id = ?');
    $stm->execute([$choice_id, $question_id]);
    $label = $stm->fetchColumn();
    if (!$label) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'CHOICE_NOT_FOUND']);
        exit;
    }
    $label = strtoupper(substr(trim($label), 0, 1));

    // (7) บันทึกคำตอบ
    $inTransaction = false;
    try {
        $pdo->beginTransaction();
        $inTransaction = true;
        $stm = $pdo->prepare('SELECT answer_id FROM answer WHERE session_id = ? AND question_id = ? LIMIT 1');
        $stm->execute([$session_id, $question_id]);
        $ansId = $stm->fetchColumn();

        if ($ansId) {
            $stm = $pdo->prepare('UPDATE answer SET selected_choice = ?, answered_at = NOW() WHERE answer_id = ?');
            $stm->execute([$label, $ansId]);
        } else {
            $stm = $pdo->prepare('INSERT INTO answer (session_id, question_id, selected_choice, answered_at) VALUES (?, ?, ?, NOW())');
            $stm->execute([$session_id, $question_id, $label]);

            $pdo->prepare('UPDATE examsession SET questions_answered = questions_answered + 1 WHERE session_id = ?')
                ->execute([$session_id]);
        }
        $pdo->commit();
        $inTransaction = false;
    } catch (Throwable $ex) {
        if ($inTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $ex;
    }

    // (8) ตรวจสอบจำนวนข้อที่ตอบและหาข้อถัดไป
    $LIMIT = 5;

    // ดึง question_ids จาก session
    $stmQ = $pdo->prepare('SELECT question_ids FROM examsession WHERE session_id = ?');
    $stmQ->execute([$session_id]);
    $rowQ = $stmQ->fetch(PDO::FETCH_ASSOC);
    $question_ids = json_decode($rowQ['question_ids'] ?? '[]', true);
    if (!is_array($question_ids)) $question_ids = [];

    // จำกัดที่ 5 ข้อ
    $total_limit = min($LIMIT, count($question_ids));
    $limited_qids = array_slice($question_ids, 0, $LIMIT);

    // นับจำนวนข้อที่ตอบแล้ว
    $stm = $pdo->prepare('SELECT COUNT(*) FROM answer WHERE session_id = ?');
    $stm->execute([$session_id]);
    $answered_count = (int)$stm->fetchColumn();

    // ถ้าตอบครบแล้ว → คำนวณคะแนนและจบ
    if ($answered_count >= $total_limit) {
        // คำนวณคะแนนจากเฉพาะ 5 ข้อที่สุ่มได้
        $score = 0;
        foreach ($limited_qids as $qid) {
            $stmA = $pdo->prepare('
                SELECT a.selected_choice, q.correct_choice 
                FROM answer a 
                JOIN question q ON q.question_id = a.question_id
                WHERE a.session_id = ? AND a.question_id = ?
            ');
            $stmA->execute([$session_id, $qid]);
            $ans = $stmA->fetch(PDO::FETCH_ASSOC);
            if ($ans && strtoupper($ans['selected_choice']) === strtoupper($ans['correct_choice'])) {
                $score++;
            }
        }

        // อัปเดตคะแนนและเวลาสิ้นสุด
        $pdo->prepare('
            UPDATE examsession 
            SET score = ?, end_time = COALESCE(end_time, NOW()) 
            WHERE session_id = ?
        ')->execute([$score, $session_id]);

        // No transaction needed for score update
        echo json_encode([
            'status' => 'finished',
            'message' => 'ทำข้อสอบครบแล้ว',
            'score' => $score
        ]);
        exit;
    }

    // หาข้อถัดไปจากกลุ่ม 5 ข้อแรก
    $answered_ids = $pdo->prepare('SELECT question_id FROM answer WHERE session_id = ?');
    $answered_ids->execute([$session_id]);
    $answered = $answered_ids->fetchAll(PDO::FETCH_COLUMN);

    $remaining = array_values(array_diff($limited_qids, array_map('intval', $answered)));
    if (empty($remaining)) {
        $pdo->prepare('UPDATE examsession SET end_time = COALESCE(end_time, NOW()) WHERE session_id = ?')
            ->execute([$session_id]);
        // No transaction needed for end time update
        echo json_encode(['status' => 'finished', 'message' => 'ทำข้อสอบครบแล้ว']);
        exit;
    }

    $stm = $pdo->prepare("SELECT question_id, question_text FROM question WHERE question_id = ?");
    $stm->execute([$remaining[0]]);
    $nextQ = $stm->fetch(PDO::FETCH_ASSOC);

    if (!$nextQ) {
        // บันทึกเวลาสิ้นสุด
        $pdo->prepare('UPDATE examsession SET end_time = NOW() WHERE session_id = ?')->execute([$session_id]);

        // === คำนวณคะแนนและอัปเดต score (ใช้เฉพาะ question_ids ที่สุ่มไว้ใน session) ===
        $stmQ = $pdo->prepare('SELECT question_ids FROM examsession WHERE session_id = ?');
        $stmQ->execute([$session_id]);
        $rowQ = $stmQ->fetch(PDO::FETCH_ASSOC);
        $question_ids = json_decode($rowQ['question_ids'] ?? '[]', true);
        if (!is_array($question_ids) || count($question_ids) === 0) {
            $question_ids = [];
        }

        // 2. ดึงคำตอบของนักศึกษาทั้งหมดใน session นี้
        $stmA = $pdo->prepare('SELECT question_id, selected_choice FROM answer WHERE session_id = ?');
        $stmA->execute([$session_id]);
        $answers = $stmA->fetchAll(PDO::FETCH_KEY_PAIR); // [question_id => selected_choice]

        // 3. ดึงเฉลยที่ถูกต้องจาก question (label ที่ตรงกับ correct_choice ใน question)
        $score = 0;
        foreach ($question_ids as $qid) {
            // ดึง correct_choice จาก question
            $stmC = $pdo->prepare('SELECT correct_choice FROM question WHERE question_id = ?');
            $stmC->execute([$qid]);
            $correct = $stmC->fetchColumn();
            if (!$correct) continue;
            // เปรียบเทียบกับคำตอบ
            if (isset($answers[$qid]) && strtoupper($answers[$qid]) === strtoupper($correct)) {
                $score++;
            }
        }
        // 4. อัปเดตคะแนน
        $pdo->prepare('UPDATE examsession SET score = ? WHERE session_id = ?')->execute([$score, $session_id]);

        echo json_encode(['status' => 'finished', 'message' => 'ทำข้อสอบครบแล้ว', 'score' => $score]);
        exit;
    }


    $stm = $pdo->prepare('
        SELECT choice_id AS id, label, content AS choice_text
        FROM choice
        WHERE question_id = ?
        ORDER BY label ASC
    ');
    $stm->execute([$nextQ['question_id']]);
    $choices = $stm->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'continue',
        'question' => [
            'id' => (int)$nextQ['question_id'],
            'text' => $nextQ['question_text'],
            'choices' => $choices
        ]
    ]);
} catch (Throwable $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[submit_answer] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
