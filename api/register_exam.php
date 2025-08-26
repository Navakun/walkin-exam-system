
<?php
// DEBUG: แสดง error ทุกอย่าง
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';
// require '../auth.php';
require_once '../vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ฟังก์ชันตรวจสอบ JWT (ย้ายมาไว้ที่นี่โดยตรง)
if (!function_exists('validateJWT')) {
    function validateJWT($token) {
        $secretKey = 'd57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef'; // ใช้ key เดียวกับ student_login_new.php
        try {
            $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
            return (array)$decoded;
        } catch (Exception $e) {
            return false;
        }
    }
}

header("Content-Type: application/json");

// ตรวจสอบ JWT token
$headers = apache_request_headers();
$authHeader = $headers['Authorization'] ?? '';
if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
    $token = $matches[1];
    $decoded = validateJWT($token);
    if (!$decoded) {
        http_response_code(401);
        echo json_encode(["status" => "error", "message" => "Invalid token"]);
        exit;
    }
} else {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "No token provided"]);
    exit;
}

// รับ JSON input
$data = json_decode(file_get_contents("php://input"), true);
$student_id = $data['student_id'] ?? null;
$slot_id = $data['slot_id'] ?? null;
$examset_id = $data['examset_id'] ?? null;

// ตรวจสอบข้อมูล
if (!$student_id || !$slot_id || !$examset_id) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

// Ensure all IDs are integer for binding
$student_id = (int)$student_id;
$slot_id = (int)$slot_id;
$examset_id = (int)$examset_id;

// (ยกเลิกการตรวจสอบซ้ำ: อนุญาตให้จองซ้ำได้)

// ตรวจสอบจำนวนคนที่ลงทะเบียนแล้วใน slot นี้
try {
    $slotCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM exambooking WHERE slot_id = ?");
    if (!$slotCheckStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $slotCheckStmt->bind_param("i", $slot_id);
    $slotCheckStmt->execute();
    $slotCount = $slotCheckStmt->get_result()->fetch_assoc()['count'];

    $maxStmt = $conn->prepare("SELECT max_seats FROM exam_slots WHERE id = ?");
    if (!$maxStmt) throw new Exception('Prepare failed: ' . $conn->error);
    $maxStmt->bind_param("i", $slot_id);
    $maxStmt->execute();
    $maxSeats = $maxStmt->get_result()->fetch_assoc()['max_seats'];

    if ($slotCount >= $maxSeats) {
        http_response_code(409);
        echo json_encode(["status" => "error", "message" => "This slot is already full"]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB error: ".$e->getMessage()]);
    exit;
}

// บันทึกลงทะเบียนสอบ
try {
    $stmt = $conn->prepare("INSERT INTO exambooking (student_id, examset_id, slot_id, scheduled_at, status) VALUES (?, ?, ?, NOW(), 'registered')");
    if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);
    $stmt->bind_param("iii", $student_id, $examset_id, $slot_id);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Booking successful"]);
    } else {
        throw new Exception($stmt->error);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "DB error: ".$e->getMessage()]);
    exit;
}
?>
