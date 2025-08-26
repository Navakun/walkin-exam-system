<?php
// เชื่อมต่อฐานข้อมูล
$conn = new mysqli('localhost', 'root', '28012547', 'walkin_exam_db');
if ($conn->connect_error) {
    die("การเชื่อมต่อล้มเหลว: " . $conn->connect_error);
}

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

// เตรียมคำสั่ง SQL ให้ตรงกับ schema จริง
error_log("DEBUG register: preparing insert statement");
$stmt = $conn->prepare("INSERT INTO Student (student_id, name, email, password) VALUES (?, ?, ?, ?)");
$stmt->bind_param("ssss", $student_id, $name, $email, $hashed_password);

// ตรวจสอบว่ามี student_id หรือ username ซ้ำหรือไม่
error_log("DEBUG register: checking duplicate");
$check_stmt = $conn->prepare("SELECT student_id FROM Student WHERE student_id = ? OR email = ? LIMIT 1");
$check_stmt->bind_param("ss", $student_id, $email);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result === false) {
    error_log("DEBUG register: duplicate check failed: " . $check_stmt->error);
}

if ($result && $result->num_rows > 0) {
    http_response_code(400);
    error_log("DEBUG register: duplicate found");
    echo json_encode([
        'status' => 'error',
        'error' => 'รหัสนิสิตหรืออีเมลนี้ถูกใช้งานแล้ว'
    ]);
    exit;
}
$check_stmt->close();


// บันทึกข้อมูล
try {
    error_log("DEBUG register: executing insert");
    if ($stmt->execute()) {
        http_response_code(201);
        error_log("DEBUG register: success");
        echo json_encode([
            'status' => 'success',
            'message' => 'ลงทะเบียนสำเร็จ',
            'redirect' => 'login.html'
        ]);
    } else {
        error_log("DEBUG register: insert error: " . $stmt->error);
        throw new Exception($stmt->error);
    }
} catch (Exception $e) {
    // ตรวจสอบ error code สำหรับ duplicate entry
    error_log("DEBUG register: exception: " . $e->getMessage());
    if ($e->getCode() == 1062) {
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

$stmt->close();
$conn->close();
?>
