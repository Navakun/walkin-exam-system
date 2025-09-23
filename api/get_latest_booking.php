<?php

declare(strict_types=1);

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ⬇️ ฟังก์ชันช่วยคืน JSON
function json_out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 1) โหลด DB + composer autoload
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../vendor/autoload.php';

    // 2) JWT Helper
    $jwtHelper = __DIR__ . '/helpers/jwt_helper.php';
    if (!file_exists($jwtHelper)) throw new Exception("jwt_helper missing");
    require_once $jwtHelper;

    // 3) อ่าน Bearer Token
    function getBearerToken(): ?string
    {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) return null;
        if (!preg_match('/Bearer\s+(\S+)/', $headers['Authorization'], $matches)) return null;
        return $matches[1];
    }

    $token = getBearerToken();
    if (!$token) json_out(['status' => 'error', 'message' => 'Missing token'], 401);

    // 4) ถอดรหัส JWT
    try {
        $decoded = JWT::decode($token, new Key(getJwtKey(), 'HS256'));
    } catch (Exception $e) {
        json_out(['status' => 'error', 'message' => 'Invalid token'], 403);
    }

    if (!isset($decoded->role) || $decoded->role !== 'student') {
        json_out(['status' => 'error', 'message' => 'Unauthorized role'], 403);
    }

    $student_id = $decoded->student_id ?? null;
    if (!$student_id) {
        json_out(['status' => 'error', 'message' => 'Missing student_id'], 403);
    }

    // 5) คำสั่ง SQL: ดึงการจองล่าสุด
    $stmt = $pdo->prepare("
        SELECT 
            esr.id AS booking_id,
            esr.slot_id,
            esr.examset_id
        FROM exam_slot_registrations esr
        WHERE esr.student_id = ?
        ORDER BY esr.registered_at DESC
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);


    if ($booking) {
        json_out(['status' => 'success'] + $booking);
    } else {
        json_out(['status' => 'error', 'message' => 'ยังไม่มีข้อมูลการจอง'], 404);
    }
} catch (Throwable $e) {
    error_log('[get_latest_booking.php] ERROR: ' . $e->getMessage());
    json_out(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()], 500);
}
