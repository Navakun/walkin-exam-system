<?php
require_once '../config/db.php';

// รับค่าจากฟอร์ม
$student_id = $_POST['student_id'] ?? '';
$name = $_POST['name'] ?? '';
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
error_log("DEBUG register: student_id=$student_id, name=$name, email=$email");

// ตรวจสอบข้อมูลที่จำเป็น
if (empty($student_id) || empty($name) || empty($email) || empty($password)) {
    http_response_code(400);
    error_log("DEBUG register: missing fields");
    echo json_encode(['error' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

// เข้ารหัสรหัสผ่าน
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// ตรวจสอบว่ามี student_id หรือ email ซ้ำหรือไม่
error_log("DEBUG register: checking duplicate");
$check_stmt = $pdo->prepare("SELECT student_id FROM Student WHERE student_id = ? OR email = ? LIMIT 1");
$check_stmt->execute([$student_id, $email]);
$result = $check_stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    http_response_code(400);
    error_log("DEBUG register: duplicate found");
    echo json_encode([
        'status' => 'error',
        'error' => 'รหัสนิสิตหรืออีเมลนี้ถูกใช้งานแล้ว'
    ]);
    exit;
}

// บันทึกข้อมูล
try {
    error_log("DEBUG register: executing insert");
    $stmt = $pdo->prepare("INSERT INTO Student (student_id, name, email, password) VALUES (?, ?, ?, ?)");
    $stmt->execute([$student_id, $name, $email, $hashed_password]);
    http_response_code(201);
    error_log("DEBUG register: success");
    echo json_encode([
        'status' => 'success',
        'message' => 'ลงทะเบียนสำเร็จ',
        'redirect' => 'login.html'
    ]);
} catch (PDOException $e) {
    // ตรวจสอบ error code สำหรับ duplicate entry
    error_log("DEBUG register: exception: " . $e->getMessage());
    if ($e->getCode() == 23000) { // PDO duplicate entry
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'error' => 'รหัสนิสิตหรืออีเมลนี้ถูกใช้งานแล้ว'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'error' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ]);
    }
}
?>
