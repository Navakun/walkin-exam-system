<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jerr(string $m, int $c = 500)
{
    http_response_code($c);
    echo json_encode(['status' => 'error', 'message' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

/* bootstrap db */
try {
    foreach ([__DIR__ . '/db.php', __DIR__ . '/../db.php', __DIR__ . '/../config/db.php', __DIR__ . '/../walkin-exam-system/config/db.php'] as $p) {
        if (is_file($p)) {
            require_once $p;
            break;
        }
    }
    if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException('$pdo missing');
} catch (Throwable $e) {
    jerr('bootstrap db.php: ' . $e->getMessage());
}

try {
    require_once __DIR__ . '/verify_token.php';
} catch (Throwable $e) {
    jerr('bootstrap verify_token.php: ' . $e->getMessage());
}

try {
    // auth teacher
    $payload = requireAuth('teacher');
    try {
        $pdo->query("SET time_zone = '+07:00'");
    } catch (Throwable $e) {
    }

    // helpers
    $todayStart = "DATE(NOW())";
    $todayEnd   = "DATE(NOW()) + INTERVAL 1 DAY";

    $data = [
        'questions_total'       => 0,
        'students_total'        => 0,
        'registered_today'      => 0,
        'registered_last_7d'    => 0,
        'registered_last_30d'   => 0,
        'completed_today'       => 0,
        'exams_completed_total' => 0,
    ];

    // 1) รวมคำถามทั้งหมด
    try {
        $data['questions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
    } catch (Throwable $e) {
    }

    // 2) จำนวนนิสิตทั้งหมด
    try {
        $data['students_total'] = (int)$pdo->query("SELECT COUNT(*) FROM student")->fetchColumn();
    } catch (Throwable $e) {
    }

    // 3) ลงทะเบียนวันนี้ / 7 วัน / 30 วัน  (prefer exam_slot_registrations → fallback exam_booking)
    $regSQLs = [
        // ตารางใหม่
        'today' => "SELECT COUNT(*) FROM exam_slot_registrations WHERE registered_at >= {$todayStart} AND registered_at < {$todayEnd}",
        '7d'    => "SELECT COUNT(*) FROM exam_slot_registrations WHERE registered_at >= (NOW() - INTERVAL 7 DAY)",
        '30d'   => "SELECT COUNT(*) FROM exam_slot_registrations WHERE registered_at >= (NOW() - INTERVAL 30 DAY)",
    ];
    $regFallback = [
        'today' => "SELECT COUNT(*) FROM exam_booking WHERE created_at >= {$todayStart} AND created_at < {$todayEnd} AND (status IS NULL OR status='booked')",
        '7d'    => "SELECT COUNT(*) FROM exam_booking WHERE created_at >= (NOW() - INTERVAL 7 DAY) AND (status IS NULL OR status='booked')",
        '30d'   => "SELECT COUNT(*) FROM exam_booking WHERE created_at >= (NOW() - INTERVAL 30 DAY) AND (status IS NULL OR status='booked')",
    ];
    try {
        $data['registered_today']    = (int)$pdo->query($regSQLs['today'])->fetchColumn();
        $data['registered_last_7d']  = (int)$pdo->query($regSQLs['7d'])->fetchColumn();
        $data['registered_last_30d'] = (int)$pdo->query($regSQLs['30d'])->fetchColumn();
    } catch (Throwable $e) {
        // fallback
        try {
            $data['registered_today']    = (int)$pdo->query($regFallback['today'])->fetchColumn();
            $data['registered_last_7d']  = (int)$pdo->query($regFallback['7d'])->fetchColumn();
            $data['registered_last_30d'] = (int)$pdo->query($regFallback['30d'])->fetchColumn();
        } catch (Throwable $e2) {
        }
    }

    // 4) สอบเสร็จวันนี้ / ทั้งหมด
    try {
        $data['completed_today'] = (int)$pdo->query("SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL AND end_time >= {$todayStart} AND end_time < {$todayEnd}")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $data['exams_completed_total'] = (int)$pdo->query("SELECT COUNT(*) FROM examsession WHERE end_time IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) {
    }

    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr($e->getMessage());
}
