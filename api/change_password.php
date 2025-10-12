<?php
// api/change_password.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/* ---------- helpers ---------- */

function jerr(int $code, string $msg, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function jin(): array
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw ?? '', true);
    return is_array($d) ? $d : [];
}

/* ---------- auth (JWT) ---------- */
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) jerr(401, 'ไม่พบ token');
$token = trim($m[1]);

try {
    /** @var string $jwt_key */
    $jwt = JWT::decode($token, new Key($jwt_key, 'HS256'));
    $student_id = $jwt->student_id ?? null;
    if (!$student_id) jerr(401, 'Token ไม่มี student_id');
} catch (Throwable $e) {
    jerr(401, 'Token ไม่ถูกต้องหรือหมดอายุ', ['debug' => $e->getMessage()]);
}

/* ---------- input ---------- */
$in = jin();
$current = trim((string)($in['current_password'] ?? ''));
$new     = trim((string)($in['new_password'] ?? ''));
$confirm = trim((string)($in['confirm_password'] ?? ''));
if ($current === '' || $new === '' || $confirm === '') {
    jerr(400, 'กรุณากรอกรหัสผ่านให้ครบถ้วน');
}
if ($new !== $confirm) {
    jerr(422, 'รหัสผ่านใหม่และยืนยันรหัสผ่านไม่ตรงกัน');
}

/* ---------- password policy (แก้ตามต้องการ) ---------- */
$minLen = 8;
if (mb_strlen($new) < $minLen) {
    jerr(422, "รหัสผ่านใหม่ต้องยาวอย่างน้อย {$minLen} ตัวอักษร");
}
// แนะนำแต่ไม่บังคับ: มีตัวพิมพ์เล็ก/ใหญ่/ตัวเลข
$score = 0;
$score += preg_match('/[a-z]/', $new) ? 1 : 0;
$score += preg_match('/[A-Z]/', $new) ? 1 : 0;
$score += preg_match('/\d/',   $new) ? 1 : 0;
if ($score < 2) {
    jerr(422, 'รหัสผ่านใหม่ควรมีอย่างน้อย 2 ใน 3: ตัวพิมพ์เล็ก/พิมพ์ใหญ่/ตัวเลข');
}

try {
    /* ---------- ดึง hash ปัจจุบัน ---------- */
    $stmt = $pdo->prepare("SELECT password_hash FROM student WHERE student_id = :sid LIMIT 1");
    $stmt->execute([':sid' => $student_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) jerr(404, 'ไม่พบนิสิตในระบบ');

    $stored = (string)($row['password_hash'] ?? '');

    // รองรับกรณี legacy: เก็บ md5/plain (ไม่แนะนำ) → ตรวจแบบเดิมแล้ว “อัปเกรด” เป็น password_hash
    $verified = false;
    $needsRehash = false;

    if ($stored !== '' && str_starts_with($stored, '$2y$')) {
        // bcrypt/argon2 อื่น ๆ ใช้ password_verify
        $verified = password_verify($current, $stored);
        $needsRehash = $verified && password_needs_rehash($stored, PASSWORD_BCRYPT, ['cost' => 12]);
    } else {
        // legacy fallback (ถ้าเคยเก็บ md5 หรือ plaintext)
        if ($stored !== '') {
            if (hash_equals($stored, md5($current)) || hash_equals($stored, $current)) {
                $verified = true;
                $needsRehash = true; // อัปเกรด
            }
        } else {
            // ถ้าไม่มีรหัสผ่านเดิม (ไม่ควรเกิด) ถือว่าไม่ผ่าน
            $verified = false;
        }
    }

    if (!$verified) jerr(401, 'รหัสผ่านเดิมไม่ถูกต้อง');

    // ไม่ให้ใช้รหัสผ่านเดิมซ้ำ
    if ($stored && password_verify($new, $stored)) {
        jerr(422, 'รหัสผ่านใหม่ต้องแตกต่างจากรหัสผ่านเดิม');
    }

    $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);

    $upd = $pdo->prepare("UPDATE student SET password_hash = :ph WHERE student_id = :sid LIMIT 1");
    $upd->execute([':ph' => $newHash, ':sid' => $student_id]);

    echo json_encode([
        'status' => 'success',
        'message' => 'เปลี่ยนรหัสผ่านสำเร็จ'
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr(500, 'SERVER_ERROR', ['debug' => $e->getMessage()]);
}
