<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) {
    throw new ErrorException($m, 0, $s, $f, $l);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        if (function_exists('ob_get_length') && ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'SERVER_ERROR', 'debug' => $e['message']]);
    }
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

$h = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($h['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $h['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'NO_TOKEN']);
    exit;
}
$jwt = $m[1];
$user = verifyJwtToken($jwt);
if (!$user || ($user['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'FORBIDDEN']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$name            = trim((string)($body['name'] ?? ''));
$email           = trim((string)($body['email'] ?? ''));
$current_pass    = (string)($body['current_password'] ?? '');
$new_pass        = (string)($body['new_password'] ?? '');

if ($name === '' || $email === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรอกชื่อและอีเมล']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'อีเมลไม่ถูกต้อง']);
    exit;
}

$instructor_id = $user['instructor_id'];

$pdo->beginTransaction();

// ตรวจอีเมลซ้ำ (ยกเว้นของตัวเอง)
$chk = $pdo->prepare("SELECT 1 FROM instructor WHERE email = :email AND instructor_id <> :id LIMIT 1");
$chk->execute([':email' => $email, ':id' => $instructor_id]);
if ($chk->fetchColumn()) {
    $pdo->rollBack();
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'อีเมลนี้ถูกใช้แล้ว']);
    exit;
}

// ถ้ามีขอเปลี่ยนรหัส: ต้องตรวจรหัสเดิม
if ($new_pass !== '') {
    if (strlen($new_pass) < 8) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านใหม่อย่างน้อย 8 ตัวอักษร']);
        exit;
    }
    $q = $pdo->prepare("SELECT password FROM instructor WHERE instructor_id = :id");
    $q->execute([':id' => $instructor_id]);
    $hash = (string)$q->fetchColumn();
    if (!$hash || !password_verify($current_pass, $hash)) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        exit;
    }

    $newHash = password_hash($new_pass, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE instructor SET name=:name, email=:email, password=:pw WHERE instructor_id=:id");
    $up->execute([':name' => $name, ':email' => $email, ':pw' => $newHash, ':id' => $instructor_id]);
} else {
    // เปลี่ยนเฉพาะชื่อ/อีเมล
    $up = $pdo->prepare("UPDATE instructor SET name=:name, email=:email WHERE instructor_id=:id");
    $up->execute([':name' => $name, ':email' => $email, ':id' => $instructor_id]);
}

$pdo->commit();
echo json_encode(['status' => 'success', 'message' => 'อัปเดตสำเร็จ'], JSON_UNESCAPED_UNICODE);
