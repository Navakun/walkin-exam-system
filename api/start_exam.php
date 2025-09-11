<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// หาก error fatal
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'SERVER_FATAL', 'detail' => $e['message']]);
    }
});

try {
    // 1. โหลด DB
    require_once __DIR__ . '/../config/db.php';
    if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined');

    // 2. JWT Authorization
    function getBearerToken(): ?string {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
        if (!$auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = $v;
                    break;
                }
            }
        }
        $auth = trim(preg_replace('/\s+/', ' ', $auth));
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return null;
        $tok = preg_replace('/[\x00-\x1F\x7F]/', '', $m[1]);
        return $tok !== '' ? $tok : null;
    }

    $token = getBearerToken();
    if (!$token || strlen($token) < 16) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'INVALID_TOKEN']);
        exit;
    }

    // 3. อ่าน JSON body
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'INVALID_JSON_BODY']);
        exit;
    }

    $student_id  = trim($input['student_id'] ?? '');
    $examset_id  = isset($input['examset_id']) ? (int)$input['examset_id'] : 0;
    $booking_id  = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;

    if (!$student_id || !$examset_id || !$booking_id) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_REQUIRED_FIELDS']);
        exit;
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4. ตรวจสอบ student/examset
    $stmS = $pdo->prepare('SELECT 1 FROM student WHERE student_id = ?');
    $stmS->execute([$student_id]);
    if (!$stmS->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'STUDENT_NOT_FOUND']);
        exit;
    }

    $stmE = $pdo->prepare('SELECT 1 FROM examset WHERE examset_id = ?');
    $stmE->execute([$examset_id]);
    if (!$stmE->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'EXAMSET_NOT_FOUND']);
        exit;
    }

    // 5. ตรวจสอบ booking และดึง slot_id
    $stmtB = $pdo->prepare('SELECT slot_id FROM exambooking WHERE id = ? AND student_id = ? AND examset_id = ? AND status = "registered" LIMIT 1');
    $stmtB->execute([$booking_id, $student_id, $examset_id]);
    $slot_id = $stmtB->fetchColumn();
    if (!$slot_id) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'BOOKING_NOT_FOUND_OR_INVALID']);
        exit;
    }

    // 6. ลบ session และคำตอบเก่าทิ้ง (กรณีสอบใหม่)
    $delAns = $pdo->prepare('DELETE FROM answer WHERE session_id IN (SELECT session_id FROM examsession WHERE student_id = ? AND examset_id = ?)');
    $delAns->execute([$student_id, $examset_id]);

    $delSess = $pdo->prepare('DELETE FROM examsession WHERE student_id = ? AND examset_id = ?');
    $delSess->execute([$student_id, $examset_id]);

    // 7. สุ่มคำถามจาก exam_set_question
    $qstmt = $pdo->prepare('SELECT question_id FROM exam_set_question WHERE examset_id = ? ORDER BY RAND() LIMIT 5');
    $qstmt->execute([$examset_id]);
    $qids = $qstmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($qids) != 5) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'สุ่มข้อสอบไม่ได้ครบ 5 ข้อ']);
        exit;
    }

    $question_ids_json = json_encode(array_map('intval', $qids));

    // 8. สร้าง session ใหม่
    $stmtNew = $pdo->prepare('
        INSERT INTO examsession (
            student_id, examset_id, slot_id, start_time, questions_answered, avg_response_time, correct_count, question_ids
        ) VALUES (?, ?, ?, NOW(), 0, 0, 0, ?)
    ');
    $stmtNew->execute([$student_id, $examset_id, $slot_id, $question_ids_json]);
    $session_id = (int)$pdo->lastInsertId();

    // 9. ส่งกลับ
    echo json_encode([
        'status' => 'success',
        'session_id' => $session_id,
        'examset_id' => $examset_id,
        'booking_id' => $booking_id,
        'slot_id' => $slot_id,
        'student_id' => $student_id,
        'question_ids' => $qids
    ]);
} catch (Throwable $e) {
    error_log('[start_exam] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
