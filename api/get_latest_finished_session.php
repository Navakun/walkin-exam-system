<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php'; // มี $pdo แล้ว

// ดึง student_id จาก Bearer JWT (ถ้าไม่มีให้ลองรับจาก ?student_id=)
function getStudentIdFromAuth(): ?string
{
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        $parts = explode('.', trim($m[1]));
        if (count($parts) >= 2) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (!empty($payload['student_id'])) return (string)$payload['student_id'];
        }
    }
    return null;
}

$studentId = getStudentIdFromAuth() ?? ($_GET['student_id'] ?? null);
if (!$studentId) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'unauthorized: missing token/student_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // หา session ล่าสุดที่สอบเสร็จ (end_time ไม่เป็น NULL)
    $st = $pdo->prepare("
        SELECT s.session_id, s.student_id, s.start_time, s.end_time, s.score, s.attempt_no,
               es.title AS exam_title
        FROM examsession s
        LEFT JOIN exam_slots sl ON sl.id = s.slot_id
        LEFT JOIN examset es     ON es.examset_id = sl.examset_id
        WHERE s.student_id = :sid
          AND s.end_time IS NOT NULL
        ORDER BY s.end_time DESC, s.session_id DESC
        LIMIT 1
    ");
    $st->execute([':sid' => $studentId]);
    $sess = $st->fetch(PDO::FETCH_ASSOC);

    if (!$sess) {
        echo json_encode(['status' => 'empty', 'message' => 'no finished session'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $sessionId = (int)$sess['session_id'];

    // นับข้อ/ถูก/ผิดจากตาราง answer
    $st2 = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct
        FROM answer
        WHERE session_id = :sid
    ");
    $st2->execute([':sid' => $sessionId]);
    $row = $st2->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'correct' => 0];
    $total   = (int)$row['total'];
    $correct = (int)$row['correct'];
    $wrong   = max(0, $total - $correct);

    echo json_encode([
        'status'   => 'success',
        'session_id'   => $sessionId,
        'student_id'   => $sess['student_id'],
        'exam_title'   => $sess['exam_title'],
        'start_time'   => $sess['start_time'],
        'end_time'     => $sess['end_time'],
        'score'        => is_null($sess['score']) ? null : (float)$sess['score'],
        'attempt_no'   => is_null($sess['attempt_no']) ? null : (int)$sess['attempt_no'],
        'total_questions' => $total,
        'correct_count'   => $correct,
        'wrong_count'     => $wrong
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
