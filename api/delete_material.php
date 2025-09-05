<?php
require_once '../config/db.php';
require_once 'helpers/auth_helper.php';  // ✅ แก้ตรงนี้

header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบว่าเป็น POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// ตรวจสอบ token
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
try {
    $decoded = decodeToken($token);
    $instructor_id = $decoded->instructor_id ?? null;
    if (!$instructor_id) throw new Exception('Invalid token payload');
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// รับข้อมูล material_id
$data = json_decode(file_get_contents('php://input'), true);
$material_id = $data['material_id'] ?? null;
if (!$material_id) {
    echo json_encode(['status' => 'error', 'message' => 'Material ID is required']);
    exit;
}

try {
    // ดึงชื่อไฟล์
    $stmt = $pdo->prepare("SELECT file_path FROM teaching_materials WHERE material_id = ? AND instructor_id = ?");
    $stmt->execute([$material_id, $instructor_id]);
    $material = $stmt->fetch();

    if (!$material) {
        echo json_encode(['status' => 'error', 'message' => 'Material not found or unauthorized']);
        exit;
    }

    // ลบไฟล์จริง
    $filePath = '../uploads/materials/' . $material['file_path'];
    if (is_file($filePath)) {
        unlink($filePath);
    }

    // ลบจากฐานข้อมูล
    $stmt = $pdo->prepare("DELETE FROM teaching_materials WHERE material_id = ? AND instructor_id = ?");
    $stmt->execute([$material_id, $instructor_id]);

    echo json_encode(['status' => 'success', 'message' => 'Material deleted successfully']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error',
        'debug' => $e->getMessage()
    ]);
}
