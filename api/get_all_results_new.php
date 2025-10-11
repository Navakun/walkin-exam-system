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

/* ---------- Auth ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
try {
    $claims = (array)decodeToken($m[1]);
} catch (Throwable $e) {
    out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

$allow = ['teacher', 'instructor', 'admin', 'lecturer', 'staff'];
$role = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$ok = in_array($role, $allow, true);
if (!$ok) {
    foreach (['scope', 'scopes', 'roles'] as $k) {
        if (isset($claims[$k]) && is_string($claims[$k])) {
            $parts = preg_split('/[\s,]+/', strtolower($claims[$k]));
            if (array_intersect($parts, $allow)) {
                $ok = true;
                break;
            }
        } elseif (isset($claims[$k]) && is_array($claims[$k])) {
            $parts = array_map(fn($x) => strtolower((string)$x), $claims[$k]);
            if (array_intersect($parts, $allow)) {
                $ok = true;
                break;
            }
        }
    }
}
if (!$ok && empty($claims['instructor_id'])) out(['status' => 'error', 'message' => 'Forbidden'], 403);

/* ---------- Query ---------- */
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("SET time_zone = '+07:00'");

    // รวมสถิติคำตอบต่อ session (เทียบแบบ byte-for-byte)
    $scoreAggSql = "
    SELECT a.session_id,
           COUNT(*) AS total_questions,
           SUM(CAST(a.selected_choice AS BINARY) = CAST(q.correct_choice AS BINARY)) AS correct_count
    FROM answer a
    JOIN question q ON q.question_id = a.question_id
    GROUP BY a.session_id
  ";

    $sql = "
    SELECT 
      se.session_id,
      se.student_id,
      st.name AS student_name,
      COALESCE(es.title, CONCAT('ชุดสอบ #', COALESCE(sl.examset_id,'—'))) AS exam_title,
      se.start_time,
      se.end_time,
      se.score AS score_recorded,
      ROUND(100 * COALESCE(sa.correct_count,0) / NULLIF(sa.total_questions,0), 2) AS score_calc,
      COALESCE(se.score, ROUND(100 * COALESCE(sa.correct_count,0) / NULLIF(sa.total_questions,0), 2)) AS score,
      sa.total_questions,
      sa.correct_count
    FROM examsession se
    JOIN student st          ON st.student_id = se.student_id
    LEFT JOIN exam_slots sl  ON sl.id = se.slot_id
    LEFT JOIN examset es     ON es.examset_id = sl.examset_id
    LEFT JOIN ($scoreAggSql) sa ON sa.session_id = se.session_id
    ORDER BY se.session_id DESC
  ";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        foreach (['score', 'score_recorded', 'score_calc'] as $k) {
            if ($r[$k] !== null) $r[$k] = (float)$r[$k];
        }
        foreach (['total_questions', 'correct_count'] as $k) {
            if ($r[$k] !== null) $r[$k] = (int)$r[$k];
        }
    }
    unset($r);

    out(['status' => 'success', 'data' => $rows]);
} catch (Throwable $e) {
    error_log('[get_all_results_new] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    out(['status' => 'error', 'message' => 'Internal Server Error'], 500);
}
