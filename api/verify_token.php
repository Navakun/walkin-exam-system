<?php
// api/verify_token.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once __DIR__ . '/helpers/jwt_helper.php'; // ✅ แก้พาธให้ถูก

// รับ token จาก Header
$hdrs = function_exists('getallheaders') ? getallheaders() : [];
$auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? '';
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}
$token = $m[1];

try {
    $decoded = decodeToken($token); // ✅ ใช้ helper
    if (!$decoded) {
        throw new Exception('Token ไม่ถูกต้องหรือหมดอายุ');
    }

    // ถ้า endpoint นี้ตั้งใจใช้เช็คสิทธิ์อาจารย์ ให้บังคับ role ได้
    if (($decoded['role'] ?? '') !== 'teacher') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึง');
    }

    echo json_encode([
        'status'  => 'success',
        'message' => 'Token ถูกต้อง',
        'data'    => [
            'id'   => $decoded['id']   ?? null,
            'name' => $decoded['name'] ?? null,
            'role' => $decoded['role'] ?? null,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Token ไม่ถูกต้อง: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
