<?php
declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

require_once '../config/db.php';
require_once '../vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

// ===== Helper Functions =====
function fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function success(array $data): void {
    echo json_encode(['status' => 'success'] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getBearerToken(): ?string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(.*)$/i', $auth, $m)) {
        return $m[1];
    }
    return null;
}

// ===== Auth & Decode Token =====
$token = getBearerToken();
if (!$token) fail(401, 'กรุณาเข้าสู่ระบบ');

try {
    if (empty($jwt_key)) throw new Exception('JWT key not configured');
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    if (empty($decoded->student_id)) throw new Exception('ไม่พบ student_id');
    $student_id = (string) $decoded->student_id;
} catch (Exception $e) {
    fail(401, 'Token ไม่ถูกต้องหรือหมดอายุ');
}

// ===== รับข้อมูล slot_id =====
$raw_data = file_get_contents('php://input');
$data = json_decode($raw_data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    fail(400, 'รูปแบบ JSON ไม่ถูกต้อง: ' . json_last_error_msg());
}
$slot_id = $data['slot_id'] ?? null;
if (!$slot_id) fail(400, 'กรุณาระบุช่วงเวลาสอบ');

// ===== ตรวจสอบการจองซ้ำ =====
$stmt = $pdo->prepare("SELECT 1 FROM exambooking WHERE student_id = :sid AND slot_id = :slot_id");
$stmt->execute([':sid' => $student_id, ':slot_id' => $slot_id]);
if ($stmt->fetch()) fail(400, 'คุณได้ลงทะเบียนช่วงเวลานี้แล้ว');

// ===== ตรวจสอบ slot และที่นั่งว่าง =====
$stmt = $pdo->prepare("
    SELECT es.*, (
        SELECT COUNT(*) FROM exambooking WHERE slot_id = es.id
    ) AS booked_count
    FROM exam_slots es
    WHERE es.id = :slot_id AND es.slot_date >= CURDATE()
");
$stmt->execute([':slot_id' => $slot_id]);
$slot = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$slot) fail(404, 'ไม่พบช่วงเวลาสอบที่เลือก');
if ((int)$slot['booked_count'] >= (int)$slot['max_seats']) fail(400, 'ที่นั่งเต็มแล้ว');

// ===== สุ่มชุดข้อสอบ =====
$stmt = $pdo->query("SELECT examset_id FROM examset ORDER BY RAND() LIMIT 1");
$examset = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$examset) fail(500, 'ไม่พบชุดข้อสอบ กรุณาติดต่อผู้ดูแลระบบ');
$examset_id = $examset['examset_id'];

// ===== บันทึกการจองแบบ transaction =====
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO exambooking (student_id, slot_id, examset_id, scheduled_at, status)
        VALUES (:sid, :slot_id, :examset_id, :scheduled_at, 'registered')
    ");
    $stmt->execute([
        ':sid' => $student_id,
        ':slot_id' => $slot_id,
        ':examset_id' => $examset_id,
        ':scheduled_at' => date('Y-m-d H:i:s')
    ]);

    $booking_id = $pdo->lastInsertId();
    $pdo->commit();

    success([
        'message' => 'ลงทะเบียนสำเร็จ',
        'booking_id' => $booking_id,
        'session_id' => $booking_id,
        'examset_id' => $examset_id
    ]);
} catch (Exception $e) {
    $pdo->rollBack();
    fail(500, 'เกิดข้อผิดพลาดขณะลงทะเบียน: ' . $e->getMessage());
}
