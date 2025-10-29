<?php
// api/teacher_kpis_today.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
// ถ้าหน้าเว็บ/โดเมนเดียวกันอยู่แล้ว ไม่จำเป็นต้องเปิด CORS แต่อยู่ได้
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// ตอบ preflight ให้เงียบ ๆ
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

// ------- Bootstrap: หา db.php หลายตำแหน่งให้ทนโครงสร้างโฟลเดอร์ -------
try {
    (function () {
        $candidates = [
            __DIR__ . '/db.php',
            __DIR__ . '/../db.php',
            __DIR__ . '/../config/db.php',
            __DIR__ . '/../walkin-exam-system/config/db.php', // ที่แจ้งว่ามีจริง
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                require_once $p;
                return;
            }
        }
        throw new RuntimeException('db.php not found in known locations');
    })();
} catch (Throwable $e) {
    json_error('bootstrap db.php: ' . $e->getMessage(), 500);
}

try {
    require_once __DIR__ . '/verify_token.php'; // ต้องมี requireAuth()
} catch (Throwable $e) {
    json_error('bootstrap verify_token.php: ' . $e->getMessage(), 500);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    json_error('bootstrap: $pdo not available', 500);
}

try {
    // --- Auth: บังคับเฉพาะ role=teacher ---
    $payload = requireAuth('teacher');

    // --- TZ (บางโฮสต์ห้าม SET ก็ไม่เป็นไร) ---
    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
    }

    $out = [
        'registered_today' => 0,
        'completed_today'  => 0,
        'questions_total'  => 0,
    ];

    // --- 1) จำนวนลงทะเบียนวันนี้ (ตารางใหม่ → fallback ตารางเดิม) ---
    $ok = false;
    $last = null;
    foreach (
        [
            "SELECT COUNT(*) FROM exam_slot_registrations WHERE DATE(registered_at) = CURDATE()",
            "SELECT COUNT(*) FROM exam_booking WHERE DATE(COALESCE(created_at, booking_time)) = CURDATE() AND (status IS NULL OR status='booked')",
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
    if (!$ok && $last) {
        throw $last;
    }

    // --- 2) จำนวนสอบเสร็จวันนี้ ---
    try {
        $out['completed_today'] = (int)$pdo->query(
            "SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL AND DATE(end_time) = CURDATE()"
        )->fetchColumn();
    } catch (Throwable $e) { /* ปล่อยไว้เป็น 0 */
    }

    // --- 3) จำนวนข้อสอบทั้งหมด ---
    try {
        $out['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) { /* ปล่อยไว้เป็น 0 */
    }

    echo json_encode(['status' => 'success', 'data' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error(($DEBUG ? '[debug] ' : '') . $e->getMessage(), 500);
}
