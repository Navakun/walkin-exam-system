<?php
// teacher_register.php (API)
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$DEBUG = false; // เปิดเฉพาะตอนดีบัก เสร็จแล้วตั้งเป็น false

require_once __DIR__ . '/db.php';

function respond($status, $message, $http = 200)
{
    // ล้าง output อื่น ๆ ที่อาจถูก echo มาก่อน (กัน JSON พัง)
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        respond('error', 'Method not allowed', 405);
    }

    // รับข้อมูล + normalize
    $instructor_id = mb_strtoupper(trim($_POST['instructor_id'] ?? ''), 'UTF-8');
    $name          = trim($_POST['name'] ?? '');
    $email         = mb_strtolower(trim($_POST['email'] ?? ''), 'UTF-8');
    $password      = (string)($_POST['password'] ?? '');

    // ตรวจค่าพื้นฐาน
    if ($instructor_id === '' || $name === '' || $email === '' || $password === '') {
        respond('error', 'กรุณากรอกข้อมูลให้ครบทุกช่อง', 400);
    }
    if (mb_strlen($instructor_id, 'UTF-8') > 15) respond('error', 'รหัสอาจารย์ยาวเกิน 15 ตัว', 400);
    if (mb_strlen($name, 'UTF-8') > 100)        respond('error', 'ชื่อ-นามสกุลยาวเกิน 100 ตัว', 400);
    if (mb_strlen($email, 'UTF-8') > 120)       respond('error', 'อีเมลยาวเกิน 120 ตัว', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond('error', 'อีเมลไม่ถูกต้อง', 400);
    if (strlen($password) < 8) respond('error', 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 400);

    // ตรวจซ้ำเร็ว ๆ (ช่วยให้ข้อความเป็นมิตร ก่อนชน UNIQUE)
    $chk = $pdo->prepare("
        SELECT 
          SUM(CASE WHEN instructor_id = ? THEN 1 ELSE 0 END) AS id_dup,
          SUM(CASE WHEN email = ? THEN 1 ELSE 0 END)         AS email_dup
        FROM instructor
        WHERE instructor_id = ? OR email = ?
    ");
    $chk->execute([$instructor_id, $email, $instructor_id, $email]);
    $dup = $chk->fetch(PDO::FETCH_ASSOC) ?: ['id_dup' => 0, 'email_dup' => 0];
    if ((int)$dup['id_dup'] > 0)   respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
    if ((int)$dup['email_dup'] > 0) respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);

    // แฮชรหัสผ่าน
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // บันทึก
    $ins = $pdo->prepare("
        INSERT INTO instructor (instructor_id, name, email, password)
        VALUES (?, ?, ?, ?)
    ");
    $ins->execute([$instructor_id, $name, $email, $hash]);

    respond('success', 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ', 200);
} catch (PDOException $e) {
    // ถ้าเป็นข้อผิดพลาด constraint (SQLSTATE 23000) ให้ re-check ใน DB ว่าซ้ำตรงไหน
    if ($e->getCode() === '23000') {
        try {
            $re = $pdo->prepare("SELECT instructor_id, email FROM instructor WHERE instructor_id = ? OR email = ? LIMIT 1");
            $re->execute([$instructor_id, $email]);
            $row = $re->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['instructor_id']) && $row['instructor_id'] === $instructor_id) {
                    respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
                }
                if (isset($row['email']) && mb_strtolower($row['email'], 'UTF-8') === $email) {
                    respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);
                }
            }
            // ถ้าเช็กแล้วยังไม่ชัด ให้ส่ง generic
            respond('error', 'ข้อมูลซ้ำในระบบ (รหัส/อีเมล)', 409);
        } catch (Throwable $e2) {
            // ถ้า re-check พัง ให้ตกไปด้านล่าง
        }
    }

    // ข้อผิดพลาดอื่น ๆ
    $msg = $DEBUG ? ('DB [' . $e->getCode() . ']: ' . $e->getMessage()) : 'เกิดข้อผิดพลาดในระบบฐานข้อมูล';
    respond('error', $msg, 500);
} catch (Throwable $e) {
    $msg = $DEBUG ? ('ERR: ' . $e->getMessage()) : 'ข้อผิดพลาดภายในระบบ';
    respond('error', $msg, 500);
}
