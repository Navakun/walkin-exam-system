<?php
declare(strict_types=1);

require_once '../api/generate_jwt.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function json_out(array $o, int $code = 200): void {
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

$err = null;

try {
    // DB
    require_once __DIR__ . '/../config/db.php';
    if (!isset($pdo)) { $err = 'PDO_NOT_SET'; throw new RuntimeException('PDO not set'); }

    // composer autoload
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) { $err = 'NO_AUTOLOAD'; throw new RuntimeException('vendor/autoload.php missing'); }
    require_once $autoload;

    // jwt helper
    $jwtHelper = __DIR__ . '/helpers/jwt_helper.php';
    if (!is_file($jwtHelper)) { $err = 'NO_JWT_HELPER'; throw new RuntimeException('jwt_helper missing'); }
    require_once $jwtHelper;

    // ===== รับ input: รองรับ JSON และ FormData =====
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;

    $username = '';
    $password = '';

    if (is_array($data)) {
        $username = trim((string)($data['username'] ?? $data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');
    }
    if ($username === '' && (!empty($_POST))) {
        $username = trim((string)($_POST['username'] ?? $_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
    }

    if ($username === '' || $password === '') {
        json_out(['status'=>'error','message'=>'กรุณากรอกชื่อผู้ใช้/อีเมล และรหัสผ่าน','error_code'=>'MISSING_FIELDS'], 400);
    }

    // ===== ดึงผู้ใช้ (ใช้ ? เพื่อตัดปัญหาชื่อพารามิเตอร์) =====
    $sql = "
        SELECT instructor_id, name, email, password
        FROM instructor
        WHERE instructor_id = ? OR email = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    if (!$stmt) {
        $err = 'PREPARE_FAIL';
        throw new RuntimeException('prepare failed');
    }

    // bind แบบลำดับ
    if (!$stmt->execute([$username, $username])) {
        $ei = $stmt->errorInfo();
        $err = 'EXECUTE_FAIL';
        throw new RuntimeException('execute failed: '.($ei[2] ?? 'unknown'));
    }

    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$instructor) {
        json_out(['status'=>'error','message'=>'ไม่พบบัญชีผู้ใช้','error_code'=>'NO_USER'], 401);
    }

    // ===== ตรวจรหัสผ่าน =====
    $stored = (string)$instructor['password'];
    $isHash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2i$') || str_starts_with($stored, '$argon2id$');
    $ok = $isHash ? password_verify($password, $stored) : hash_equals($stored, $password);
    if (!$ok) {
        json_out(['status'=>'error','message'=>'รหัสผ่านไม่ถูกต้อง','error_code'=>'BAD_PASSWORD'], 401);
    }

    // ===== ออก JWT =====
    if (!function_exists('getJwtKey')) { $err = 'JWT_HELPER_NOT_LOADED'; throw new RuntimeException('getJwtKey missing'); }
    $secret = getJwtKey();

    if (!class_exists(\Firebase\JWT\JWT::class)) { $err = 'JWT_CLASS_MISSING'; throw new RuntimeException('JWT class missing'); }

    $payload = [
        'instructor_id' => (string)$instructor['instructor_id'],
        'name' => html_entity_decode((string)$instructor['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email'         => (string)$instructor['email'],
        'role'          => 'teacher',
        'iat'           => time(),
        'exp'           => time() + 86400, // อายุ 1 วัน
    ];
    $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

    json_out([
        'status' => 'success',
        'token'  => $token,
        'instructor' => [
            'instructor_id' => $instructor['instructor_id'],
            'name'          => $instructor['name'],
            'email'         => $instructor['email'],
        ],
    ]);

} catch (Throwable $e) {
    error_log('teacher_login error ['.($err ?? 'UNSPECIFIED').']: '.$e->getMessage());
    json_out(['status'=>'error','message'=>'SERVER_ERROR','error_code'=>$err ?? 'UNSPECIFIED'], 500);
}

echo json_encode([
  'status' => 'success',
  'token' => $token,
  'user' => $user_data
]);
