<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
// ถ้าทดสอบข้ามพอร์ต/โดเมน ให้เปิด CORS ตามต้องการ
// header('Access-Control-Allow-Origin: *');
// header('Access-Control-Allow-Headers: Content-Type, Authorization');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'SERVER_FATAL', 'detail' => $e['message']]);
    }
});

try {
    // --- 1) include DB ---
    $dbPath = __DIR__ . '/../config/db.php';
    if (!file_exists($dbPath)) throw new RuntimeException('db.php not found at ' . $dbPath);
    require_once $dbPath; // ต้องประกาศ $pdo
    if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined (check db.php)');

    // --- 2) ตรวจ Authorization: ต้องมี Bearer token ---
    //   $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    //   if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
    //     http_response_code(401);
    //     echo json_encode(['status' => 'error', 'message' => 'MISSING_BEARER']);
    //     exit;
    //   }
    //   $token = $m[1];
    //   if (strlen($token) < 16) { // กันส่งมั่ว ๆ
    //     http_response_code(401);
    //     echo json_encode(['status' => 'error', 'message' => 'INVALID_TOKEN']);
    //     exit;
    //   }

    // แทนที่บล็อกเดิมที่อ่าน Authorization ด้วยอันนี้
    function getBearerToken(): ?string
    {
        // 1) มาตรฐาน
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        // 2) บางเซิร์ฟเวอร์ใช้ 'Authorization'
        if (!$auth) {
            $auth = $_SERVER['Authorization'] ?? '';
        }
        // 3) สำรอง: ดึงจาก getallheaders()
        if (!$auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = $v;
                    break;
                }
            }
        }
        if (!$auth) return null;

        // Trim กัน \r \n \t และช่องว่างส่วนเกิน
        $auth = trim(preg_replace('/\s+/', ' ', $auth));
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return null;

        // ตัด control chars ออกอีกชั้น
        $tok = preg_replace('/[\x00-\x1F\x7F]/', '', $m[1]);
        return $tok !== '' ? $tok : null;
    }

    $token = getBearerToken();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_BEARER']);
        exit;
    }
    if (strlen($token) < 16) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'INVALID_TOKEN']);
        exit;
    }

    // TODO: ถ้าทำระบบ session/token จริง ให้ตรวจ token ใน storage ที่นี่

    // --- 3) อ่าน JSON body ---
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'INVALID_JSON_BODY', 'raw' => $raw]);
        exit;
    }

    // ✅ แก้พิมพ์ตกตรงนี้
    $examset_id = isset($input['examset_id']) ? (int)$input['examset_id'] : 0;
    $student_id = trim($input['student_id'] ?? '');

    if ($examset_id <= 0 || $student_id === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'MISSING_FIELDS']);
        exit;
    }

    // --- 4) ตรวจว่ามี student และ examset จริง ---
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmS = $pdo->prepare('SELECT student_id FROM student WHERE student_id = ? LIMIT 1');
    $stmS->execute([$student_id]);
    if (!$stmS->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'STUDENT_NOT_FOUND']);
        exit;
    }

    $stmE = $pdo->prepare('SELECT examset_id FROM examset WHERE examset_id = ? LIMIT 1');
    $stmE->execute([$examset_id]);
    if (!$stmE->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'EXAMSET_NOT_FOUND']);
        exit;
    }

    // --- ตรวจสอบว่านักศึกษามี booking/exambooking สำหรับ examset_id นี้หรือไม่ ---
    $stmB = $pdo->prepare('SELECT booking_id FROM exambooking WHERE student_id = ? AND examset_id = ? AND status = "registered" ORDER BY booking_id DESC LIMIT 1');
    $stmB->execute([$student_id, $examset_id]);
    if (!$stmB->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'คุณยังไม่ได้ลงทะเบียนสอบ กรุณาลงทะเบียนก่อนเข้าสอบ']);
        exit;
    }

    // --- 5) สร้าง session ---
    // --- ลบคำตอบเดิมใน answer ถ้ามี (กรณีสอบซ้ำ) ---
    // --- ลบ session/examset เก่าของนิสิตนี้ทิ้งก่อนสร้างใหม่ (ป้องกัน session_id ซ้ำ/ข้อมูลเก่าค้าง) ---
    $delAns = $pdo->prepare('DELETE FROM answer WHERE session_id IN (SELECT session_id FROM examsession WHERE student_id = ? AND examset_id = ?)');
    $delAns->execute([$student_id, $examset_id]);
    $delSess = $pdo->prepare('DELETE FROM examsession WHERE student_id = ? AND examset_id = ?');
    $delSess->execute([$student_id, $examset_id]);

    // --- สุ่ม 5 ข้อจาก examset_id ---
    $qstmt = $pdo->prepare('SELECT question_id FROM exam_set_question WHERE examset_id = ? ORDER BY RAND() LIMIT 5');
    $qstmt->execute([$examset_id]);
    $qids = $qstmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($qids) < 1) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อสอบในชุดนี้']);
        exit;
    }
    $question_ids_json = json_encode(array_map('intval', $qids));

    // --- สร้าง session ใหม่ พร้อมบันทึก question_ids ---
    $stmI = $pdo->prepare('
        INSERT INTO examsession (student_id, examset_id, start_time, questions_answered, question_ids)
        VALUES (?, ?, NOW(), 0, ?)
    ');
    $stmI->execute([$student_id, $examset_id, $question_ids_json]);
    $session_id = (int)$pdo->lastInsertId();

    echo json_encode([
        'status' => 'success',
        'session_id' => $session_id,
        'examset_id' => $examset_id,
        'student_id' => $student_id,
        'question_ids' => $qids
    ]);
} catch (Throwable $e) {
    error_log('[start_exam] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

error_log("[start_exam] Creating session for student_id=$student_id, examset_id=$examset_id");

