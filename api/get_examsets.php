<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

// ตรวจสอบ Authorization
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

$auth_header = $headers['Authorization'];
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token format']);
    exit;
}

$token = $matches[1];
// TODO: ตรวจสอบ token กับฐานข้อมูล

try {
    // Get all examsets with question count
    $stmt = $pdo->prepare("
        SELECT 
            e.examset_id,
            e.title,
            (SELECT COUNT(*) FROM exam_set_question esq WHERE esq.examset_id = e.examset_id) as question_count
        FROM examset e
        ORDER BY e.examset_id DESC
    ");
    $stmt->execute();
    $examsets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($examsets)) {
        echo json_encode([
            'status' => 'success',
            'data' => [],
            'message' => 'ยังไม่มีชุดข้อสอบในระบบ'
        ]);
    } else {
        echo json_encode([
            'status' => 'success',
            'data' => $examsets
        ]);
    }

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล'
    ]);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ]);
}
?>
