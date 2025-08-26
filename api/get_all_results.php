<?php
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/db.php';

// ตรวจสอบ Authorization header
$headers = getallheaders();
if (!isset($headers['Authorization']) && !isset($headers['authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'กรุณาเข้าสู่ระบบ']);
    exit;
}

// รับ token
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : $headers['authorization'];
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้อง']);
    exit;
}

$token = $matches[1];
$secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";

try {
    // ตรวจสอบ token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    
    // ตรวจสอบว่าเป็น token ของอาจารย์
    if (!isset($decoded->role) || $decoded->role !== 'teacher') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึงข้อมูล');
    }


    // ดึงข้อมูลผลสอบทั้งหมด
    $sql = "SELECT 
                s.session_id,
                st.student_id,
                st.name AS student_name,
                es.title AS exam_title,
                s.score,
                s.start_time,
                s.end_time
            FROM 
                exam_session s
            JOIN 
                student st ON s.student_id = st.student_id
            JOIN 
                examset es ON s.examset_id = es.examset_id
            ORDER BY 
                s.session_id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // จัดรูปแบบข้อมูลก่อนส่งกลับ
    $formatted_results = array_map(function($result) {
        return [
            'session_id' => $result['session_id'],
            'student_id' => $result['student_id'],
            'student_name' => $result['student_name'],
            'exam_title' => $result['exam_title'],
            'score' => $result['score'],
            'start_time' => $result['start_time'],
            'end_time' => $result['end_time']
        ];
    }, $results);

    echo json_encode([
        'status' => 'success',
        'results' => $formatted_results
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>