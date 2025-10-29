<?php
// api/teacher_registrations_feed.php
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

/* ---------- Bootstrap db.php (global scope) ---------- */
try {
    $candidates = [
        __DIR__ . '/db.php',
        __DIR__ . '/../db.php',
        __DIR__ . '/../config/db.php',
        __DIR__ . '/../walkin-exam-system/config/db.php',
    ];
    $found = false;
    foreach ($candidates as $p) {
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

/* ---------- Auth ---------- */
$payload = requireAuth('teacher');

/* ---------- Main ---------- */
try {
    $range = $_GET['range'] ?? '30d';
    $days  = ($range === '7d') ? 7 : (($range === '90d') ? 90 : 30);

    // ชื่อรอบสอบ: สร้างจาก exam_date + start_time + end_time (เพราะไม่มี sl.title)
    $slotTitle =
        "TRIM(CONCAT(DATE_FORMAT(sl.exam_date,'%Y-%m-%d'),' ', " .
        "TIME_FORMAT(sl.start_time,'%H:%i'),'–',TIME_FORMAT(sl.end_time,'%H:%i')))";

    // 1) โครงสร้างใหม่: exam_slot_registrations
    $sql1 = "
    SELECT
      s.student_id,
      s.name AS name,
      r.registered_at AS booked_at,
      {$slotTitle} AS slot_title,
      sl.id AS slot_id
    FROM exam_slot_registrations r
    JOIN student s ON s.student_id = r.student_id
    LEFT JOIN exam_slots sl ON sl.id = r.slot_id
    WHERE r.registered_at >= (NOW() - INTERVAL ? DAY)
    ORDER BY r.registered_at DESC
    LIMIT 5000
  ";

    // 2) โครงสร้างเดิม: exam_booking (ใช้ created_at เท่านั้น)
    $sql2 = "
    SELECT
      s.student_id,
      s.name AS name,
      b.created_at AS booked_at,
      {$slotTitle} AS slot_title,
      sl.id AS slot_id
    FROM exam_booking b
    JOIN student s ON s.student_id = b.student_id
    LEFT JOIN exam_slots sl ON sl.id = b.slot_id
    WHERE b.created_at >= (NOW() - INTERVAL ? DAY)
      AND (b.status IS NULL OR b.status='booked')
    ORDER BY b.created_at DESC
    LIMIT 5000
  ";

    $rows = null;
    $last = null;

    try {
        $st = $pdo->prepare($sql1);
        $st->execute([$days]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $last = $e;
    }

    if (!is_array($rows)) {
        try {
            $st = $pdo->prepare($sql2);
            $st->execute([$days]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $last = $e;
        }
    }

    if (!is_array($rows)) {
        json_error('query failed: ' . ($last ? $last->getMessage() : 'unknown'), 500);
    }

    $data = array_map(static function ($it) {
        return [
            'student_id' => (string)($it['student_id'] ?? ''),
            'name'       => (string)($it['name'] ?? ''),
            'booked_at'  => (string)($it['booked_at'] ?? ''),
            'slot_title' => (string)($it['slot_title'] ?? ''),
            'slot_id'    => isset($it['slot_id']) ? (int)$it['slot_id'] : null,
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error(($DEBUG ? '[debug] ' : '') . $e->getMessage(), 500);
}
