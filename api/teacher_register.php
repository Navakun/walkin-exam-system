<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$DEBUG = true; // ทดสอบเสร็จ ให้ตั้งเป็น false

// ดัก fatal error ทุกชนิดให้แสดงเป็น JSON
register_shutdown_function(function () use ($DEBUG) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_length()) ob_clean();
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => $DEBUG ? ('FATAL: ' . $e['message'] . ' @ ' . $e['file'] . ':' . $e['line']) : 'ข้อผิดพลาดภายในระบบ',
        ], JSON_UNESCAPED_UNICODE);
    }
});

// ชี้ไปยัง public_html/config/db.php
$ROOT = dirname(__DIR__);                    // = .../public_html
$DBCFG = $ROOT . '/config/db.php';           // = .../public_html/config/db.php
if (!is_file($DBCFG) || !is_readable($DBCFG)) {
    if (function_exists('respond')) {
        respond('error', 'Config not found: ' . $DBCFG, 500);
    } else {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Config not found: ' . $DBCFG], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
require_once $DBCFG;

// ---- helpers (fallback หาก mbstring ไม่มี) ----
function upcase($s)
{
    return function_exists('mb_strtoupper') ? mb_strtoupper($s, 'UTF-8') : strtoupper($s);
}
function locase($s)
{
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
function mblen($s)
{
    return function_exists('mb_strlen')     ? mb_strlen($s, 'UTF-8')     : strlen($s);
}

function respond($status, $message, $http = 200)
{
    if (ob_get_length()) ob_clean();
    http_response_code($http);
    echo json_encode(['status' => $status, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        respond('error', 'Method not allowed', 405);
    }

    // --- STEP A: normalize + validate ---
    $instructor_id = upcase(trim($_POST['instructor_id'] ?? ''));
    $name          = trim($_POST['name'] ?? '');
    $email         = locase(trim($_POST['email'] ?? ''));
    $password      = (string)($_POST['password'] ?? '');

    if ($instructor_id === '' || $name === '' || $email === '' || $password === '') respond('error', 'กรุณากรอกข้อมูลให้ครบทุกช่อง', 400);
    if (mblen($instructor_id) > 15) respond('error', 'รหัสอาจารย์ยาวเกิน 15 ตัว', 400);
    if (mblen($name) > 100)         respond('error', 'ชื่อ-นามสกุลยาวเกิน 100 ตัว', 400);
    if (mblen($email) > 120)        respond('error', 'อีเมลยาวเกิน 120 ตัว', 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond('error', 'อีเมลไม่ถูกต้อง', 400);
    if (strlen($password) < 8) respond('error', 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 400);

    // --- STEP B: DB ping ---
    try {
        $pdo->query('SELECT 1');
    } catch (Throwable $e) {
        respond('error', $DEBUG ? ('DB connect: ' . $e->getMessage()) : 'เชื่อมต่อฐานข้อมูลล้มเหลว', 500);
    }

    // --- STEP C: duplicate check ---
    $chk = $pdo->prepare("SELECT 
      SUM(CASE WHEN instructor_id=? THEN 1 ELSE 0 END) AS id_dup,
      SUM(CASE WHEN email=? THEN 1 ELSE 0 END)         AS email_dup
    FROM instructor WHERE instructor_id=? OR email=?");
    $chk->execute([$instructor_id, $email, $instructor_id, $email]);
    $dup = $chk->fetch(PDO::FETCH_ASSOC) ?: ['id_dup' => 0, 'email_dup' => 0];
    if ((int)$dup['id_dup'] > 0)   respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
    if ((int)$dup['email_dup'] > 0) respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);

    // --- STEP D: insert ---
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $pdo->prepare("INSERT INTO instructor (instructor_id,name,email,password) VALUES (?,?,?,?)");
    $ins->execute([$instructor_id, $name, $email, $hash]);

    respond('success', 'สมัครสมาชิกสำเร็จ! กรุณาเข้าสู่ระบบ');
} catch (PDOException $e) {
    // duplicate (23000) → re-check ให้บอกสาเหตุชัด
    if ($e->getCode() === '23000') {
        try {
            $re = $pdo->prepare("SELECT instructor_id,email FROM instructor WHERE instructor_id=? OR email=? LIMIT 1");
            $re->execute([$instructor_id, $email]);
            $row = $re->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['instructor_id'] ?? '') === $instructor_id) respond('error', 'รหัสอาจารย์นี้มีอยู่แล้ว', 409);
            if ($row && locase($row['email'] ?? '') === $email)           respond('error', 'อีเมลนี้มีอยู่แล้ว', 409);
        } catch (Throwable $e2) {
        }
        respond('error', 'ข้อมูลซ้ำในระบบ (รหัส/อีเมล)', 409);
    }
    respond('error', $DEBUG ? ('DB: ' . $e->getMessage()) : 'เกิดข้อผิดพลาดในระบบฐานข้อมูล', 500);
} catch (Throwable $e) {
    respond('error', $DEBUG ? ('ERR: ' . $e->getMessage()) : 'ข้อผิดพลาดภายในระบบ', 500);
}
