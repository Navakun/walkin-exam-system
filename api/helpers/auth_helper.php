<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

function verifyJwtToken($token) {
    $secretKey = getJwtKey(); 
    try {
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
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

function decodeToken(string $token) {
    try {
        if ($token === '') return null;
        $key = getJwtKey();
        return json_decode(json_encode(JWT::decode($token, new Key($key, 'HS256'))), true);
    } catch (Throwable $e) {
        error_log('❌ JWT Decode Error: ' . $e->getMessage());
        return null;
    }
}

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

/**
 * ดึง JWT token จาก Authorization Header
 */
function getBearerToken(): ?string {
    // Debug: บันทึก headers ทั้งหมด
    error_log('Headers ทั้งหมด: ' . print_r(getallheaders(), true));
    error_log('$_SERVER: ' . print_r($_SERVER, true));

    // ลองใช้ getallheaders() ก่อน
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        // ตรวจสอบทั้งตัวพิมพ์ใหญ่และเล็ก
        foreach(['Authorization', 'authorization'] as $key) {
            if (isset($headers[$key])) {
                $auth = $headers[$key];
                error_log("พบ token ใน header '$key': $auth");
                return trim(str_replace('Bearer', '', $auth));
            }
        }
    }

    // ถ้าไม่พบใน headers ให้ลองหาใน $_SERVER
    $serverKeys = ['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'];
    foreach ($serverKeys as $key) {
        if (isset($_SERVER[$key])) {
            $auth = $_SERVER[$key];
            error_log("พบ token ใน $_SERVER[$key]: $auth");
            return trim(str_replace('Bearer', '', $auth));
        }
    }

    error_log('ไม่พบ token ในทุกตำแหน่ง');
    return null;
}
