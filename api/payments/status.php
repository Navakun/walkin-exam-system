<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

// auth (student or teacher)
$hdrs = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
if (!preg_match('/bearer\s+(\S+)/i', $hdrs['authorization'] ?? '', $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'no token']);
    exit;
}
$claims = decodeToken($m[1]);
if (!$claims) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'bad token']);
    exit;
}
$who = $claims['student_id'] ?? $claims['sid'] ?? $claims['email'] ?? null;

$ref = $_GET['ref_no'] ?? null;
$pid = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;
if (!$ref && !$pid) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ref_no or payment_id required']);
    exit;
}

$sql = "SELECT p.*, r.student_id AS reg_owner
        FROM payments p
        JOIN exam_slot_registrations r ON r.id = p.registration_id
        WHERE " . ($ref ? "p.ref_no = ?" : "p.payment_id = ?") . " LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([$ref ?: $pid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'not found']);
    exit;
}

// ตรวจว่าเป็นของนิสิตคนนี้ (ถ้าเป็น student) — teacher ผ่านได้
$role = strtolower($claims['role'] ?? $claims['user_role'] ?? '');
if (!in_array($role, ['teacher', 'instructor'], true)) {
    if ($who && $row['reg_owner'] !== $who) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'forbidden']);
        exit;
    }
}

$now = new DateTime('now', new DateTimeZone('Asia/Bangkok'));
$exp = $row['expires_at'] ? new DateTime($row['expires_at']) : null;
$expired = $exp ? ($now > $exp && $row['status'] === 'pending') : false;

echo json_encode([
    'status'      => 'success',
    'payment'     => [
        'payment_id' => (int)$row['payment_id'],
        'ref_no'     => $row['ref_no'],
        'status'     => $expired ? 'expired' : $row['status'],
        'amount'     => $row['amount'],
        'paid_at'    => $row['paid_at'],
        'expires_at' => $row['expires_at'],
    ],
], JSON_UNESCAPED_UNICODE);
