<?php
require_once '../config/db.php';

header('Content-Type: application/json');

// รับค่า exam_id จาก query string
$exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;

if ($exam_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid exam ID']);
    exit;
}

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ดึงข้อมูลการสอบ
    $stmt = $conn->prepare("
        SELECT 
            es.id,
            es.title,
            COUNT(eq.question_id) as question_count,
            es.duration
        FROM exam_sets es
        LEFT JOIN examset_questions eq ON es.id = eq.examset_id
        WHERE es.id = ?
        GROUP BY es.id, es.title
    ");
    $stmt->execute([$exam_id]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$exam) {
        throw new Exception('Exam not found');
    }

    echo json_encode([
        'status' => 'success',
        'title' => $exam['title'],
        'question_count' => $exam['question_count'],
        'duration' => $exam['duration'] ?? 60 // ถ้าไม่ได้กำหนดให้ใช้ 60 นาที
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
