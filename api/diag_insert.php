<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->beginTransaction();
    $id = '_PROBE_' . date('His');
    $stmt = $pdo->prepare("INSERT INTO instructor (instructor_id, name, email, password) VALUES (?, 'probe', CONCAT(?, '@probe.local'), 'x')");
    $stmt->execute([$id, $id]);
    // ไม่ทิ้งข้อมูลจริง
    $pdo->rollBack();

    echo json_encode(['ok' => true, 'msg' => 'INSERT allowed (rolled back)']);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'code' => $e->getCode(), 'err' => $e->getMessage()]);
}

echo json_encode([
    'mb_strtoupper' => function_exists('mb_strtoupper'),
    'mb_strtolower' => function_exists('mb_strtolower'),
    'mb_strlen'     => function_exists('mb_strlen'),
    'mbstring_ext'  => extension_loaded('mbstring'),
]);
