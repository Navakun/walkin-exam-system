<?php
// api/update_profile.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ---------- Helper: JSON out ----------
function jexit(int $code, array $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- Auth: Bearer JWT ----------
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if (!preg_match('/Bearer\s+(.+)$/i', $auth, $m)) {
    jexit(401, ['status' => 'error', 'message' => 'ไม่พบโทเคน (Authorization header)']);
}
$token = $m[1];

try {
    // $jwt_key ต้องมาจาก config/db.php (หรือไฟล์ env ของคุณ)
    if (!isset($jwt_key)) {
        jexit(500, ['status' => 'error', 'message' => 'เซิร์ฟเวอร์ไม่ได้ตั้งค่า jwt_key']);
    }
    $decoded = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $studentIdFromToken = $decoded->student_id ?? null;
    if (!$studentIdFromToken) {
        jexit(401, ['status' => 'error', 'message' => 'โทเคนไม่มีข้อมูล student_id']);
    }
} catch (Throwable $e) {
    error_log('[update_profile] JWT error: ' . $e->getMessage());
    jexit(401, ['status' => 'error', 'message' => 'โทเคนไม่ถูกต้องหรือหมดอายุ']);
}

// ---------- Read input (JSON or form) ----------
$raw = file_get_contents('php://input') ?: '';
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';

$in = [];
if (stripos($ct, 'application/json') !== false && $raw !== '') {
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        jexit(400, ['status' => 'error', 'message' => 'รูปแบบ JSON ไม่ถูกต้อง']);
    }
} else {
    // รองรับ form/multipart ด้วย
    $in = array_merge($_POST ?: [], $_FILES ?: []);
}

// ฟิลด์ที่แก้ไขได้
$name     = isset($in['name'])     ? trim((string)$in['name'])     : null;
$surname  = isset($in['surname'])  ? trim((string)$in['surname'])  : null;
$email    = isset($in['email'])    ? trim((string)$in['email'])    : null;
$password = isset($in['password']) ? (string)$in['password']       : null;

// ต้องมีอย่างน้อย 1 ฟิลด์
if (($name === null && $surname === null) && $email === null && ($password === null || $password === '')) {
    jexit(400, ['status' => 'error', 'message' => 'ไม่มีฟิลด์สำหรับอัปเดต']);
}

// ตรวจค่าพื้นฐาน
$updates = [];
$params  = [];

// ประกอบชื่อเต็มถ้ามีการแก้ไขชื่อหรือนามสกุล
if ($name !== null || $surname !== null) {
    // ดึงค่าเดิมมาใช้ถ้าไม่ได้แก้ไขส่วนใดส่วนหนึ่ง
    $currentName = $me['name'] ?? '';
    $parts = explode(' ', $currentName, 2);
    $currentFirst = $parts[0] ?? '';
    $currentLast = $parts[1] ?? '';

    // ใช้ค่าใหม่หรือค่าเดิม
    $firstName = $name ?? $currentFirst;
    $lastName = $surname ?? $currentLast;

    if ($firstName === '') {
        jexit(400, ['status' => 'error', 'message' => 'ชื่อห้ามว่าง']);
    }

    // ประกอบชื่อเต็ม
    $fullName = trim($lastName ? "$firstName $lastName" : $firstName);
    $updates[] = 'name = ?';
    $params[] = $fullName;
}

if ($email !== null) {
    if ($email === '') jexit(400, ['status' => 'error', 'message' => 'อีเมลห้ามว่าง']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jexit(400, ['status' => 'error', 'message' => 'รูปแบบอีเมลไม่ถูกต้อง']);
    }
}

if ($password !== null && $password !== '') {
    if (strlen($password) < 8) {
        jexit(400, ['status' => 'error', 'message' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร']);
    }
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $updates[] = 'password = ?';
    $params[]  = $hash;
}

try {
    // ตรวจว่า user มีอยู่จริง
    $stmt = $pdo->prepare('SELECT student_id, email, name FROM student WHERE student_id = ? LIMIT 1');
    $stmt->execute([$studentIdFromToken]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$me) {
        jexit(404, ['status' => 'error', 'message' => 'ไม่พบบัญชีผู้ใช้']);
    }

    // ถ้า email ต้องการเปลี่ยน ตรวจซ้ำกับคนอื่น
    if ($email !== null && $email !== $me['email']) {
        $chk = $pdo->prepare('SELECT 1 FROM student WHERE email = ? AND student_id <> ? LIMIT 1');
        $chk->execute([$email, $studentIdFromToken]);
        if ($chk->fetchColumn()) {
            jexit(400, ['status' => 'error', 'message' => 'อีเมลนี้ถูกใช้งานแล้ว']);
        }
        $updates[] = 'email = ?';
        $params[]  = $email;
    }

    if (empty($updates)) {
        // ไม่มีอะไรเปลี่ยนจริง ๆ
        jexit(200, [
            'status'  => 'success',
            'message' => 'ไม่มีการเปลี่ยนแปลง',
            'profile' => [
                'student_id' => $me['student_id'],
                'name'       => $me['name'],
                'email'      => $me['email'],
            ]
        ]);
    }

    $params[] = $studentIdFromToken;
    $sql = 'UPDATE student SET ' . implode(', ', $updates) . ' WHERE student_id = ?';
    $upd = $pdo->prepare($sql);
    $upd->execute($params);

    // อ่านค่าใหม่กลับไป
    $stmt2 = $pdo->prepare('SELECT student_id, name, email FROM student WHERE student_id = ? LIMIT 1');
    $stmt2->execute([$studentIdFromToken]);
    $updated = $stmt2->fetch(PDO::FETCH_ASSOC);

    // แยกชื่อ-นามสกุลเพื่อส่งกลับ
    $nameParts = explode(' ', $updated['name'], 2);

    jexit(200, [
        'status'  => 'success',
        'message' => 'บันทึกข้อมูลแล้ว',
        'profile' => $updated,
        'updated_name' => $updated['name'],  // ชื่อเต็มสำหรับอัปเดตชิป
        'name_parts' => [
            'first' => $nameParts[0] ?? '',
            'last' => $nameParts[1] ?? ''
        ]
    ]);
} catch (PDOException $e) {
    error_log('[update_profile] DB error: ' . $e->getMessage());
    // 23000 = duplicate/constraint
    if ($e->getCode() === '23000') {
        jexit(400, ['status' => 'error', 'message' => 'ข้อมูลซ้ำ (เช่น อีเมลซ้ำ)']);
    }
    jexit(500, ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์']);
} catch (Throwable $e) {
    error_log('[update_profile] Fatal: ' . $e->getMessage());
    jexit(500, ['status' => 'error', 'message' => 'เกิดข้อผิดพลาดของเซิร์ฟเวอร์']);
}
