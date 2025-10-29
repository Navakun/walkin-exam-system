<?php
// api/teacher_kpis_today.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verify_token.php';

try {
    // auth
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) throw new Exception('Unauthorized', 401);
    $payload = verifyToken($m[1]);
    if (empty($payload->role) || !in_array($payload->role, ['teacher', 'instructor'], true)) {
        throw new Exception('Forbidden', 403);
    }

    // helper
    function tableExists(PDO $pdo, $name)
    {
        $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $q->execute([$name]);
        return (bool)$q->fetchColumn();
    }
    function pickCol(PDO $pdo, string $table, array $cands)
    {
        $in = implode(',', array_fill(0, count($cands), '?'));
        $sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($in)
                ORDER BY FIELD(COLUMN_NAME,$in) LIMIT 1";
        $st = $pdo->prepare($sql);
        $params = array_merge([$table], $cands, $cands);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? $r['COLUMN_NAME'] : null;
    }

    // tables
    $tblQuestion = tableExists($pdo, 'question') ? 'question' : (tableExists($pdo, 'questions') ? 'questions' : null);
    $tblBooking  = tableExists($pdo, 'exambooking') ? 'exambooking'
        : (tableExists($pdo, 'exam_bookings') ? 'exam_bookings'
            : (tableExists($pdo, 'exam_registrations') ? 'exam_registrations' : null));
    $tblSession  = tableExists($pdo, 'exam_sessions') ? 'exam_sessions' : (tableExists($pdo, 'sessions') ? 'sessions' : null);

    // questions total
    $questionsTotal = 0;
    if ($tblQuestion) {
        $questionsTotal = (int)$pdo->query("SELECT COUNT(*) FROM `$tblQuestion`")->fetchColumn();
    }

    // studentsToday = distinct student ลงทะเบียน 'วันนี้'
    $studentsToday = 0;
    if ($tblBooking) {
        $colStu = pickCol($pdo, $tblBooking, ['student_id', 'sid', 'student', 'student_code']);
        $colTime = pickCol($pdo, $tblBooking, ['booked_at', 'created_at', 'booking_time', 'reg_time', 'timestamp']);
        if ($colStu && $colTime) {
            $sql = "SELECT COUNT(DISTINCT `$colStu`) FROM `$tblBooking` WHERE DATE(`$colTime`) = CURRENT_DATE";
            $studentsToday = (int)$pdo->query($sql)->fetchColumn();
        }
    }

    // completed sessions (ทั้งหมดหรือวันนี้แล้วแต่ต้องการ)
    $completedAll = 0;
    if ($tblSession) {
        $colStatus = pickCol($pdo, $tblSession, ['status']);
        $colEnd    = pickCol($pdo, $tblSession, ['end_time', 'ended_at', 'finished_at']);
        if ($colStatus && $colEnd) {
            $completedAll = (int)$pdo->query("SELECT COUNT(*) FROM `$tblSession` WHERE `$colStatus` IN ('completed','finished')")->fetchColumn();
            // ถ้าจะเอาเฉพาะวันนี้ ใช้ WHERE DATE(`$colEnd`) = CURRENT_DATE
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'questions_total' => $questionsTotal,
            'students_today'  => $studentsToday,   // << ใช้ค่านี้ไปใส่ KPI “จำนวนนิสิต”
            'completed_total' => $completedAll,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code((int)($e->getCode() ?: 500));
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
