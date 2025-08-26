<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../vendor/autoload.php';
require_once '../config/db.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

try {
    // Get and decode JSON input
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);

    if (!is_array($input)) {
        throw new Exception('Invalid JSON data');
    }

    // Validate required fields
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        throw new Exception('กรุณากรอกข้อมูลให้ครบถ้วน');
    }

    // Find instructor
    $stmt = $pdo->prepare("SELECT * FROM instructor WHERE email = ? OR instructor_id = ? LIMIT 1");
    $stmt->execute([$username, $username]);
    $instructor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$instructor) {
        throw new Exception('ไม่พบบัญชีผู้ใช้');
    }

    // Verify password
    if (!password_verify($password, $instructor['password'])) {
        throw new Exception('รหัสผ่านไม่ถูกต้อง');
    }

    // Generate JWT token
    $secret_key = "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef";
    $payload = array(
        "iat" => time(),
        "exp" => time() + (60 * 60 * 24),
        "id" => $instructor['instructor_id'],
        "name" => $instructor['name'],
        "email" => $instructor['email'],
        "role" => "teacher"
    );

    $jwt = JWT::encode($payload, $secret_key, 'HS256');

    // Send success response
    echo json_encode([
        'status' => 'success',
        'token' => $jwt,
        'instructor' => [
            'id' => $instructor['instructor_id'],
            'name' => $instructor['name'],
            'email' => $instructor['email']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
