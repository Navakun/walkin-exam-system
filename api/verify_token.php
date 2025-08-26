<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$token = $matches[1];
$secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";

try {
    // ตรวจสอบ token
    $decoded = JWT::decode($token, new Key($secret_key, 'HS256'));
    
    // ตรวจสอบว่าเป็น token ของอาจารย์
    if (!isset($decoded->role) || $decoded->role !== 'teacher') {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึง']);
        exit;
    }

    // ตรวจสอบว่า token ยังไม่หมดอายุ
    if (isset($decoded->exp) && $decoded->exp < time()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Token หมดอายุ']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Token ถูกต้อง',
        'data' => [
            'id' => $decoded->id,
            'name' => $decoded->name,
            'role' => $decoded->role
        ]
    ]);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Token ไม่ถูกต้อง: ' . $e->getMessage()
    ]);
}
