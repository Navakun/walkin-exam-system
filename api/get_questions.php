<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once '../config/db.php';

function getBearerToken(): ?string {
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

// ========= MAIN =========
try {
    $token = getBearerToken();
    if (!$token || strlen($token) < 16) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_OR_INVALID_TOKEN']);
        exit;
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ✅ ตรวจสอบ session_id
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $session_id = isset($data['session_id']) ? (int)$data['session_id'] : 0;
    if ($session_id <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'กรุณาระบุ session_id']);
        exit;
    }

    // ✅ ตรวจสอบ session และหา examset + question_ids

    $stm = $pdo->prepare('SELECT examset_id, question_ids FROM examsession WHERE session_id = ? LIMIT 1');
    $stm->execute([$session_id]);
    $row = $stm->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'SESSION_NOT_FOUND']);
        exit;
    }
    $examset_id = (int)$row['examset_id'];
    $question_ids = json_decode($row['question_ids'] ?? '[]', true);
    if (!is_array($question_ids) || count($question_ids) === 0) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'SESSION_QUESTIONS_NOT_FOUND']);
        exit;
    }

    // จำกัดจำนวนข้อสอบต่อการสอบ
    $LIMIT = 5;

    // นับจำนวนข้อทั้งหมดในชุด แล้วจำกัดที่ 5
    $total_in_set = count($question_ids);
    $total_limit = min($LIMIT, $total_in_set);

    // ดึงข้อที่ตอบไปแล้วพร้อมผลตอบ เพื่อปรับระดับความยาก
    $stmAns = $pdo->prepare('
        SELECT a.question_id, 
               CASE WHEN a.selected_choice = q.correct_choice THEN 1 ELSE 0 END as is_correct,
               q.difficulty
        FROM answer a
        JOIN question q ON q.question_id = a.question_id
        WHERE a.session_id = ?
    ');
    $stmAns->execute([$session_id]);
    $answered = $stmAns->fetchAll(PDO::FETCH_ASSOC);
    $answered_ids = array_column($answered, 'question_id');

    // คำนวณระดับความยากเฉลี่ยของข้อที่ตอบถูก
    $avg_difficulty = 0.5; // ค่าเริ่มต้นกลาง
    $correct_count = 0;
    foreach ($answered as $ans) {
        if ($ans['is_correct']) {
            $avg_difficulty = ($avg_difficulty + $ans['difficulty']) / 2;
            $correct_count++;
        }
    }

    // ปรับระดับความยากตามผลตอบ
    $target_difficulty = $avg_difficulty;
    if ($correct_count / max(1, count($answered)) > 0.7) {
        // ถ้าตอบถูกเกิน 70% เพิ่มความยาก
        $target_difficulty += 0.1;
    } elseif ($correct_count / max(1, count($answered)) < 0.3) {
        // ถ้าตอบถูกน้อยกว่า 30% ลดความยาก
        $target_difficulty -= 0.1;
    }
    $target_difficulty = max(0.1, min(0.9, $target_difficulty));

    // ดึงข้อสอบที่ยังไม่ได้ตอบและมีความยากใกล้เคียงกับเป้าหมาย
    $params = [$examset_id];
    $sql = "
        SELECT q.question_id
        FROM question q
        JOIN exam_set_question esq ON esq.question_id = q.question_id
        WHERE esq.examset_id = ?\n";
    if (count($answered_ids) > 0) {
        $in = implode(',', array_fill(0, count($answered_ids), '?'));
        $sql .= "AND q.question_id NOT IN ($in)\n";
        foreach ($answered_ids as $aid) {
            $params[] = $aid;
        }
    }
    $sql .= "ORDER BY ABS(q.difficulty - ?) ASC\nLIMIT ?";
    $params[] = $target_difficulty;
    $params[] = $LIMIT - count($answered);
    $stmQ = $pdo->prepare($sql);
    $stmQ->execute($params);
    $remaining_ids = $stmQ->fetchAll(PDO::FETCH_COLUMN);
    $remaining = array_values($remaining_ids);

    if (count($remaining) === 0 || count($answered_ids) >= $total_limit) {
        // อัปเดต end_time ถ้าทำครบแล้ว
        $pdo->prepare('UPDATE examsession SET end_time = COALESCE(end_time, NOW()) WHERE session_id = ?')
            ->execute([$session_id]);
        echo json_encode(['status' => 'finished', 'message' => 'ทำข้อสอบครบแล้ว']);
        exit;
    }
    $next_qid = $remaining[0];
    // ดึงข้อมูลข้อสอบ
    $stmQ = $pdo->prepare('SELECT question_id, question_text FROM question WHERE question_id = ?');
    $stmQ->execute([$next_qid]);
    $q = $stmQ->fetch(PDO::FETCH_ASSOC);
    if (!$q) {
        echo json_encode(['status' => 'error', 'message' => 'QUESTION_NOT_FOUND']);
        exit;
    }

    // ดึง choices
    $stmC = $pdo->prepare('SELECT choice_id, label, content FROM choice WHERE question_id = ? ORDER BY label ASC');
    $stmC->execute([$q['question_id']]);
    $choices = [];
    while ($c = $stmC->fetch(PDO::FETCH_ASSOC)) {
        $choices[] = [
            'id' => (int)$c['choice_id'],
            'choice_text' => $c['content']
        ];
    }

    // นับ progress/total จาก question_ids ที่สุ่มไว้จริง
    $answered_count = count($answered_ids);
    $total_count = count($question_ids);

    echo json_encode([
        'status' => 'success',
        'question' => [
            'id' => (int)$q['question_id'],
            'text' => $q['question_text'],
            'choices' => $choices
        ],
        'progress' => [
            'current' => $answered_count + 1,
            'total' => $total_count
        ]
    ]);

} catch (Throwable $e) {
    error_log('[get_questions] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

// ========= FUNCTIONS =========
function getAnsweredCount($pdo, $session_id): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM answer WHERE session_id = ?');
    $stmt->execute([$session_id]);
    return (int)$stmt->fetchColumn();
}

// ฟังก์ชันนี้ไม่จำเป็นอีกต่อไป (นับจาก question_ids ใน session เท่านั้น)
