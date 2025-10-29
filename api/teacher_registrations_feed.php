<?php
// api/teacher_registrations_feed.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verify_token.php'; // ต้องมี requireAuth()

function json_error(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // --- Auth: บังคับเฉพาะครู ---
    $payload = requireAuth('teacher');

    // --- Input: range ---
    $range = $_GET['range'] ?? '30d';
    $days  = ($range === '7d') ? 7 : (($range === '90d') ? 90 : 30);

    // --- Query หลัก + Fallback (ใช้ exam_slots.id เสมอ) ---
    $tries = [
        // โครงสร้างปัจจุบัน (exam_slot_registrations)
        [
            "SELECT
         s.student_id,
         TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
         r.registered_at AS booked_at,
         sl.title AS slot_title,
         sl.id    AS slot_id
       FROM exam_slot_registrations r
       JOIN student s     ON s.student_id = r.student_id
       LEFT JOIN exam_slots sl ON sl.id = r.slot_id
       WHERE r.registered_at >= (NOW() - INTERVAL ? DAY)
       ORDER BY r.registered_at DESC
       LIMIT 5000",
            [$days]
        ],
        // โครงสร้างเดิม (exam_booking)
        [
            "SELECT
         s.student_id,
         TRIM(CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,''))) AS name,
         COALESCE(b.created_at, b.booking_time) AS booked_at,
         sl.title AS slot_title,
         sl.id    AS slot_id
       FROM exam_booking b
       JOIN student s ON s.student_id = b.student_id
       LEFT JOIN exam_slots sl ON sl.id = b.slot_id
       WHERE COALESCE(b.created_at, b.booking_time) >= (NOW() - INTERVAL ? DAY)
         AND (b.status IS NULL OR b.status='booked')
       ORDER BY COALESCE(b.created_at, b.booking_time) DESC
       LIMIT 5000",
            [$days]
        ],
    ];

    $rows = null;
    $last = null;
    foreach ($tries as [$sql, $params]) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
        } catch (Throwable $e) {
            $last = $e;
            // ลองตัวถัดไป
        }
    }

    if (!is_array($rows)) {
        json_error('Query failed: ' . ($last ? $last->getMessage() : 'unknown'), 500);
    }

    echo json_encode(['status' => 'success', 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
