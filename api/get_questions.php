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

    // ดึงเฉพาะข้อที่ยังไม่ได้ตอบ จาก question_ids ที่สุ่มไว้
    $answered = $pdo->prepare('SELECT question_id FROM answer WHERE session_id = ?');
    $answered->execute([$session_id]);
    $answered_ids = $answered->fetchAll(PDO::FETCH_COLUMN);
    $remaining = array_values(array_diff($question_ids, array_map('intval', $answered_ids)));
    if (count($remaining) === 0) {
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
