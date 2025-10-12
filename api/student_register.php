<?php
require_once '../config/db.php';

header('Content-Type: application/json; charset=utf-8');

// รับค่าจากฟอร์ม + ทำความสะอาด
$student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : '';
$name       = isset($_POST['name'])       ? trim($_POST['name'])       : '';
$email      = isset($_POST['email'])      ? trim($_POST['email'])      : '';
$password   = isset($_POST['password'])   ? (string)$_POST['password'] : '';

error_log("DEBUG register: student_id={$student_id}, name={$name}, email={$email}");

// ตรวจสอบข้อมูลที่จำเป็น
if ($student_id === '' || $name === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

// ตรวจรูปแบบอีเมล
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'รูปแบบอีเมลไม่ถูกต้อง']);
    exit;
}

// เข้ารหัสรหัสผ่าน (แนะนำให้กำหนด cost ตามสมควร)
$hashed_password = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

try {
    // ตรวจสอบว่า student_id หรือ email ซ้ำหรือไม่
    $check_stmt = $pdo->prepare("SELECT 1 FROM student WHERE student_id = ? OR email = ? LIMIT 1");
    $check_stmt->execute([$student_id, $email]);
    $duplicate = $check_stmt->fetchColumn();

    if ($duplicate) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'รหัสนิสิตหรืออีเมลนี้ถูกใช้งานแล้ว']);
        exit;
    }

    // บันทึกข้อมูล
    $stmt = $pdo->prepare("
        INSERT INTO student (student_id, name, email, password)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$student_id, $name, $email, $hashed_password]);

    http_response_code(201);
    echo json_encode([
        'status'   => 'success',
        'message'  => 'ลงทะเบียนสำเร็จ',
        'redirect' => 'student_login.html'
    ]);
    exit;
} catch (PDOException $e) {
    error_log("DEBUG register: exception: " . $e->getMessage());

    // รหัส 23000 = integrity constraint violation (เช่น duplicate key)
    if ($e->getCode() === '23000') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'รหัสนิสิตหรืออีเมลนี้ถูกใช้งานแล้ว']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์']);
    }
    exit;
}
