<?php
require_once __DIR__ . '/helpers/jwt_helper.php'; // ใช้ getJwtKey()
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

/**
 * สร้าง JWT Token สำหรับผู้ใช้
 * 
 * @param array $payload ข้อมูลใน token เช่น ['id' => 'I001', 'role' => 'teacher']
 * @param int $expire_seconds อายุ token เป็นวินาที (default: 1 ชั่วโมง)
 * @return string JWT token
 */
function generate_jwt(array $payload, int $expire_seconds = 3600): string
{
    $key = getJwtKey();
    $issuedAt = time();
    $expire = $issuedAt + $expire_seconds;

    // รวมข้อมูลของระบบ (iat, exp) กับข้อมูลจาก $payload
    $tokenData = array_merge($payload, [
        'iat' => $issuedAt,
        'exp' => $expire
    ]);

    // สร้างและคืน JWT
    return JWT::encode($tokenData, $key, 'HS256');
}


