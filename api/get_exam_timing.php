<?php
// api/get_exam_timing.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function out(array $o, int $code = 200)
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

// --- auth (student only; ยืดหยุ่น header case/claim) ---
$h = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
$auth = $h['authorization'] ?? '';
if (!preg_match('/bearer\s+(\S+)/i', $auth, $m)) out(['status' => 'error', 'message' => 'Missing token'], 401);
$claims = decodeToken($m[1]);
if (!$claims) out(['status' => 'error', 'message' => 'Unauthorized'], 401);

$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
if ($role !== 'student') out(['status' => 'error', 'message' => 'Forbidden'], 403);
$studentId = (string)($claims['student_id'] ?? $claims['sub'] ?? '');
if ($studentId === '') out(['status' => 'error', 'message' => 'No student_id'], 403);

// --- read body ---
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: 'null', true);
$sessionId = (int)($body['session_id'] ?? 0);
if ($sessionId <= 0) out(['status' => 'error', 'message' => 'Missing session_id'], 400);

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // ดึง session + slot + duration (ถ้ามี)
    $sql = "
      SELECT
        xs.session_id,
        xs.student_id,
        xs.slot_id,
        xs.start_time   AS sess_start,   -- DATETIME
        xs.end_time     AS sess_end,     -- ถ้าปิดแล้วจะมีค่า
        sl.start_at     AS slot_start,   -- ถ้ามีคอลัมน์ start_at/end_at
        sl.end_at       AS slot_end,
        sl.exam_date, sl.start_time AS slot_st, sl.end_time AS slot_et,
        es.duration_minutes
      FROM examsession xs
      JOIN exam_slots sl ON sl.id = xs.slot_id
      LEFT JOIN examset es ON es.examset_id = sl.examset_id
      WHERE xs.session_id = ? AND xs.student_id = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([$sessionId, $studentId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) out(['status' => 'error', 'message' => 'Session not found'], 404);

    // ถ้าปิดไปแล้ว
    if (!empty($row['sess_end'])) {
        out([
            'status'           => 'closed',
            'time_left_secs'   => 0,
            'total_questions'  => null,
            'answered_count'   => null,
            'server_time'      => date('Y-m-d H:i:s'),
        ], 200);
    }

    // หาเวลาเริ่ม-สิ้นสุดที่อนุญาต
    $sessStart = $row['sess_start'] ?: null;                       // DATETIME
    $slotStart = $row['slot_start'] ?: ($row['exam_date'] . ' ' . $row['slot_st']);
    $slotEnd   = $row['slot_end']   ?: ($row['exam_date'] . ' ' . $row['slot_et']);

    $baseStart = $sessStart ?: $slotStart;

    // end ตาม duration (ถ้ามี) มิฉะนั้นตาม slotEnd
    $durMin = (int)($row['duration_minutes'] ?? 0);
    $byDuration = $durMin > 0
        ? date('Y-m-d H:i:s', strtotime($baseStart . " +{$durMin} minutes"))
        : $slotEnd;

    $allowedEnd = date('Y-m-d H:i:s', min(strtotime($slotEnd), strtotime($byDuration)));
    $now = time();
    $left = max(0, strtotime($allowedEnd) - $now);

    // (ออปชัน) นับจำนวนข้อที่ตอบแล้ว ถ้ามีตารางคำตอบชื่อ session_answers/answers ให้ปรับชื่อให้ตรง
    $answered = null;
    try {
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM session_answers WHERE session_id=?");
        $cnt->execute([$sessionId]);
        $answered = (int)$cnt->fetchColumn();
    } catch (Throwable $e) {
        // ไม่มีตารางนี้ก็ข้ามไป
    }

    out([
        'status'           => ($left > 0 ? 'ok' : 'closed'),
        'time_left_secs'   => $left,
        'total_questions'  => null,      // ถ้ามีจำนวนข้อจริงจะใส่ก็ได้
        'answered_count'   => $answered, // ถ้าไม่มีตารางจะเป็น null
        'server_time'      => date('Y-m-d H:i:s'),
        'allowed_end_at'   => $allowedEnd,
        'session_start_at' => $baseStart,
    ]);
} catch (Throwable $e) {
    out(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()], 500);
}
