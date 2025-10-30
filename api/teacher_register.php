<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$DEBUG = false; // เปลี่ยนเป็น true เฉพาะช่วงดีบัก!

if ($DEBUG) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
}

require_once __DIR__ . '/db.php';

function respond($status, $message, $http = 200)
{
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond('error', 'Method not allowed', 405);

    $instructor_id = mb_strtoupper(trim($_POST['instructor_id'] ?? ''), 'UTF-8');
    $name          = trim($_POST['name'] ?? '');
    $email         = mb_strtolower(trim($_POST['email'] ?? ''), 'UTF-8');
    $password      = (string)($_POST['password'] ?? '');

    if ($instructor_id === '' || $name === '' || $email === '' || $password === '') {
        respond('error', 'กรุณากรอกข้อมูลให้ครบทุกช่อง', 400);
    }
    if (mb_strlen($instructor_id, 'UTF-8') > 15) respond('error', 'รหัสอาจารย์ยาวเกิน 15 ตัว', 400);
    if (mb_strlen($name, 'UTF-8') > 100)      respond('error', 'ชื่อ-นามสกุลยาวเกิน 100 ตัว', 400);
    if (mb_strlen($email, 'UTF-8') > 120)     respond('error', 'อีเมลยาวเกิน 120 ตัว', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond('error', 'อีเมลไม่ถูกต้อง', 400);
    if (strlen($password) < 8) respond('error', 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 400);

    // ตรวจซ้ำเร็ว ๆ
    $chk = $pdo->prepare("SELECT 
      SUM(CASE WHEN instructor_id = ? THEN 1 ELSE 0 END) AS id_dup,
      SUM(CASE WHEN email = ? THEN 1 ELSE 0 END) AS email_dup
    FROM instructor
    WHERE instructor_id = ? OR email = ?");
    $chk->execute([$instructor_id, $email, $instructor_id, $email]);
    $dup = $chk->fetch() ?: ['id_dup' => 0, 'email_dup' => 0];
    if ((int)$dup['id_dup'] > 0) respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
    if ((int)$dup['email_dup'] > 0) respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $ins = $pdo->prepare("INSERT INTO instructor (instructor_id, name, email, password)
                        VALUES (?, ?, ?, ?)");
    $ins->execute([$instructor_id, $name, $email, $hash]);

    respond('success', 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ');
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        $msg = $e->getMessage();
        if (stripos($msg, 'uk_instructor_email') !== false || stripos($msg, 'email') !== false) {
            respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);
        }
        respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
    }
    if ($GLOBALS['DEBUG']) respond('error', 'DB: ' . $e->getMessage(), 500);
    respond('error', 'เกิดข้อผิดพลาดในระบบฐานข้อมูล', 500);
} catch (Throwable $e) {
    if ($GLOBALS['DEBUG']) respond('error', 'ERR: ' . $e->getMessage(), 500);
    respond('error', 'ข้อผิดพลาดภายในระบบ', 500);
}
