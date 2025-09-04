<?php
declare(strict_types=1);

/**
 * api/get_materials.php
 * ส่งรายการสื่อการสอนของอาจารย์ (จาก JWT) เป็น JSON ล้วน
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ---------- helpers ---------- */
function json_fail(int $status, string $msg): void {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function getAuthHeader(): string {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (isset($h['Authorization'])) return $h['Authorization'];
        if (isset($h['authorization'])) return $h['authorization'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_ENV['HTTP_AUTHORIZATION']))   return $_ENV['HTTP_AUTHORIZATION'];
    return '';
}

/* ---------- bootstrap ---------- */
$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) { require_once $autoload; }

require_once $root . '/config/db.php';
require_once $root . '/api/helpers/instructor_helper.php'; // ฟังก์ชัน getInstructorFromToken()

if (!isset($pdo)) {
    error_log('DB not connected');
    json_fail(500, 'SERVER_ERROR');
}

/* ---------- auth ---------- */
$auth = getAuthHeader();
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    error_log('Missing Authorization header');
    json_fail(401, 'Unauthorized');
}
$token = $m[1];

if (!function_exists('getInstructorFromToken')) {
    error_log('getInstructorFromToken() not defined');
    json_fail(500, 'SERVER_ERROR');
}
$instructor = getInstructorFromToken($token);
if (!$instructor || empty($instructor['instructor_id'])) {
    error_log('Invalid token or missing instructor_id');
    json_fail(401, 'Unauthorized');
}
$instructor_id = (string)$instructor['instructor_id'];

/* ---------- query ---------- */
try {
    $sql = "
        SELECT
            m.material_id,
            m.title,
            m.description,
            m.file_path,
            m.file_size,
            m.file_type,
            m.upload_date,
            m.download_count,
            i.name AS teacher_name
        FROM teaching_materials m
        JOIN instructor i ON m.instructor_id = i.instructor_id
        WHERE m.instructor_id = :instructor_id
        ORDER BY m.upload_date ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':instructor_id', $instructor_id, PDO::PARAM_STR);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $materials = array_map(static function(array $r): array {
        return [
            'material_id'    => (int)$r['material_id'],
            'title'          => (string)$r['title'],
            'description'    => (string)($r['description'] ?? ''),
            'file_path'      => (string)$r['file_path'],
            'file_size'      => (int)$r['file_size'],
            'file_type'      => (string)$r['file_type'],
            'upload_date'    => (string)$r['upload_date'],
            'download_count' => (int)$r['download_count'],
            'teacher_name'   => (string)$r['teacher_name'],
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'materials' => $materials], JSON_UNESCAPED_UNICODE);
    exit;

} catch (Throwable $e) {
    error_log('Get Materials Error: '.$e->getMessage());
    json_fail(500, 'SERVER_ERROR');
}
