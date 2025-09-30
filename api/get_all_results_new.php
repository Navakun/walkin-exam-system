<?php

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

// ------- Auth -------
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
$claims = decodeToken($m[1]);
if (!$claims) out(['status' => 'error', 'message' => 'Unauthorized'], 401);

$claims = (array)$claims;

function tokenHasTeacherRole(array $c): bool
{
    $allow = ['teacher', 'instructor', 'admin', 'lecturer', 'staff'];
    // เดี่ยว ๆ
    $cand = strtolower((string)($c['role'] ?? $c['user_role'] ?? $c['typ'] ?? ''));
    if (in_array($cand, $allow, true)) return true;

    // scope/roles เป็น string
    foreach (['scope', 'scopes', 'roles'] as $k) {
        if (!empty($c[$k]) && is_string($c[$k])) {
            $parts = preg_split('/[\s,]+/', strtolower($c[$k]));
            if (array_intersect($parts, $allow)) return true;
        }
    }
    // roles เป็น array
    if (!empty($c['roles']) && is_array($c['roles'])) {
        $parts = array_map('strtolower', array_map('strval', $c['roles']));
        if (array_intersect($parts, $allow)) return true;
    }
    // บางระบบใส่ instructor_id มาก็ยอม
    if (!empty($c['instructor_id'])) return true;

    return false;
}

if (!tokenHasTeacherRole($claims)) {
    out(['status' => 'error', 'message' => 'Forbidden'], 403);
}

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // สรุปคำตอบต่อ session: total & correct
    $scoreAggSql = "
    SELECT a.session_id,
           COUNT(*) AS total_questions,
           SUM(a.selected_choice = q.correct_choice) AS correct_count
    FROM answer a
    JOIN question q ON q.question_id = a.question_id
    GROUP BY a.session_id
  ";

    $sql = "
    SELECT 
      se.session_id,
      se.student_id,
      st.name AS student_name,
      COALESCE(es.title, CONCAT('ชุดสอบ #', COALESCE(sl.examset_id, '—'))) AS exam_title,
      se.start_time,
      se.end_time,
      -- คะแนนที่บันทึกไว้
      se.score AS score_recorded,
      -- คะแนนที่คำนวณจากคำตอบ (ถ้ามี)
      ROUND(100 * COALESCE(sa.correct_count,0) / NULLIF(sa.total_questions,0), 2) AS score_calc,
      -- ส่ง score เป็นค่าที่น่าเชื่อถือที่สุด
      COALESCE(se.score, ROUND(100 * COALESCE(sa.correct_count,0) / NULLIF(sa.total_questions,0), 2)) AS score,
      sa.total_questions,
      sa.correct_count
    FROM examsession se
    JOIN student st        ON st.student_id = se.student_id
    LEFT JOIN exam_slots sl ON sl.id        = se.slot_id
    LEFT JOIN examset es    ON es.examset_id = sl.examset_id
    LEFT JOIN ({$scoreAggSql}) sa ON sa.session_id = se.session_id
    ORDER BY se.session_id DESC
  ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        foreach (['score', 'score_recorded', 'score_calc'] as $k) {
            if ($r[$k] !== null) $r[$k] = (float)$r[$k];
        }
        if ($r['total_questions'] !== null) $r['total_questions'] = (int)$r['total_questions'];
        if ($r['correct_count']   !== null) $r['correct_count']   = (int)$r['correct_count'];
    }
    out(['status' => 'success', 'data' => $rows], 200);
} catch (Throwable $e) {
    error_log('[get_all_results_new] ' . $e->getMessage());

    out(['status' => 'error', 'message' => 'Internal Server Error'], 500);
}
