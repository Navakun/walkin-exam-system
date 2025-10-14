<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

error_reporting(E_ALL);
ini_set('display_errors', '0');      // กัน error ปน JSON
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/send_result_to_teacher_error.log');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- อ่าน & ตรวจ JWT ----------
$hdrs = function_exists('getallheaders') ? getallheaders() : [];
$auth = $hdrs['Authorization'] ?? $hdrs['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
    out(['status' => 'error', 'message' => 'No token provided'], 401);
}
$claims = decodeToken($m[1]);
if (!$claims) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
$role       = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$student_id = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($role !== 'student' || $student_id === '') {
    out(['status' => 'error', 'message' => 'Forbidden'], 403);
}

// ---------- รับ session_id จาก JSON body หรือ GET ----------
$body = json_decode(file_get_contents('php://input') ?: 'null', true);
$session_id = (int)($body['session_id'] ?? ($_GET['session_id'] ?? 0));
if ($session_id <= 0) {
    out(['status' => 'error', 'message' => 'Missing session_id'], 400);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET time_zone = '+07:00'");

    // ---------- ตรวจว่ามี session นี้ และเป็นของนิสิตคนนี้จริง ----------
    $st = $pdo->prepare("
    SELECT session_id, student_id, reported_to_teacher_at, end_time
    FROM examsession
    WHERE session_id = ? AND CAST(student_id AS BINARY) = CAST(? AS BINARY)
    LIMIT 1
  ");
    $st->execute([$session_id, $student_id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        out(['status' => 'error', 'message' => 'Session not found'], 404);
    }

    // (ถ้าต้องการบังคับว่าต้องทำข้อสอบจบก่อนค่อยส่ง ก็ปลดคอมเมนต์ 3 บรรทัดด้านล่าง)
    // if (empty($row['end_time'])) {
    //   out(['status' => 'error', 'message' => 'Session not finished yet'], 400);
    // }

    // ---------- เคยกดส่งแล้ว? ----------
    if (!empty($row['reported_to_teacher_at'])) {
        out([
            'status'      => 'success',
            'message'     => 'already_reported',
            'reported_at' => $row['reported_to_teacher_at'],
            'session_id'  => (int)$row['session_id'],
            'student_id'  => $row['student_id'],
        ]);
    }

    // ---------- ยังไม่เคยส่ง → อัปเดต timestamp ----------
    $upd = $pdo->prepare("
    UPDATE examsession
    SET reported_to_teacher_at = NOW()
    WHERE session_id = ? AND CAST(student_id AS BINARY) = CAST(? AS BINARY)
  ");
    $upd->execute([$session_id, $student_id]);

    // ดึงค่าเวลาที่อัปเดต (กันกรณี timezone/trigger)
    $st2 = $pdo->prepare("
    SELECT reported_to_teacher_at
    FROM examsession
    WHERE session_id = ? AND CAST(student_id AS BINARY) = CAST(? AS BINARY)
    LIMIT 1
  ");
    $st2->execute([$session_id, $student_id]);
    $reportedAt = (string)$st2->fetchColumn();

    // (ออปชัน) ทำ log เผื่อ debug
    error_log("[send_result_to_teacher] session_id={$session_id} student_id={$student_id} reported_at={$reportedAt}");

    out([
        'status'      => 'success',
        'message'     => 'ส่งข้อมูลผลสอบให้อาจารย์เรียบร้อยแล้ว',
        'session_id'  => $session_id,
        'student_id'  => $student_id,
        'reported_at' => $reportedAt,
    ]);
} catch (Throwable $e) {
    error_log('[send_result_to_teacher] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
