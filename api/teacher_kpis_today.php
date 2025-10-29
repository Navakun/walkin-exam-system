<?php
// api/teacher_kpis_today.php
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

    // --- TZ (บางโฮสต์อาจปฏิเสธได้ ไม่เป็นไร) ---
    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
    }

    $out = [
        'registered_today' => 0,
        'completed_today'  => 0,
        'questions_total'  => 0,
    ];

    // --- 1) จำนวนลงทะเบียนวันนี้ ---
    // ลองจากตารางใหม่ exam_slot_registrations ก่อน → ถ้าไม่ได้ ค่อย fallback exambooking
    $ok = false;
    $lastErr = null;

    foreach (
        [
            "SELECT COUNT(*) AS c
     FROM exam_slot_registrations
     WHERE DATE(registered_at) = CURDATE()",

            "SELECT COUNT(*) AS c
     FROM exam_booking
     WHERE DATE(COALESCE(created_at, booking_time)) = CURDATE()
       AND (status IS NULL OR status='booked')",
        ] as $sql
    ) {
        try {
            $out['registered_today'] = (int)$pdo->query($sql)->fetchColumn();
            $ok = true;
            break;
        } catch (Throwable $e) {
            $lastErr = $e;
        }
    }
    if (!$ok && $lastErr) throw $lastErr;

    // --- 2) จำนวนสอบเสร็จวันนี้ ---
    try {
        $out['completed_today'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM examsession
       WHERE end_time IS NOT NULL AND DATE(end_time) = CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
        // ไม่มีตาราง/คอลัมน์ก็ปล่อย 0
    }

    // --- 3) จำนวนคำถามทั้งหมด ---
    try {
        $out['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) {
        // ไม่มีตารางก็ปล่อย 0
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
