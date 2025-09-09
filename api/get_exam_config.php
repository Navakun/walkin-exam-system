<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../helpers/encode.php';

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

try {
    $stmt = $pdo->prepare("SELECT * FROM exam_config ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        // ถ้ายังไม่มีค่าในระบบ ใช้ค่าเริ่มต้น
        $result = [
            'easy_count' => 15,
            'medium_count' => 15,
            'hard_count' => 20
        ];
    }

    echo json_encode($result);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
