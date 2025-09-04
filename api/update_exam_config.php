<?php
require_once '../config/db.php';
require_once 'helpers/encode.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ตรวจสอบ Method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

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

// รับและตรวจสอบข้อมูล
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['easy_count']) || !isset($data['medium_count']) || !isset($data['hard_count'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required fields'
    ]);
    exit;
}

// ตรวจสอบว่าค่าเป็นตัวเลขที่ถูกต้อง
if (!is_numeric($data['easy_count']) || !is_numeric($data['medium_count']) || !is_numeric($data['hard_count']) ||
    $data['easy_count'] < 0 || $data['medium_count'] < 0 || $data['hard_count'] < 0) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid number format'
    ]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO exam_config (easy_count, medium_count, hard_count) 
        VALUES (:easy, :medium, :hard)
    ");

    $stmt->execute([
        ':easy' => $data['easy_count'],
        ':medium' => $data['medium_count'],
        ':hard' => $data['hard_count']
    ]);

    echo json_encode([
        'status' => 'success',
        'message' => 'Configuration updated successfully'
    ]);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
