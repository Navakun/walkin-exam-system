<?php
// api/teacher_dashboard_metrics.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/helpers/jwt_helper.php';
require_once __DIR__ . '/../config/db.php';

// ---------------- Config ----------------
const PASSING_SCORE_DEFAULT = 60; // เกณฑ์ผ่านเริ่มต้น (กรณีไม่มีตาราง/คอลัมน์กำหนดเกณฑ์ผ่าน)

// ---------------- Auth ------------------
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!isset($headers['Authorization']) || !preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $m)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'NO_TOKEN']);
    exit;
}

$token = $m[1];
$decoded = decodeToken($token);
if (!$decoded || (($decoded['role'] ?? '') !== 'teacher' && ($decoded['role'] ?? '') !== 'admin')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'FORBIDDEN']);
    exit;
}

try {
    // -------- DB handle ----------
    $db = isset($pdo) ? $pdo : (function () {
        return pdo();
    })();
    $db->exec("SET time_zone = '+07:00'");

    // ---------- helper: metadata ----------
    $tableExists = function (string $table) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    };
    $colExists = function (string $table, string $col) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $col]);
        return (bool)$stmt->fetchColumn();
    };

    // ---------- 1) Questions ----------
    $qRow = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN item_difficulty = 0.150 THEN 1 ELSE 0 END) AS easy,
            SUM(CASE WHEN item_difficulty = 0.500 THEN 1 ELSE 0 END) AS mid,
            SUM(CASE WHEN item_difficulty = 0.850 THEN 1 ELSE 0 END) AS hard
        FROM question
    ")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'easy' => 0, 'mid' => 0, 'hard' => 0];

    // ---------- 2) Students ----------
    $studentsTotal = (int)($db->query("SELECT COUNT(*) FROM student")->fetchColumn() ?: 0);

    // ---------- 3) Exams (registrations) ----------
    $activeStatuses = ['free', 'paid', 'waived'];
    $in = implode(',', array_fill(0, count($activeStatuses), '?'));

    // 3.1 รวมทั้งหมด
    $stmt = $db->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE payment_status IN ($in)");
    $stmt->execute($activeStatuses);
    $completed = (int)($stmt->fetchColumn() ?: 0);

    // 3.2 รายวัน 30 วันล่าสุด
    $stmt = $db->prepare("
        SELECT DATE(created_at) AS d, COUNT(*) AS c
        FROM exam_slot_registrations
        WHERE payment_status IN ($in)
          AND created_at >= (CURRENT_DATE - INTERVAL 29 DAY)
        GROUP BY DATE(created_at)
        ORDER BY d ASC
    ");
    $stmt->execute($activeStatuses);
    $rawByDay = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['2025-10-01'=>12, ...]

    $byDay = [];
    $start = new DateTime('today -29 days');
    for ($i = 0; $i < 30; $i++) {
        $d = $start->format('Y-m-d');
        $byDay[] = ['date' => $d, 'count' => (int)($rawByDay[$d] ?? 0)];
        $start->modify('+1 day');
    }

    // ---------- 4) Scores / Pass-Fail ----------
    $scores = [
        'avg'       => null,
        'passRate'  => null, // 0..1
        'pass'      => 0,
        'fail'      => 0,
    ];

    // 4.A ถ้ามีตาราง exam_results(score, is_pass[, created_at])
    if ($tableExists('exam_results') && $colExists('exam_results', 'score')) {
        // คะแนนเฉลี่ย
        $avg = $db->query("SELECT AVG(score) FROM exam_results WHERE score IS NOT NULL")->fetchColumn();
        if ($avg !== false && $avg !== null) {
            $scores['avg'] = (float)$avg;
        }

        // จำนวนผ่าน/ไม่ผ่าน
        if ($colExists('exam_results', 'is_pass')) {
            $row = $db->query("
                SELECT
                    SUM(CASE WHEN is_pass IN (1, '1', 'true', 'TRUE', 't', 'Y', 'y') THEN 1 ELSE 0 END) AS pass_count,
                    SUM(CASE WHEN is_pass IN (0, '0', 'false', 'FALSE', 'f', 'N', 'n') THEN 1 ELSE 0 END) AS fail_count
                FROM exam_results
            ")->fetch(PDO::FETCH_ASSOC);
            $scores['pass'] = (int)($row['pass_count'] ?? 0);
            $scores['fail'] = (int)($row['fail_count'] ?? 0);
        } else {
            // ไม่มี is_pass → ตัดสินจากคะแนนกับเกณฑ์ผ่านดีฟอลต์
            $passMark = PASSING_SCORE_DEFAULT;
            $row = $db->query("
                SELECT
                    SUM(CASE WHEN score >= {$passMark} THEN 1 ELSE 0 END) AS pass_count,
                    SUM(CASE WHEN score IS NOT NULL AND score < {$passMark} THEN 1 ELSE 0 END) AS fail_count
                FROM exam_results
            ")->fetch(PDO::FETCH_ASSOC);
            $scores['pass'] = (int)($row['pass_count'] ?? 0);
            $scores['fail'] = (int)($row['fail_count'] ?? 0);
        }
    }
    // 4.B ถ้าไม่มี exam_results ให้ลองใช้ exam_sessions(score[, ended_at|updated_at|created_at])
    elseif ($tableExists('exam_sessions') && $colExists('exam_sessions', 'score')) {
        $passMark = PASSING_SCORE_DEFAULT;

        // ถ้ามี status/ended_at จะนับเฉพาะที่จบแล้วก็ได้ (ป้องกันนับกลางคัน)
        $endedCol = null;
        foreach (['ended_at', 'finished_at', 'end_time', 'updated_at', 'created_at'] as $c) {
            if ($colExists('exam_sessions', $c)) {
                $endedCol = $c;
                break;
            }
        }

        $whereFinished = $endedCol ? "WHERE {$endedCol} IS NOT NULL" : "";

        // คะแนนเฉลี่ย
        $avgRow = $db->query("
            SELECT AVG(score) AS avg_score
            FROM exam_sessions
            {$whereFinished}
        ")->fetch(PDO::FETCH_ASSOC);
        if ($avgRow && $avgRow['avg_score'] !== null) {
            $scores['avg'] = (float)$avgRow['avg_score'];
        }

        // จำนวนผ่าน/ไม่ผ่าน (สามารถปรับเป็น JOIN กับตาราง exams เพื่อนำ pass_mark เฉพาะข้อสอบได้)
        // ตัวอย่าง (คอมเมนต์ไว้): 
        // SELECT SUM(CASE WHEN s.score >= COALESCE(e.pass_mark, {$passMark}) THEN 1 ELSE 0 END) ...
        $pfRow = $db->query("
            SELECT
                SUM(CASE WHEN score >= {$passMark} THEN 1 ELSE 0 END) AS pass_count,
                SUM(CASE WHEN score IS NOT NULL AND score < {$passMark} THEN 1 ELSE 0 END) AS fail_count
            FROM exam_sessions
            {$whereFinished}
        ")->fetch(PDO::FETCH_ASSOC);

        $scores['pass'] = (int)($pfRow['pass_count'] ?? 0);
        $scores['fail'] = (int)($pfRow['fail_count'] ?? 0);
    }

    // คำนวณ passRate ถ้ามีฐานข้อมูลพอ
    $totalDone = $scores['pass'] + $scores['fail'];
    if ($totalDone > 0) {
        $scores['passRate'] = $scores['pass'] / $totalDone;
    }

    echo json_encode([
        'status'    => 'success',
        'questions' => [
            'total'      => (int)$qRow['total'],
            'difficulty' => [
                'easy' => (int)$qRow['easy'],
                'mid'  => (int)$qRow['mid'],
                'hard' => (int)$qRow['hard'],
            ],
        ],
        'students'  => ['registered' => $studentsTotal],
        'exams'     => [
            'completed' => $completed,
            'byDay'     => $byDay,
        ],
        'scores'    => $scores,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'SERVER_ERROR',
        // 'debug'   => $e->getMessage(), // เปิดเฉพาะตอนดีบัก
    ], JSON_UNESCAPED_UNICODE);
}
