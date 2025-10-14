<?php
header('Content-Type: application/json; charset=utf-8');

/* -------------------------------------------------------
 * Error handling → ส่งกลับเป็น JSON เสมอ
 * ----------------------------------------------------- */
ini_set('display_errors', 0);
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'HTTP_500',
            'debug' => $e['message'] . ' @' . $e['file'] . ':' . $e['line']
        ]);
    }
});

/* -------------------------------------------------------
 * Safe logger (เขียนไว้ไฟล์ข้างๆสคริปต์นี้)
 * ----------------------------------------------------- */
function safe_log($file, $msg)
{
    $path = __DIR__ . '/' . ltrim($file, '/');
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    @file_put_contents($path, $line, FILE_APPEND);
}

/* -------------------------------------------------------
 * Require
 * ----------------------------------------------------- */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../api/helpers/jwt_helper.php';

/* -------------------------------------------------------
 * Read Authorization header (รองรับหลาย server)
 * ----------------------------------------------------- */
$headers = function_exists('getallheaders') ? getallheaders() : [];
// ทำให้ key ไม่สนตัวพิมพ์
$h2 = [];
foreach ($headers as $k => $v) $h2[strtolower($k)] = $v;

$auth = $h2['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Token ไม่ถูกต้องหรือไม่พบ token']);
    exit;
}
$token = $m[1];
$userData = verifyJwtToken($token);
if (!$userData || ($userData['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'ไม่มีสิทธิ์เข้าถึงข้อมูล']);
    exit;
}

/* -------------------------------------------------------
 * Helpers: normalize เวลา/วันเวลา
 * ----------------------------------------------------- */
function norm_datetime(?string $v): ?string
{
    if (!$v) return null;
    $s = trim(str_replace('T', ' ', $v));
    $s = preg_replace('/(\d{2}:\d{2}:\d{2}):\d{2}$/', '$1', $s); // กัน xx:xx:xx:xx
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $s)) $s .= ':00';
    $ts = strtotime($s);
    if ($ts === false) throw new Exception("INVALID_DATETIME:$s");
    return date('Y-m-d H:i:s', $ts);
}
function norm_time(?string $v): ?string
{
    if (!$v) return null;
    $v = trim($v);
    if (preg_match('/^\d{2}:\d{2}$/', $v)) return $v . ':00';
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $v)) return $v;
    $ts = strtotime($v);
    if ($ts === false) throw new Exception("INVALID_TIME:$v");
    return date('H:i:s', $ts);
}
function is_valid_ymd(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $d2] = array_map('intval', explode('-', $d));
    return checkdate($m, $d2, $y);
}

/* -------------------------------------------------------
 * Input
 * ----------------------------------------------------- */
$body = json_decode(file_get_contents('php://input'), true) ?? [];

// รองรับชื่อฟิลด์ได้หลายแบบ
$exam_date      = $body['slot_date'] ?? $body['exam_date'] ?? null;
$start_time_raw = $body['start_time'] ?? null;
$end_time_raw   = $body['end_time'] ?? null;
$max_seats      = isset($body['max_seats']) ? (int)$body['max_seats'] : null;

$reg_open_raw   = $body['reg_open_at'] ?? null;
$reg_close_raw  = $body['reg_close_at'] ?? null;

$examset_title  = trim((string)($body['examset_title'] ?? ''));
$easy_count     = (int)($body['easy_count'] ?? 0);
$medium_count   = (int)($body['medium_count'] ?? 0);
$hard_count     = (int)($body['hard_count'] ?? 0);
$duration_min   = (int)($body['duration_minutes'] ?? 60);

/* -------------------------------------------------------
 * Validation
 * ----------------------------------------------------- */
if (!is_valid_ymd($exam_date) || !$start_time_raw || !$end_time_raw || !$max_seats || $examset_title === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลให้ครบถ้วน']);
    exit;
}

try {
    $start_time   = norm_time($start_time_raw);   // HH:MM:SS
    $end_time     = norm_time($end_time_raw);     // HH:MM:SS
    $reg_open_at  = $reg_open_raw  ? norm_datetime($reg_open_raw)  : null;
    $reg_close_at = $reg_close_raw ? norm_datetime($reg_close_raw) : null;
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'รูปแบบวันเวลาไม่ถูกต้อง', 'debug' => $e->getMessage()]);
    exit;
}

// ตรวจระยะเวลา (ไม่ให้ 0)
if ($duration_min <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'ระยะเวลาสอบต้องมากกว่า 0 นาที']);
    exit;
}

/* -------------------------------------------------------
 * คำนวณ start_at / end_at (รองรับข้ามเที่ยงคืน)
 * ----------------------------------------------------- */
$start_at = $exam_date . ' ' . $start_time;
$start_ts = strtotime($start_at);
if ($start_ts === false) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'วันเวลาเริ่มสอบไม่ถูกต้อง']);
    exit;
}
$end_ts = $start_ts + ($duration_min * 60);
$end_at = date('Y-m-d H:i:s', $end_ts);
// sync end_time จาก end_at เผื่อผู้ใช้ใส่ไม่ตรงกับ duration
$end_time = date('H:i:s', $end_ts);

/* -------------------------------------------------------
 * DB ops
 * ----------------------------------------------------- */
try {
    $pdo->beginTransaction();

    // 1) สร้าง examset
    $stmtExamset = $pdo->prepare("
    INSERT INTO examset (title, easy_count, medium_count, hard_count, duration_minutes, created_by)
    VALUES (:title, :easy, :medium, :hard, :dur, :created_by)
  ");
    $stmtExamset->execute([
        ':title'      => $examset_title,
        ':easy'       => $easy_count,
        ':medium'     => $medium_count,
        ':hard'       => $hard_count,
        ':dur'        => $duration_min,
        ':created_by' => $userData['instructor_id'] ?? null,
    ]);
    $examset_id = (int)$pdo->lastInsertId();
    $createdBy = $userData['instructor_id'] ?? null;
    if ($createdBy) {
        $chk = $pdo->prepare("SELECT 1 FROM instructor WHERE instructor_id = :id LIMIT 1");
        $chk->execute([':id' => $createdBy]);
        if (!$chk->fetchColumn()) $createdBy = null; // กัน FK ชน
    }

    // 2) สร้าง slot (ถ้าตารางคุณไม่มีคอลัมน์ start_at/end_at ก็ลบสองคอลัมน์นี้ออก)
    $stmtSlot = $pdo->prepare("
    INSERT INTO exam_slots (
      exam_date, start_time, end_time, start_at, end_at,
      max_seats, reg_open_at, reg_close_at, created_by, examset_id
    ) VALUES (
      :exam_date, :start_time, :end_time, :start_at, :end_at,
      :max_seats, :reg_open_at, :reg_close_at, :created_by, :examset_id
    )
  ");
    $stmtSlot->execute([
        ':exam_date'   => $exam_date,
        ':start_time'  => $start_time,
        ':end_time'    => $end_time,
        ':start_at'    => $start_at,
        ':end_at'      => $end_at,
        ':max_seats'   => $max_seats,
        ':reg_open_at' => $reg_open_at,
        ':reg_close_at' => $reg_close_at,
        ':created_by'  => $userData['instructor_id'] ?? 'Unknown',
        ':examset_id'  => $examset_id,
    ]);

    $slot_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        'status'      => 'success',
        'examset_id'  => $examset_id,
        'slot_id'     => $slot_id,
        'start_at'    => $start_at,
        'end_at'      => $end_at
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    safe_log('add_slot_error.log', $e->__toString());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'DB_ERROR', 'debug' => $e->getMessage()]);
}
