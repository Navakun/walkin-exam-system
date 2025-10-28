<?php
// api/teacher_registrations_feed.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php';               // ปรับ path ตามโปรเจกต์
require_once __DIR__ . '/verify_token.php';     // มีฟังก์ชัน getBearerToken(), decodeToken()

try {
    // 1) Auth (ต้องเป็นอาจารย์)
    $token = getBearerToken();
    if (!$token) throw new Exception('No token');
    $payload = decodeToken($token);
    if (!$payload) throw new Exception('Invalid token');
    // ตรวจ role จาก payload (ปรับคีย์ให้ตรงระบบคุณ)
    $role = $payload->role ?? $payload->user_role ?? $payload->teacher_role ?? null;
    if (strtolower((string)$role) !== 'teacher') throw new Exception('Forbidden');

    // 2) อ่านพารามิเตอร์ช่วงเวลา
    $range = $_GET['range'] ?? '30d';
    $days = 30;
    if ($range === '7d') $days = 7;
    else if ($range === '90d') $days = 90;

    // 3) เลือกแหล่งข้อมูล (โปรเจกต์คุณบางช่วงใช้ exambooking แทน exam_slot_registrations)
    // ตั้งค่านี้ให้ตรง schema ปัจจุบันของคุณ
    $USE_EXAMBOOKING = true;

    // 4) SQL แบบยืดหยุ่น: ดึงเวลา, นิสิต, ชื่อ, ชื่อรอบ/ชุดข้อสอบ
    if ($USE_EXAMBOOKING) {
        // สมมุติคอลัมน์หลัก:
        // exambooking(booking_id, student_id, slot_id, created_at)
        // student(student_id, first_name, last_name)
        // exam_slots(slot_id, exam_date, start_time, end_time, title NULL-able)
        $sql = "
            SELECT
                s.student_id,
                TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
                eb.created_at AS booked_at,
                COALESCE(sl.title,
                    CONCAT(
                        DATE_FORMAT(sl.exam_date, '%Y-%m-%d'),
                        ' ',
                        DATE_FORMAT(sl.start_time, '%H:%i'),
                        '-',
                        DATE_FORMAT(sl.end_time, '%H:%i')
                    )
                ) AS slot_title
            FROM exambooking eb
            JOIN student s   ON s.student_id = eb.student_id
            LEFT JOIN exam_slots sl ON sl.slot_id = eb.slot_id
            WHERE eb.created_at >= (NOW() - INTERVAL ? DAY)
            ORDER BY eb.created_at DESC
            LIMIT 5000
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // สมมุติใช้ตาราง exam_slot_registrations(register_id, student_id, slot_id, created_at)
        $sql = "
            SELECT
                s.student_id,
                TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
                r.created_at AS booked_at,
                COALESCE(sl.title,
                    CONCAT(
                        DATE_FORMAT(sl.exam_date, '%Y-%m-%d'),
                        ' ',
                        DATE_FORMAT(sl.start_time, '%H:%i'),
                        '-',
                        DATE_FORMAT(sl.end_time, '%H:%i')
                    )
                ) AS slot_title
            FROM exam_slot_registrations r
            JOIN student s   ON s.student_id = r.student_id
            LEFT JOIN exam_slots sl ON sl.slot_id = r.slot_id
            WHERE r.created_at >= (NOW() - INTERVAL ? DAY)
            ORDER BY r.created_at DESC
            LIMIT 5000
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode([
        'status' => 'success',
        'data'   => array_map(function ($x) {
            // ทำให้ฟิลด์ที่ frontend ใช้แน่ๆ มีครบ
            return [
                'student_id' => $x['student_id'] ?? '',
                'name'       => $x['name'] ?? '',
                'booked_at'  => $x['booked_at'] ?? '',
                'slot_title' => $x['slot_title'] ?? ''
            ];
        }, $rows),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
