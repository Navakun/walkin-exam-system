<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/jwt_helper.php';

$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth = $hdrs['authorization'] ?? '';
if (!preg_match('/bearer\s+(\S+)/i', $auth)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}
$claims = decodeToken(preg_replace('/^Bearer\s+/i', '', $auth));
$studentId = (string)($claims['sid'] ?? $claims['student_id'] ?? '');

$paymentId = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
$refNo = isset($_GET['ref_no']) ? (string)$_GET['ref_no'] : '';

if ($paymentId <= 0 && $refNo === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'missing payment_id or ref_no']);
    exit;
}

$sql = "SELECT p.payment_id, p.registration_id, p.amount, p.status, p.ref_no, p.paid_at, r.student_id
        FROM payments p
        JOIN exam_slot_registrations r ON r.id = p.registration_id
        WHERE " . ($paymentId ? "p.payment_id=?" : "p.ref_no=?") . " LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([$paymentId ?: $refNo]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row || $row['student_id'] !== $studentId) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'not found']);
    exit;
}

// หากมีระบบเชื่อม bank จริง ให้ sync ที่นี่ แล้วอัปเดตตาราง payments
echo json_encode([
    'status'          => 'success',
    'payment_status'  => $row['status'],   // 'pending' | 'paid' | 'failed' | 'refunded'
    'ref_no'          => $row['ref_no'],
    'paid_at'         => $row['paid_at']
], JSON_UNESCAPED_UNICODE);
