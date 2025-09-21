<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

function getBearerToken()
{
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) return null;
    if (!preg_match('/Bearer\s+(\S+)/', $headers['Authorization'], $matches)) return null;
    return $matches[1];
}

$token = getBearerToken();
if (!$token) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Missing token']);
    exit;
}

try {
    $decoded = JWT::decode($token, new Key("your-secret-key", 'HS256'));
    if (!isset($decoded->role) || $decoded->role !== 'student') {
        throw new Exception('Access denied');
    }
    $student_id = $decoded->student_id ?? null;
    if (!$student_id) throw new Exception('Missing student_id');
} catch (Exception $e) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// ดึงข้อมูลการจองล่าสุด และหา examset_id ที่ตรงกับ slot_id
$stmt = $pdo->prepare("
    SELECT 
        esr.id AS booking_id, 
        esr.slot_id, 
        es.examset_id
    FROM exam_slot_registrations esr
    LEFT JOIN examset es ON es.examset_id = esr.examset_id
    WHERE esr.student_id = ?
    ORDER BY esr.registered_at DESC
    LIMIT 1
");
$stmt->execute([$student_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if ($booking) {
    echo json_encode(['status' => 'success'] + $booking);
} else {
    echo json_encode(['status' => 'error', 'message' => 'ยังไม่มีข้อมูลการจอง']);
}
