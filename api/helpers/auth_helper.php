<?php
require_once __DIR__ . '/../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

/**
 * ดึง JWT key จาก config
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
 * Decode token แบบ object → array
 */
function decodeToken(string $token) {
    try {
        if ($token === '') return null;
        $key = getJwtKey();
        return json_decode(json_encode(JWT::decode($token, new Key($key, 'HS256'))));
    } catch (Throwable $e) {
        error_log('❌ JWT Decode Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Decode แล้วโยน exception ถ้า invalid
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

/**
 * Decode พร้อมจัดการ Unauthorized JSON response ทันที
 */
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

/**
 * ดึง JWT token จาก Authorization header หรือ $_SERVER
 */
function getBearerToken(): ?string {
    // Debug headers
    // error_log('Headers: ' . print_r(getallheaders(), true));
    // error_log('$_SERVER: ' . print_r($_SERVER, true));

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach(['Authorization', 'authorization'] as $key) {
            if (isset($headers[$key])) {
                return trim(str_replace('Bearer', '', $headers[$key]));
            }
        }
    }

    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (isset($_SERVER[$key])) {
            return trim(str_replace('Bearer', '', $_SERVER[$key]));
        }
    }

    return null;
}
