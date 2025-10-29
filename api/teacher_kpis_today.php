<?php
// api/teacher_kpis_today.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$DEBUG = isset($_GET['debug']);
if ($DEBUG) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    set_error_handler(function ($no, $str, $file, $line) {
        throw new ErrorException($str, 0, $no, $file, $line);
    });
}

function json_error(string $msg, int $code = 500): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // --- bootstrap (จับ error ตั้งแต่ require) ---
    try {
        require_once __DIR__ . '/db.php';
    } catch (Throwable $e) {
        json_error('bootstrap db.php: ' . $e->getMessage(), 500);
    }
    try {
        require_once __DIR__ . '/verify_token.php'; // มี requireAuth()
    } catch (Throwable $e) {
        json_error('bootstrap verify_token.php: ' . $e->getMessage(), 500);
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) json_error('bootstrap: $pdo not available', 500);

    // --- Auth ---
    $payload = requireAuth('teacher');

    // --- TZ (ignore ถ้าทำไม่ได้) ---
    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
    }

    $out = ['registered_today' => 0, 'completed_today' => 0, 'questions_total' => 0];

    // ลงทะเบียนวันนี้: table ใหม่ → fallback
    $ok = false;
    $last = null;
    foreach (
        [
            "SELECT COUNT(*) FROM exam_slot_registrations WHERE DATE(registered_at)=CURDATE()",
            "SELECT COUNT(*) FROM exam_booking WHERE DATE(COALESCE(created_at,booking_time))=CURDATE() AND (status IS NULL OR status='booked')",
        ] as $sql
    ) {
        try {
            $out['registered_today'] = (int)$pdo->query($sql)->fetchColumn();
            $ok = true;
            break;
        } catch (Throwable $e) {
            $last = $e;
        }
    }
    if (!$ok && $last) throw $last;

    // สอบเสร็จวันนี้
    try {
        $out['completed_today'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL AND DATE(end_time)=CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
    }

    // จำนวนข้อสอบทั้งหมด
    try {
        $out['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) {
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error(($DEBUG ? '[debug] ' : '') . $e->getMessage(), 500);
}
