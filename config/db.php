<?php
$DB_HOST = '127.0.0.1';   // หรือ 'localhost'
$DB_NAME = 'walkin_exam_db'; // ใส่ชื่อ database ของคุณ (ต้องมีจริงใน MySQL)
$DB_USER = 'root';        // user ของคุณ
$DB_PASS = '28012547';            // รหัสผ่าน (ถ้า XAMPP ปกติจะว่าง)

$servername = $DB_HOST;
$dbname = $DB_NAME;
$username = $DB_USER;
$password = $DB_PASS;

$dsn = "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

try {
    error_log("Attempting to connect to database: $DB_NAME");
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
    error_log("Successfully connected to database (PDO)");
    // เพิ่ม MySQLi connection
    $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
    if ($conn->connect_error) {
        throw new Exception('MySQLi connect error: ' . $conn->connect_error);
    }
    error_log("Successfully connected to database (MySQLi)");
} catch (Exception $e) {
    error_log("Database connection error: " . $e->getMessage());
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    die(json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการเชื่อมต่อฐานข้อมูล',
        'error' => $e->getMessage(),
        'details' => [
            'host' => $DB_HOST,
            'database' => $DB_NAME
        ]
    ]));
}


