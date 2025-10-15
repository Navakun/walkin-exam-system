<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(function ($s, $m, $f, $l) {
    throw new ErrorException($m, 0, $s, $f, $l);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        if (function_exists('ob_get_length') && ob_get_length()) ob_clean();
        echo json_encode(['status' => 'error', 'message' => 'SERVER_ERROR', 'debug' => $e['message']]);
    }
});

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

$h = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($h['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $h['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'NO_TOKEN']);
    exit;
}
$jwt = $m[1];
$user = verifyJwtToken($jwt); // หรือ decodeToken() ของคุณ
if (!$user || ($user['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'FORBIDDEN']);
    exit;
}

$instructor_id = $user['instructor_id'];

$pdo->exec("SET time_zone = '+07:00'");
$stmt = $pdo->prepare("SELECT instructor_id, name, email FROM instructor WHERE instructor_id = :id LIMIT 1");
$stmt->execute([':id' => $instructor_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'NOT_FOUND']);
    exit;
}

echo json_encode(['status' => 'success', 'profile' => $row], JSON_UNESCAPED_UNICODE);
