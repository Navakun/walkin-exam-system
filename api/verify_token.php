<?php
// api/verify_token.php  — ใช้เป็น LIB, ไม่พ่น JSON เอง
declare(strict_types=1);

require_once __DIR__ . '/helpers/jwt_helper.php'; // ต้องมี decodeToken($jwt): array|object|null

/**
 * ดึงค่า Authorization header จากหลายทาง (รองรับ FPM/CGI/Reverse Proxy)
 */
function _getAuthorizationHeader(): ?string
{
    // A) มาตรฐาน
    if (function_exists('getallheaders')) {
        $hdrs = getallheaders();
        if (isset($hdrs['Authorization'])) return $hdrs['Authorization'];
        if (isset($hdrs['authorization'])) return $hdrs['authorization'];
    }
    // B) จาก $_SERVER
    foreach (
        [
            'HTTP_AUTHORIZATION',
            'REDIRECT_HTTP_AUTHORIZATION',
            'AUTHORIZATION',
        ] as $k
    ) {
        if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
    }
    // C) เผื่อ Proxy แปลก ๆ
    foreach ($_SERVER as $k => $v) {
        if (stripos($k, 'HTTP_AUTHORIZATION') !== false && !empty($v)) return (string)$v;
    }
    return null;
}

/**
 * คืน Bearer token ถ้าพบ (หรือ null ถ้าไม่พบ)
 * @param bool $allowQueryDebug เปิดรับ token จาก ?token= เฉพาะช่วงดีบั๊ก
 */
function getBearerToken(bool $allowQueryDebug = false): ?string
{
    $auth = _getAuthorizationHeader();
    if ($auth && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    if ($allowQueryDebug && isset($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }
    return null;
}

/**
 * ดีโค้ด JWT → array (หรือ object) ถ้าไม่ผ่าน ให้คืน null
 * อิงจาก helpers/jwt_helper.php ที่ Jisoo ใช้อยู่
 */
function decodeTokenSafe(?string $token)
{
    if (!$token) return null;
    try {
        // ถ้า helper ของ Jisoo ชื่อ decodeToken() อยู่แล้ว ใช้อันนี้ต่อได้เลย
        $decoded = decodeToken($token);
        // ปรับเป็น array ให้ใช้ง่าย
        if (is_object($decoded)) $decoded = (array)$decoded;
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * ตัวช่วยบังคับสิทธิ์ — ใช้ในแต่ละ API
 * คืน payload (array) ถ้าผ่าน, ถ้าไม่ผ่านจะส่ง 401 JSON แล้ว exit
 */
function requireAuth(array|string $roles = []): array
{
    // อย่าให้ไฟล์นี้เผลอส่ง header ทุกครั้ง — header ให้ API ปลายทางเป็นคนกำหนดเอง
    $token = getBearerToken(/*allowQueryDebug*/false);
    if (!$token) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบ token'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $payload = decodeTokenSafe($token);
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือหมดอายุ'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ตรวจ role ถ้าอยากบังคับ
    if ($roles) {
        $roles = (array)$roles;
        $role = strtolower(strval($payload['role'] ?? $payload['user_role'] ?? ''));
        $ok = in_array($role, array_map('strtolower', $roles), true);
        if (!$ok) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึง'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $payload;
}
