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
    // ดึงข้อมูล session เพื่อตรวจสอบว่าใช้ชุดข้อสอบใด
    $stmtSession = $pdo->prepare("SELECT student_id, examset_id FROM examsession WHERE session_id = :session_id");
    $stmtSession->execute([':session_id' => $session_id]);
    $session = $stmtSession->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ session']);
        exit;
    }

    $student_id = $session['student_id'];
    $examset_id = $session['examset_id'];

    // ดึงคำตอบทั้งหมดของนักศึกษาสำหรับ session นี้
    $stmtAnswers = $pdo->prepare("SELECT * FROM answer WHERE session_id = :session_id");
    $stmtAnswers->execute([':session_id' => $session_id]);
    $answers = $stmtAnswers->fetchAll(PDO::FETCH_ASSOC);
    $answer_count = count($answers);
    
    // นับจำนวนข้อที่ถูก
    $correct_count = array_reduce($answers, function($carry, $answer) {
        return $carry + ($answer['is_correct'] ? 1 : 0);
    }, 0);

    // ดึงจำนวนข้อสอบจาก question_ids ที่เก็บไว้ใน session
    $stmtSession = $pdo->prepare("SELECT question_ids FROM examsession WHERE session_id = :session_id");
    $stmtSession->execute([':session_id' => $session_id]);
    $sessionData = $stmtSession->fetch(PDO::FETCH_ASSOC);
    $question_ids = json_decode($sessionData['question_ids'], true);
    $total_questions = 5; // กำหนดให้เป็น 5 ข้อตามโจทย์

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
            'correct_count' => $correct_count,
            'completed' => $answer_count >= $total_questions
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
