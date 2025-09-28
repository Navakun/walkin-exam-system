<?php
// api/get_all_results_new.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/get_all_results_new_error.log');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function out($o, int $code = 200)
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}

// ------- Auth: รับ Bearer token แล้วตรวจ role แบบยืดหยุ่น -------
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
$claims = decodeToken($m[1]);
if (!$claims) out(['status' => 'error', 'message' => 'Unauthorized'], 401);

$claims = (array)$claims;
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? $claims['scope'] ?? ''));
$allowRoles = ['teacher', 'instructor', 'admin'];
if (!in_array($role, $allowRoles, true)) {
    out(['status' => 'error', 'message' => 'Forbidden'], 403);
}

// (ถ้ามีในโทเค็น) ไว้ใช้ฟิลเตอร์รายผู้สอนภายหลังได้
$instructorId = $claims['instructor_id'] ?? $claims['sub'] ?? null;

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // ดึงรายการ session ทั้งหมด + ชื่อ นศ. + ชุดข้อสอบ (ผ่าน exam_slots -> examset)
    // !! ไม่ผูกกับ s.examset_id อีกต่อไป เพื่อตัด error "Unknown column 's.examset_id'"
    $sql = "
    SELECT 
      se.session_id,
      se.student_id,
      st.name AS student_name,
      COALESCE(es.title, CONCAT('ชุดสอบ #', COALESCE(sl.examset_id, '—'))) AS exam_title,
      se.start_time,
      se.end_time,
      se.score
    FROM examsession se
    JOIN student st       ON st.student_id = se.student_id
    LEFT JOIN exam_slots sl ON sl.id        = se.slot_id
    LEFT JOIN examset es    ON es.examset_id = sl.examset_id
    ORDER BY se.session_id DESC
  ";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // แปลงคะแนนเป็นเลขทศนิยม 2 ตำแหน่ง (ถ้ามี)
    foreach ($rows as &$r) {
        if ($r['score'] !== null) $r['score'] = (float)$r['score'];
    }

    out(['status' => 'success', 'data' => $rows], 200);
} catch (Throwable $e) {
    error_log('[get_all_results_new] ' . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error'], 500);
}
