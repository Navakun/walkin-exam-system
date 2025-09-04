<?php
declare(strict_types=1);

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
    // 1) DB
    require_once __DIR__ . '/../config/db.php';
    if (!isset($pdo)) { $err = 'PDO_NOT_SET'; throw new RuntimeException('PDO not set'); }

    // 2) composer autoload
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) { $err = 'NO_AUTOLOAD'; throw new RuntimeException('vendor/autoload.php missing'); }
    require_once $autoload;

    // 3) jwt helper (มี getJwtKey())
    $jwtHelper = __DIR__ . '/helpers/jwt_helper.php';
    if (!is_file($jwtHelper)) { $err = 'NO_JWT_HELPER'; throw new RuntimeException('jwt_helper missing'); }
    require_once $jwtHelper;

    // 4) อ่าน input: รองรับ JSON และ FormData
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;

    $student_id = '';
    $password   = '';

    if (is_array($data)) {
        // จากหน้า student_login.html จะส่ง { student_id, password }
        $student_id = trim((string)($data['student_id'] ?? ''));
        $password   = (string)($data['password'] ?? '');
        // เผื่อบางที่ใช้ email แทน student_id
        if ($student_id === '' && !empty($data['email'])) {
            $student_id = trim((string)$data['email']);
        }
    }
    if ($student_id === '' && !empty($_POST)) {
        $student_id = trim((string)($_POST['student_id'] ?? $_POST['email'] ?? ''));
        $password   = (string)($_POST['password'] ?? '');
    }

    if ($student_id === '' || $password === '') {
        json_out(['status'=>'error','message'=>'MISSING_FIELDS','error_code'=>'MISSING_FIELDS'], 400);
    }

    // 5) ดึงข้อมูลนักศึกษา (ลองหาโดย student_id หรือ email)
    $sql = "
        SELECT student_id, name, email, password
        FROM student
        WHERE student_id = ? OR email = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    if (!$stmt) { $err = 'PREPARE_FAIL'; throw new RuntimeException('prepare failed'); }

    if (!$stmt->execute([$student_id, $student_id])) {
        $ei = $stmt->errorInfo();
        $err = 'EXECUTE_FAIL';
        throw new RuntimeException('execute failed: '.($ei[2] ?? 'unknown'));
    }

    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        json_out(['status'=>'error','message'=>'USER_NOT_FOUND','error_code'=>'USER_NOT_FOUND'], 401);
    }

    // 6) ตรวจรหัสผ่าน (รองรับทั้งแบบแฮชและ plaintext ระหว่างพัฒนา)
    $stored = (string)$student['password'];
    $isHash = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon2i$') || str_starts_with($stored, '$argon2id$');
    $ok = $isHash ? password_verify($password, $stored) : hash_equals($stored, $password);

    if (!$ok) {
        json_out(['status'=>'error','message'=>'INVALID_PASSWORD','error_code'=>'INVALID_PASSWORD'], 401);
    }

    // 7) ออก JWT
    if (!function_exists('getJwtKey')) { $err = 'JWT_HELPER_NOT_LOADED'; throw new RuntimeException('getJwtKey missing'); }
    $secret = getJwtKey();

    if (!class_exists(\Firebase\JWT\JWT::class)) { $err = 'JWT_CLASS_MISSING'; throw new RuntimeException('JWT class missing'); }

    $payload = [
        'student_id' => (string)$student['student_id'],
        'name'       => (string)$student['name'],
        'email'      => (string)($student['email'] ?? ''),
        'iat'        => time(),
        'exp'        => time() + 86400, // อายุ 1 วัน
    ];
    $token = \Firebase\JWT\JWT::encode($payload, $secret, 'HS256');

    // รูปแบบผลลัพธ์ให้ตรงกับหน้าเว็บ (เรียก result.student.id)
    json_out([
        'status'  => 'success',
        'token'   => $token,
        'student' => [
            'id'    => $student['student_id'], // << สำคัญ: ต้องมี key ชื่อ id
            'name'  => $student['name'],
            'email' => $student['email'] ?? null,
        ],
    ]);

} catch (Throwable $e) {
    error_log('student_login error ['.($err ?? 'UNSPECIFIED').']: '.$e->getMessage());
    json_out(['status'=>'error','message'=>'SERVER_FATAL','error_code'=>$err ?? 'UNSPECIFIED'], 500);
}
