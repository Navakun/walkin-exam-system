<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

// ตรวจสอบ Authorization
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
if (empty($auth_header) || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}

$token = $matches[1];
// TODO: ตรวจสอบ token กับฐานข้อมูล

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // ดึงข้อมูลคำถามทั้งหมด
    $stmt = $pdo->prepare("
        SELECT q.question_id, q.question_text, q.correct_choice, q.difficulty_level, esq.examset_id
        FROM question q
        LEFT JOIN exam_set_question esq ON q.question_id = esq.question_id
        ORDER BY q.question_id ASC
    ");
    $stmt->execute();
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงตัวเลือกสำหรับแต่ละคำถาม
    $stmt = $pdo->prepare("
        SELECT c.question_id, c.label, c.content
        FROM choice c
        WHERE c.question_id = ?
        ORDER BY c.label ASC
    ");

    // เพิ่มตัวเลือกเข้าไปในแต่ละคำถาม
    foreach ($questions as &$q) {
        $stmt->execute([$q['question_id']]);
        $q['choices'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 'success',
        'data' => $questions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
?>
