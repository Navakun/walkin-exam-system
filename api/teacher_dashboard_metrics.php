<?php
// api/teacher_dashboard_metrics.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/helpers/jwt_helper.php';
require_once __DIR__ . '/../config/db.php';

// ---------- Auth ----------
$headers = function_exists('getallheaders') ? getallheaders() : [];
if (
    !isset($headers['Authorization']) ||
    !preg_match('/Bearer\s+(\S+)/i', $headers['Authorization'], $m)
) {
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
    // ใช้ PDO จาก config/db.php — สมมติว่าแปรงเป็น $pdo หรือมีฟังก์ชัน pdo()
    $db = isset($pdo) ? $pdo : (function () {
        return pdo();
    })();

    // ตั้งเวลาเป็นโซนไทย (ถ้าฐานข้อมูลเก็บเป็น UTC จะช่วยให้ group by วันตรงกัน)
    $db->exec("SET time_zone = '+07:00'");

    // ---------- 1) Questions ----------
    // ตาราง: question (ตามสคีมาปัจจุบันที่ใช้อยู่) มีฟิลด์ item_difficulty = 0.150/0.500/0.850
    $qRow = $db->query("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN item_difficulty = 0.150 THEN 1 ELSE 0 END) AS easy,
            SUM(CASE WHEN item_difficulty = 0.500 THEN 1 ELSE 0 END) AS mid,
            SUM(CASE WHEN item_difficulty = 0.850 THEN 1 ELSE 0 END) AS hard
        FROM question
    ")->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'easy' => 0, 'mid' => 0, 'hard' => 0];

    // ---------- 2) Students ----------
    // ตาราง: student (จำนวนผู้ลงทะเบียนทั้งหมด)
    $studentsTotal = (int)($db->query("SELECT COUNT(*) FROM student")->fetchColumn() ?: 0);

    // ---------- 3) Exams (registrations) ----------
    // ตาราง: exam_slot_registrations (มี payment_status: free, pending, paid, waived, cancelled)
    // นับเฉพาะสถานะที่ “กินเก้าอี้จริง” = free/paid/waived (ถ้าต้องการนับ pending ด้วย ให้เพิ่มเข้าไป)
    $activeStatuses = ['free', 'paid', 'waived'];
    $in = implode(',', array_fill(0, count($activeStatuses), '?'));

    // 3.1 จำนวนรายการที่ถือว่า “เสร็จสิ้นการลงทะเบียน” ทั้งหมด
    $stmt = $db->prepare("SELECT COUNT(*) FROM exam_slot_registrations WHERE payment_status IN ($in)");
    $stmt->execute($activeStatuses);
    $completed = (int)($stmt->fetchColumn() ?: 0);

    // 3.2 จำนวนรายวัน (30 วันหลัง) จาก created_at
    // ดึงจาก DB เท่าที่มี แล้วไปเติม 0 ในวันที่ไม่มีข้อมูลให้ครบ 30 วัน
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

    // map ให้ครบ 30 วันล่าสุด
    $byDay = [];
    $start = new DateTime('today -29 days');
    for ($i = 0; $i < 30; $i++) {
        $d = $start->format('Y-m-d');
        $byDay[] = ['date' => $d, 'count' => (int)($rawByDay[$d] ?? 0)];
        $start->modify('+1 day');
    }

    // ---------- 4) Scores (ถ้าตารางคะแนนยังไม่มี ให้ส่งเป็น null ไว้ก่อน) ----------
    // ถ้าอนาคตมีตารางผลสอบ เช่น exam_results(score,is_pass) ค่อยอัปเดตคิวรีได้
    $scores = [
        'avg'      => null,  // ค่าเฉลี่ยคะแนน
        'passRate' => null   // อัตราผ่าน (0..1)
    ];

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
        'debug'   => $e->getMessage(), // ถ้าไม่อยากโชว์ debug ให้ลบออก
    ], JSON_UNESCAPED_UNICODE);
}
