<?php
require_once '../config/db.php';
require_once 'helpers/encode.php';

// ตรวจสอบว่าเป็น POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// ตรวจสอบ token
$headers = getallheaders();
if (!isset($headers['Authorization']) || empty($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

$token = str_replace('Bearer ', '', $headers['Authorization']);
try {
    $decoded = decodeToken($token);
    $instructor_id = $decoded->instructor_id;
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Invalid token']);
    exit;
}

// รับข้อมูล material_id
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['material_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Material ID is required']);
    exit;
}

$material_id = $data['material_id'];

try {
    // ดึงข้อมูลไฟล์ก่อนลบ
    $stmt = $conn->prepare("SELECT file_path FROM teaching_materials WHERE material_id = ? AND instructor_id = ?");
    $stmt->bind_param("is", $material_id, $instructor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $material = $result->fetch_assoc();

    if (!$material) {
        echo json_encode(['status' => 'error', 'message' => 'Material not found or unauthorized']);
        exit;
    }

    // ลบไฟล์
    $filePath = '../uploads/materials/' . $material['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }

    // ลบข้อมูลจากฐานข้อมูล
    $stmt = $conn->prepare("DELETE FROM teaching_materials WHERE material_id = ? AND instructor_id = ?");
    $stmt->bind_param("is", $material_id, $instructor_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'status' => 'success',
            'message' => 'Material deleted successfully'
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to delete material'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
