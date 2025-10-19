<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// JWT
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(.+)$/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}
try {
    $decoded = JWT::decode($m[1], new Key($jwt_key, 'HS256'));
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception('Token ไม่มี student_id');
} catch (Throwable $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ', 'debug' => $e->getMessage()]);
    exit;
}

$registration_id = isset($_GET['registration_id']) ? (int)$_GET['registration_id'] : 0;
if ($registration_id <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'registration_id ไม่ถูกต้อง']);
    exit;
}

try {
    // ดึง payment ล่าสุดของ reg นี้ (ของนิสิตคนนี้เท่านั้น)
    $sql = "SELECT payment_id, student_id, registration_id, amount, method, status, paid_at, ref_no, slip_file, created_at
            FROM payments
            WHERE registration_id = ? AND student_id = ?
            ORDER BY payment_id DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$registration_id, $student_id]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'status'  => 'success',
        'payment' => $pay ?: null
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'ไม่สามารถดึงสถานะการชำระเงิน', 'debug' => $e->getMessage()]);
}
