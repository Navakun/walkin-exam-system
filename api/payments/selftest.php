<?php

declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "DB OK\n";

    // เช็คตาราง / ENUM
    $cols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_KEY_PAIR);
    echo "payments columns: " . implode(',', array_keys($cols)) . "\n";

    $create = $pdo->query("SHOW CREATE TABLE payments")->fetch(PDO::FETCH_ASSOC);
    echo "CREATE: \n" . $create['Create Table'] . "\n\n";

    // ทดสอบหา registration ล่าสุดของนิสิต (ต้องใส่ student_id มือ)
    $student_id = isset($_GET['sid']) ? $_GET['sid'] : '';
    if ($student_id) {
        $r = $pdo->prepare("SELECT r.id, r.student_id, r.fee_amount, r.payment_status
                        FROM exam_slot_registrations r
                        WHERE r.student_id=? ORDER BY r.id DESC LIMIT 1");
        $r->execute([$student_id]);
        print_r($r->fetch());
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERR: " . $e->getMessage();
}
