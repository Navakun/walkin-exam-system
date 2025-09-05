<?php
// เปิด error_reporting เพื่อดู error ในช่วงพัฒนา
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');

// ตรวจสอบและโหลดไฟล์ที่จำเป็น
$requiredFiles = [
    __DIR__ . '/helpers/auth_helper.php',
    __DIR__ . '/../config/db.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        error_log("Missing required file: $file");
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => "Missing required file: " . basename($file)
        ]);
        exit;
    }
}

require_once __DIR__ . '/helpers/auth_helper.php';
require_once __DIR__ . '/../config/db.php';

try {
    // ตรวจสอบ token
    $token = getBearerToken();
    if (!$token) {
        throw new Exception('Unauthorized: Token not found', 401);
    }

    // Debug: แสดงค่า token
    error_log("Token: $token");

    $payload = decodeToken($token);

    // Debug: แสดงค่า payload
    error_log("Payload: " . print_r($payload, true));

    if (!isset($payload->instructor_id)) {
        throw new Exception('Access denied: Not an instructor', 403);
    }

    // ตรวจสอบ parameter
    if (!isset($_GET['id'])) {
        throw new Exception('Missing material ID', 400);
    }

    $material_id = intval($_GET['id']);

    try {
        $db = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        throw new Exception('Database connection failed', 500);
    }

    // ดึงข้อมูลสื่อการสอน
    $stmt = $db->prepare("
        SELECT material_id, title, description, file_path, file_size, upload_date, download_count 
        FROM teaching_materials 
        WHERE material_id = ? AND instructor_id = ?
    ");
    $stmt->execute([$material_id, $payload->instructor_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$material) {
        throw new Exception('Material not found', 404);
    }

    // แปลง upload_date เป็น format ที่ต้องการ
    $material['upload_date'] = date('Y-m-d H:i:s', strtotime($material['upload_date']));

    // ตรวจสอบว่าไฟล์มีอยู่จริง
    $file_path = __DIR__ . '/../uploads/materials/' . $material['file_path'];
    if (!file_exists($file_path)) {
        throw new Exception('File not found: ' . basename($material['file_path']), 404);
    }

    echo json_encode([
        'status' => 'success',
        'material' => $material
    ]);
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
} catch (PDOException $e) {
    error_log("Database error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error'
    ]);
}
