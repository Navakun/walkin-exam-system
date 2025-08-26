<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../config/db.php';

// ตรวจสอบ Authorization
$headers = getallheaders();
$auth_header = isset($headers['Authorization']) ? $headers['Authorization'] : '';
if (empty($auth_header) || !preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token']);
    exit;
}

$token = $matches[1];
// TODO: ตรวจสอบ token กับฐานข้อมูล

// รับข้อมูล JSON จาก request body
$data = json_decode(file_get_contents('php://input'), true);

// ตรวจสอบ slot_id
if (!isset($data['slot_id'])) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'ไม่พบรหัสช่วงเวลาสอบ'
    ]);
    exit;
}

try {
    // ตรวจสอบว่ามีการจองแล้วหรือไม่
    $check_sql = "SELECT COUNT(*) FROM exambooking WHERE slot_id = :slot_id";
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute([':slot_id' => $data['slot_id']]);
    
    if ($check_stmt->fetchColumn() > 0) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'ไม่สามารถลบช่วงเวลาสอบนี้ได้เนื่องจากมีผู้ลงทะเบียนแล้ว'
        ]);
        exit;
    }

    // ลบช่วงเวลาสอบ
    $sql = "DELETE FROM exam_slots WHERE id = :slot_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':slot_id' => $data['slot_id']]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'ไม่พบช่วงเวลาสอบที่ระบุ'
        ]);
        exit;
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'ลบช่วงเวลาสอบสำเร็จ'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
    ]);
}
