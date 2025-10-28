<?php
// api/teacher_registrations_feed.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php';               // ปรับ path ให้ตรงโปรเจกต์
require_once __DIR__ . '/verify_token.php';     // ต้องมี getBearerToken(), decodeToken()

function json_error($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // ----- AUTH -----
    $token = getBearerToken();
    if (!$token) json_error('No token', 401);
    $payload = decodeToken($token);
    if (!$payload) json_error('Invalid token', 401);
    // ยืดหยุ่นเรื่อง role: รับถ้ามี teacher_id หรือ role=teacher
    $role = strtolower(strval($payload->role ?? $payload->user_role ?? ''));
    $teacherId = $payload->teacher_id ?? $payload->instructor_id ?? null;
    if ($role !== 'teacher' && !$teacherId) json_error('Forbidden', 403);

    // ----- INPUT -----
    $range = $_GET['range'] ?? '30d';
    $days = 30;
    if ($range === '7d') $days = 7;
    else if ($range === '90d') $days = 90;

    // ----- SQL FALLBACKS -----
    // เราจะลองรันหลาย query ทีละแบบ (กันกรณีชื่อคอลัมน์/ตารางต่างกัน)
    $sqls = [
        // A) exambooking + created_at
        "SELECT
        s.student_id,
        TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
        eb.created_at AS booked_at,
        sl.title AS slot_title
     FROM exambooking eb
     JOIN student s ON s.student_id = eb.student_id
     LEFT JOIN exam_slots sl ON sl.slot_id = eb.slot_id
     WHERE eb.created_at >= (NOW() - INTERVAL ? DAY)
     ORDER BY eb.created_at DESC
     LIMIT 5000",
        // B) exambooking + booking_time
        "SELECT
        s.student_id,
        TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
        eb.booking_time AS booked_at,
        sl.title AS slot_title
     FROM exambooking eb
     JOIN student s ON s.student_id = eb.student_id
     LEFT JOIN exam_slots sl ON sl.slot_id = eb.slot_id
     WHERE eb.booking_time >= (NOW() - INTERVAL ? DAY)
     ORDER BY eb.booking_time DESC
     LIMIT 5000",
        // C) exam_slot_registrations + created_at
        "SELECT
        s.student_id,
        TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
        r.created_at AS booked_at,
        sl.title AS slot_title
     FROM exam_slot_registrations r
     JOIN student s ON s.student_id = r.student_id
     LEFT JOIN exam_slots sl ON sl.slot_id = r.slot_id
     WHERE r.created_at >= (NOW() - INTERVAL ? DAY)
     ORDER BY r.created_at DESC
     LIMIT 5000",
        // D) exam_slot_registrations + registered_at
        "SELECT
        s.student_id,
        TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
        r.registered_at AS booked_at,
        sl.title AS slot_title
     FROM exam_slot_registrations r
     JOIN student s ON s.student_id = r.student_id
     LEFT JOIN exam_slots sl ON sl.slot_id = r.slot_id
     WHERE r.registered_at >= (NOW() - INTERVAL ? DAY)
     ORDER BY r.registered_at DESC
     LIMIT 5000",
    ];

    $rows = [];
    foreach ($sqls as $q) {
        try {
            $stmt = $pdo->prepare($q);
            $stmt->execute([$days]);
            $tmp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($tmp) && count($tmp)) {
                $rows = $tmp;
                break;
            }
            // ถ้าได้ 0 แถว ให้ยังถือว่า query ใช้ได้ แล้วออกลูปเลย
            if (is_array($tmp)) {
                $rows = $tmp;
                break;
            }
        } catch (Throwable $e) {
            // ลองตัวถัดไป
        }
    }

    echo json_encode([
        'status' => 'success',
        'data'   => array_map(function ($x) {
            return [
                'student_id' => $x['student_id'] ?? '',
                'name'       => $x['name'] ?? '',
                'booked_at'  => $x['booked_at'] ?? '',
                'slot_title' => $x['slot_title'] ?? '',
            ];
        }, $rows),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
