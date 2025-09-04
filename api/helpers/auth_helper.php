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
