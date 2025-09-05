<?php
declare(strict_types=1);

// เปิด error log สำหรับพัฒนา
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// โหลด helper และ config
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/auth_helper.php';

try {
    // ตรวจสอบและถอดรหัส token
    $token = getBearerToken();
    if (!$token) {
        throw new Exception('Unauthorized: Token not found', 401);
    }

    $payload = decodeToken($token);
    if (!isset($payload->instructor_id)) {
        throw new Exception('Access denied: Not an instructor', 403);
    }

    // ตรวจสอบ parameter
    if (!isset($_GET['id'])) {
        throw new Exception('Missing material ID', 400);
    }

    $material_id = intval($_GET['id']);
    $instructor_id = $payload->instructor_id;

    // ดึงข้อมูลจากฐานข้อมูล
    $stmt = pdo()->prepare("
        SELECT material_id, title, description, file_path, file_size, upload_date, download_count
        FROM teaching_materials
        WHERE material_id = ? AND instructor_id = ?
    ");
    $stmt->execute([$material_id, $instructor_id]);
    $material = $stmt->fetch();

    if (!$material) {
        throw new Exception('Material not found', 404);
    }

    // ตรวจสอบว่าไฟล์มีอยู่จริง
    $file_path = __DIR__ . '/../uploads/materials/' . $material['file_path'];
    if (!file_exists($file_path)) {
        throw new Exception('File not found: ' . basename($material['file_path']), 404);
    }

    // แปลงวันที่ให้อยู่ในรูปแบบที่อ่านง่าย
    $material['upload_date'] = date('Y-m-d H:i:s', strtotime($material['upload_date']));

    echo json_encode([
        'status' => 'success',
        'material' => $material
    ]);
    exit;

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
