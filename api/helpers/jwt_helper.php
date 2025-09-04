<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;


/**
 * ถอดรหัส JWT token เป็น array (ใช้ key เดียวกับระบบ)
 */

function verifyJwtToken($token)
{
    $secretKey = getJwtKey(); // ✅ เปลี่ยนจาก hard-coded เป็นแบบ dynamic
    $alg = 'HS256';

    try {
        $decoded = JWT::decode($token, new Key($secretKey, $alg));
        return (array) $decoded;
    } catch (Exception $e) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized',
            'error_code' => 'BAD_TOKEN',
            'details' => $e->getMessage()
        ]);
        return null;
    }
}

function decodeToken(string $token)
{
    try {
        if ($token === '') return null;
        $key = getJwtKey();
        error_log("🔑 decodeToken: key = $key");
        $decoded = JWT::decode($token, new Key($key, 'HS256'));
        return json_decode(json_encode($decoded), true);
    } catch (Throwable $e) {
        error_log('❌ JWT Decode Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * ดึง JWT secret key (แบบ lazy load + cache)
 */
function getJwtKey(): string {
    static $key = null;
    if ($key === null) {
        $key = require __DIR__ . '/../../config/jwt_secret.php';
        if (!is_string($key) || $key === '') {
            throw new RuntimeException('Invalid JWT key configuration');
        }
    }
    return $key;
}

/**
 * แกะ JWT แล้วคืนค่า payload (array)
 * หากไม่ถูกต้องจะโยน Exception กลับ
 */

function decode_jwt($jwt_token) {
    $secretKey = getJwtKey();
    try {
        $decoded = JWT::decode($jwt_token, new Key($secretKey, 'HS256'));
        return json_decode(json_encode($decoded), true);
    } catch (ExpiredException $e) {
        throw new Exception('Token หมดอายุ: ' . $e->getMessage());
    } catch (Exception $e) {
        throw new Exception('JWT ไม่ถูกต้อง: ' . $e->getMessage());
    }
}


