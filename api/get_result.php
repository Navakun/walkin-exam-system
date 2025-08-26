<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

// ตรวจสอบ session_id ที่ส่งมา
if (!isset($_GET['session_id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ session_id']);
    exit;
}

$session_id = $_GET['session_id'];

try {
    // ดึงข้อมูล session เพื่อตรวจสอบว่าใช้ชุดข้อสอบใด และเวลาเริ่ม/จบ
    $stmtSession = $pdo->prepare("SELECT student_id, examset_id, start_time, end_time FROM examsession WHERE session_id = :session_id");
    $stmtSession->execute([':session_id' => $session_id]);
    $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ session']);
        exit;
    }

    $student_id = $session['student_id'];
    $examset_id = $session['examset_id'];
    $start_time = $session['start_time'] ?? null;
    $end_time = $session['end_time'] ?? null;

    // ดึงคะแนนจาก field score ใน examsession
    $stmtScore = $pdo->prepare("SELECT score, question_ids FROM examsession WHERE session_id = :session_id");
    $stmtScore->execute([':session_id' => $session_id]);
    $sessionData = $stmtScore->fetch(PDO::FETCH_ASSOC);
    $score = isset($sessionData['score']) ? (int)$sessionData['score'] : null;
    $question_ids = json_decode($sessionData['question_ids'], true);
    $total_questions = is_array($question_ids) ? count($question_ids) : 5;

    // ดึงคำตอบทั้งหมดของนักศึกษาสำหรับ session นี้
    $stmtAnswers = $pdo->prepare("SELECT * FROM answer WHERE session_id = :session_id");
    $stmtAnswers->execute([':session_id' => $session_id]);
    $answers = $stmtAnswers->fetchAll(PDO::FETCH_ASSOC);
    $answer_count = count($answers);

    echo json_encode([
        'status' => 'success',
        'message' => null,
        'data' => [
            'student_id' => $student_id,
            'session_id' => $session_id,
            'examset_id' => $examset_id,
            'total_questions' => $total_questions,
            'answers' => $answers,
            'answer_count' => $answer_count,
            'correct_count' => $score,
            'completed' => $answer_count >= $total_questions,
            'start_time' => $start_time,
            'end_time' => $end_time
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
