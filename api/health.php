<?php
header('Content-Type: application/json; charset=utf-8');
try {
    require_once __DIR__ . '/../config/db.php';
    $pdo->query('SELECT 1');
    echo json_encode(['ok' => true, 'db' => 'connected']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'err' => $e->getMessage()]);
}
