<?php
require_once '../config/db.php';

// ตรวจสอบ material_id
if (!isset($_GET['id']) || empty($_GET['id'])) {
    http_response_code(400);
    exit('Material ID is required');
}

$material_id = $_GET['id'];

try {
    // อัพเดทจำนวนดาวน์โหลด
    $stmt = $conn->prepare("UPDATE teaching_materials SET download_count = download_count + 1 WHERE material_id = ?");
    $stmt->bind_param("i", $material_id);
    $stmt->execute();

    // ดึงข้อมูลไฟล์
    $stmt = $conn->prepare("SELECT file_path, title FROM teaching_materials WHERE material_id = ?");
    $stmt->bind_param("i", $material_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $material = $result->fetch_assoc();

    if (!$material) {
        http_response_code(404);
        exit('Material not found');
    }

    $file = '../uploads/materials/' . $material['file_path'];
    if (!file_exists($file)) {
        http_response_code(404);
        exit('File not found');
    }

    // ส่งไฟล์ให้ดาวน์โหลด
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $material['title'] . '.pdf"');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    exit('Server error');
}
