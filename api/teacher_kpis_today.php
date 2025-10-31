<?php
// Teacher KPIs - Today
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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

/* Bootstrap db.php */
try {
    $c = [__DIR__ . '/db.php', __DIR__ . '/../db.php', __DIR__ . '/../config/db.php', __DIR__ . '/../walkin-exam-system/config/db.php'];
    $found = false;
    foreach ($c as $p) {
        if (is_file($p)) {
            require_once $p;
            $found = true;
            break;
        }
    }
    if (!$found) throw new RuntimeException('db.php not found in known locations');
} catch (Throwable $e) {
    json_error('bootstrap db.php: ' . $e->getMessage(), 500);
}

try {
    require_once __DIR__ . '/verify_token.php';
} catch (Throwable $e) {
    json_error('bootstrap verify_token.php: ' . $e->getMessage(), 500);
}

if (!isset($pdo) || !($pdo instanceof PDO)) json_error('bootstrap: $pdo not available', 500);

try {
    $payload = requireAuth('teacher');

    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
    }

    $out = ['registered_today' => 0, 'completed_today' => 0, 'questions_total' => 0];

    // ลงทะเบียนวันนี้: ใช้ created_at (ไม่มี booking_time)
    // แทนบล็อกเดิมที่นับ registered_today ด้วย 2 query ลองตามนี้
    $todayStart = "DATE(NOW())";
    $todayEnd   = "DATE(NOW()) + INTERVAL 1 DAY";

    $ok = false;
    $last = null;
    foreach (
        [
            // ตารางใหม่
            "SELECT COUNT(*) FROM exam_slot_registrations
     WHERE registered_at >= {$todayStart} AND registered_at < {$todayEnd}",

            // ตารางเดิม
            "SELECT COUNT(*) FROM exam_booking
     WHERE created_at >= {$todayStart} AND created_at < {$todayEnd}
       AND (status IS NULL OR status='booked')",
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

    try {
        $out['completed_today'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL AND DATE(end_time)=CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $out['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) {
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error(($DEBUG ? '[debug] ' : '') . $e->getMessage(), 500);
}
