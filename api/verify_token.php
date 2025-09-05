<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/../helpers/jwt_helper.php'; // ✅ โหลดตัวช่วย JWT
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;



// รับ token
$headers = apache_request_headers();
if (!isset($headers['Authorization']) && !isset($headers['authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}

$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : $headers['authorization'];
if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'รูปแบบ token ไม่ถูกต้อง']);
    exit;
}

try {
    $decoded = decodeToken($token); // ✅ ใช้ helper จาก jwt_helper.php
    
    if (!$decoded || !isset($decoded['role']) || $decoded['role'] !== 'teacher') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึง');
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Token ถูกต้อง',
        'data' => [
            'id' => $decoded['id'] ?? null,
            'name' => $decoded['name'] ?? null,
            'role' => $decoded['role']
        ]
    ]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Token ไม่ถูกต้อง: ' . $e->getMessage()
    ]);
}
