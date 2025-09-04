<?php
require_once '../config/db.php';
require_once 'helpers/encode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ตรวจสอบ token
$headers = apache_request_headers();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

$auth_header = $headers['Authorization'];
if (strpos($auth_header, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token format']);
    exit;
}

$token = substr($auth_header, 7);
try {
    $decoded = verifyJwtToken($token);
    if (!$decoded || !isset($decoded->instructor_id)) {
        throw new Exception('Invalid token');
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// รับข้อมูลช่วงวันที่
$data = json_decode(file_get_contents('php://input'), true);
$startDate = $data['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $data['endDate'] ?? date('Y-m-d');

try {
    // สรุปภาพรวม
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT s.student_id) as total_students,
            AVG(
                (SELECT COUNT(*) 
                FROM exam_answers a 
                WHERE a.session_id = s.id 
                AND a.is_correct = 1)
            ) as average_score,
            MAX(
                (SELECT COUNT(*) 
                FROM exam_answers a 
                WHERE a.session_id = s.id 
                AND a.is_correct = 1)
            ) as max_score,
            MIN(
                (SELECT COUNT(*) 
                FROM exam_answers a 
                WHERE a.session_id = s.id 
                AND a.is_correct = 1)
            ) as min_score
        FROM exam_sessions s
        WHERE DATE(s.start_time) BETWEEN :start_date AND :end_date
    ");
    
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // การกระจายคะแนน
    $stmt = $pdo->prepare("
        WITH scores AS (
            SELECT 
                s.id,
                (
                    SELECT COUNT(*) 
                    FROM exam_answers a 
                    WHERE a.session_id = s.id 
                    AND a.is_correct = 1
                ) as score
            FROM exam_sessions s
            WHERE DATE(s.start_time) BETWEEN :start_date AND :end_date
        )
        SELECT 
            score,
            COUNT(*) as count
        FROM scores
        GROUP BY score
        ORDER BY score
    ");
    
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    
    $scoreDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // สถิติตามระดับความยาก
    $stmt = $pdo->prepare("
        SELECT 
            q.difficulty,
            COUNT(CASE WHEN a.is_correct = 1 THEN 1 END) * 100.0 / COUNT(*) as correct_percentage
        FROM exam_answers a
        JOIN exam_questions eq ON a.question_id = eq.question_id
        JOIN questions q ON eq.question_id = q.id
        JOIN exam_sessions s ON a.session_id = s.id
        WHERE DATE(s.start_time) BETWEEN :start_date AND :end_date
        GROUP BY q.difficulty
    ");
    
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    
    $difficultyStats = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $difficultyStats[$row['difficulty']] = $row['correct_percentage'];
    }

    // สถิติรายข้อ
    $stmt = $pdo->prepare("
        SELECT 
            q.id,
            q.difficulty,
            COUNT(CASE WHEN a.is_correct = 1 THEN 1 END) as correct_count,
            COUNT(CASE WHEN a.is_correct = 1 THEN 1 END) * 100.0 / COUNT(*) as correct_percentage
        FROM questions q
        LEFT JOIN exam_questions eq ON q.id = eq.question_id
        LEFT JOIN exam_answers a ON eq.question_id = a.question_id
        LEFT JOIN exam_sessions s ON a.session_id = s.id
        WHERE DATE(s.start_time) BETWEEN :start_date AND :end_date
        GROUP BY q.id, q.difficulty
        ORDER BY q.id
    ");
    
    $stmt->execute([
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ]);
    
    $questionStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'summary' => $summary,
        'charts' => [
            'score_distribution' => [
                'labels' => array_column($scoreDistribution, 'score'),
                'data' => array_column($scoreDistribution, 'count')
            ],
            'difficulty_stats' => $difficultyStats
        ],
        'questions' => $questionStats
    ]);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
