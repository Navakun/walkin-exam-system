<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
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
    
    // ตรวจสอบชื่อ column ที่มีอยู่ในตาราง
    $stmt = $pdo->prepare("DESCRIBE question");
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // สร้าง SQL query ตาม columns ที่มีอยู่จริง
    $sql = "SELECT q.question_id, q.question_text, q.correct_choice";
    
    if (in_array('difficulty', $columns)) {
        $sql .= ", q.difficulty";
    } else if (in_array('item_difficulty', $columns)) {
        $sql .= ", q.item_difficulty";
    }
    
    if (in_array('discrimination', $columns)) {
        $sql .= ", q.discrimination";
    }
    if (in_array('total_attempts', $columns)) {
        $sql .= ", q.total_attempts";
    }
    if (in_array('correct_attempts', $columns)) {
        $sql .= ", q.correct_attempts";
    }
    if (in_array('avg_response_time', $columns)) {
        $sql .= ", q.avg_response_time";
    }
    
    $sql .= " FROM question q ORDER BY q.question_id ASC";
    
    error_log("SQL Query: " . $sql); // Log the query for debugging
    
    $stmt = $pdo->prepare($sql);
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
