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

        // ให้มี key นี้เสมอ (กันหายจาก response)
        'passfail_day' => [
            'passed' => 0,
            'failed' => 0,
            'total' => 0,
            'pass_threshold' => 50.0,
            'day' => null
        ],
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

    // ---------- Pass/Fail ของวันตามพารามิเตอร์ (เวอร์ชันแก้เต็ม) ----------
    $dayRaw = $_GET['day'] ?? '';
    $dayRaw = urldecode(trim($dayRaw));
    $dayISO = null;

    // แปลงให้เป็น YYYY-MM-DD เสมอ
    if ($dayRaw === '' || strcasecmp($dayRaw, 'today') === 0) {
        $dayISO = (new DateTime('now', new DateTimeZone('+07:00')))->format('Y-m-d');
    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dayRaw)) {
        // yyyy-mm-dd
        $dayISO = $dayRaw;
    } elseif (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dayRaw)) {
        // mm/dd/yyyy
        [$m, $d, $y] = array_map('intval', explode('/', $dayRaw));
        $dayISO = sprintf('%04d-%02d-%02d', $y, $m, $d);
    } else {
        // พยายาม parse ด้วย strtotime เป็นทางสุดท้าย
        $ts = strtotime($dayRaw);
        if ($ts) $dayISO = (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('+07:00'))->format('Y-m-d');
    }

    if ($dayISO) {
        $PASS_THRESHOLD = 50.0; // เปอร์เซ็นต์ผ่าน
        $start = $dayISO . ' 00:00:00';
        $end   = date('Y-m-d', strtotime($dayISO . ' +1 day')) . ' 00:00:00';

        try {
            $pdo->query("SET time_zone = '+07:00'");

            // ดึง session ที่จบภายในวันนั้น (มี end_time)
            $sql = "
            SELECT
                s.session_id,
                COALESCE(
                    s.score,
                    CASE
                        WHEN s.questions_answered > 0
                        THEN (s.correct_count * 100.0 / s.questions_answered)
                        ELSE NULL
                    END,
                    0
                ) AS pct
            FROM examsession s
            WHERE s.end_time IS NOT NULL
              AND s.end_time >= :start
              AND s.end_time <  :end
        ";
            $st = $pdo->prepare($sql);
            $st->execute([':start' => $start, ':end' => $end]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $total  = count($rows);
            $passed = 0;
            foreach ($rows as $r) {
                if ((float)$r['pct'] >= $PASS_THRESHOLD) $passed++;
            }
            $failed = max(0, $total - $passed);

            $data['passfail_day'] = [
                'passed'         => $passed,
                'failed'         => $failed,
                'total'          => $total,
                'pass_threshold' => $PASS_THRESHOLD,
                'day'            => $dayISO,
                'window'         => ['start' => $start, 'end' => $end] // ไว้ debug ได้
            ];
        } catch (Throwable $e) {
            $data['passfail_day'] = [
                'passed' => 0,
                'failed' => 0,
                'total' => 0,
                'pass_threshold' => $PASS_THRESHOLD,
                'day' => $dayISO,
                'error' => $e->getMessage()
            ];
        }
    } else {
        // day parse ไม่ได้ — ส่งค่า default แต่ใส่บันทึกไว้
        $data['passfail_day'] = [
            'passed' => 0,
            'failed' => 0,
            'total' => 0,
            'pass_threshold' => 50.0,
            'day' => null,
            'note' => 'Unparseable day parameter'
        ];
    }

    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    jerr($e->getMessage());
}
