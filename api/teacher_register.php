<?php
// api/teacher_register.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php'; // ต้องมี $pdo (PDO) พร้อมโหมด PDO::ERRMODE_EXCEPTION

function respond($status, $message, $http = 200)
{
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond('error', 'Method not allowed', 405);
    }

    // รับค่าจากฟอร์ม
    $instructor_id = trim($_POST['instructor_id'] ?? '');
    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $password      = (string)($_POST['password'] ?? '');

    // ปรับรูปแบบเบื้องต้น
    $instructor_id = mb_strtoupper($instructor_id, 'UTF-8'); // ช่วยให้ ID คงรูป
    $email = mb_strtolower($email, 'UTF-8');

    // ตรวจค่าว่าง
    if ($instructor_id === '' || $name === '' || $email === '' || $password === '') {
        respond('error', 'กรุณากรอกข้อมูลให้ครบทุกช่อง', 400);
    }

    // ตรวจความยาวตามสคีมา
    if (mb_strlen($instructor_id, 'UTF-8') > 15) {
        respond('error', 'รหัสอาจารย์ยาวเกินกำหนด (ไม่เกิน 15 ตัวอักษร)', 400);
    }
    if (mb_strlen($name, 'UTF-8') > 100) {
        respond('error', 'ชื่อ-นามสกุลยาวเกินกำหนด (ไม่เกิน 100 ตัวอักษร)', 400);
    }
    if (mb_strlen($email, 'UTF-8') > 120) {
        respond('error', 'อีเมลยาวเกินกำหนด (ไม่เกิน 120 ตัวอักษร)', 400);
    }

    // ตรวจรูปแบบอีเมล + ความแข็งแรงของรหัสผ่าน
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond('error', 'อีเมลไม่ถูกต้อง', 400);
    }
    if (strlen($password) < 8) {
        respond('error', 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 400);
    }

    // ตรวจซ้ำ (optional แต่ช่วยคืนข้อความเร็ว ก่อนชน UNIQUE)
    $chk = $pdo->prepare("SELECT 
            SUM(CASE WHEN instructor_id = ? THEN 1 ELSE 0 END) AS id_dup,
            SUM(CASE WHEN email = ? THEN 1 ELSE 0 END) AS email_dup
        FROM instructor
        WHERE instructor_id = ? OR email = ?");
    $chk->execute([$instructor_id, $email, $instructor_id, $email]);
    $dup = $chk->fetch(PDO::FETCH_ASSOC) ?: ['id_dup' => 0, 'email_dup' => 0];
    if ((int)$dup['id_dup'] > 0) {
        respond('error', 'รหัสอาจารย์นี้มีอยู่ในระบบแล้ว', 409);
    }
    if ((int)$dup['email_dup'] > 0) {
        respond('error', 'อีเมลนี้มีอยู่ในระบบแล้ว', 409);
    }

    // แฮชรหัสผ่าน
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // บันทึก
    $ins = $pdo->prepare("INSERT INTO instructor (instructor_id, name, email, password) VALUES (?, ?, ?, ?)");
    $ins->execute([$instructor_id, $name, $email, $hash]);

    respond('success', 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ');
} catch (PDOException $e) {
    // จัดการเคส UNIQUE KEY โดนชน (กันกรณีแข่งกันยิง API)
    if ($e->getCode() === '23000') {
        // ตรวจจากข้อความว่าเป็น email หรือ id (ปลอดภัยพอในการแยกข้อความทั่วไป)
        $msg = $e->getMessage();
        if (stripos($msg, 'uk_instructor_email') !== false || stripos($msg, 'email') !== false) {
            respond('error', 'อีเมลนี้มีอยู่ในระบบแล้ว', 409);
        }
        respond('error', 'รหัสอาจารย์นี้มีอยู่ในระบบแล้ว', 409);
    }
    respond('error', 'เกิดข้อผิดพลาดในระบบฐานข้อมูล', 500);
} catch (Exception $e) {
    respond('error', 'ข้อผิดพลาด: ' . $e->getMessage(), 500);
}
