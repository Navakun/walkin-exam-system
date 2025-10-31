<?php

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/get_session_answers_error.log');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/helpers/jwt_helper.php';

function out(array $o, int $code = 200): void
{
  http_response_code($code);
  echo json_encode($o, JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- auth ---------- */
$h = array_change_key_case(getallheaders(), CASE_LOWER);
if (!isset($h['authorization']) || !preg_match('/bearer\s+(\S+)/i', $h['authorization'], $m)) {
  out(['status' => 'error', 'message' => 'Unauthorized'], 401);
}
$claims = decodeToken($m[1]);
if (!$claims) out(['status' => 'error', 'message' => 'Unauthorized'], 401);
$claims = (array)$claims;

$allowRoles = ['teacher', 'instructor', 'admin', 'lecturer', 'staff'];
$roleStr = strtolower((string)($claims['role'] ?? $claims['user_role'] ?? $claims['typ'] ?? ''));
$scopes  = strtolower((string)($claims['scope'] ?? $claims['scopes'] ?? $claims['roles'] ?? ''));
$rolesOk = in_array($roleStr, $allowRoles, true)
  || array_intersect(preg_split('/[\s,]+/', $scopes), $allowRoles)
  || !empty($claims['instructor_id']);
if (!$rolesOk) out(['status' => 'error', 'message' => 'Forbidden'], 403);

/* ---------- input ---------- */
$sid = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sid <= 0) out(['status' => 'error', 'message' => 'missing session_id'], 400);

try {
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec("SET time_zone = '+07:00'");
  $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

  /* 1) meta */
  $st = $pdo->prepare("
    SELECT
      s.session_id,
      s.student_id,
      st.name AS student_name,
      es.examset_id AS examset_id,
      es.title      AS exam_title,
      s.start_time, s.end_time, s.attempt_no, s.score, s.question_ids,
      CASE
        WHEN s.end_time IS NOT NULL THEN 'completed'
        WHEN s.start_time IS NOT NULL THEN 'in_progress'
        ELSE 'registered'
      END AS status
    FROM examsession s
    JOIN student st         ON st.student_id = s.student_id
    LEFT JOIN exam_slots sl ON sl.id = s.slot_id
    LEFT JOIN examset   es  ON es.examset_id = sl.examset_id
    WHERE s.session_id = :sid
    LIMIT 1
  ");
  $st->execute([':sid' => $sid]);
  $meta = $st->fetch(PDO::FETCH_ASSOC);
  if (!$meta) out(['status' => 'error', 'message' => 'session not found'], 404);

  $examsetId = (int)($meta['examset_id'] ?? 0);

  /* 2) build ordered question_id list */
  $qidList = [];
  if (!empty($meta['question_ids'])) {
    $tmp = json_decode($meta['question_ids'], true);
    if (is_array($tmp)) foreach ($tmp as $q) {
      $q = (int)$q;
      if ($q > 0) $qidList[] = $q;
    }
  }
  if (!$qidList) {
    try {
      $pdo->query("SELECT 1 FROM exam_set_question LIMIT 1");
      $st = $pdo->prepare("
        SELECT question_id
        FROM exam_set_question
        WHERE examset_id = :es
        ORDER BY COALESCE(seq, question_id)
      ");
      $st->execute([':es' => $examsetId]);
      $qidList = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'question_id'));
    } catch (Throwable $e) { /* ignore */
    }
  }
  if (!$qidList) {
    $st = $pdo->prepare("
      SELECT question_id
      FROM answer
      WHERE session_id = :sid
      GROUP BY question_id
      ORDER BY MIN(answer_id)
    ");
    $st->execute([':sid' => $sid]);
    $qidList = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'question_id'));
  }
  $qidList = array_values(array_unique(array_filter($qidList, fn($v) => $v > 0)));

  if (!$qidList) {
    out([
      'status' => 'success',
      'details' => [
        'session_id'   => (int)$meta['session_id'],
        'student_id'   => $meta['student_id'],
        'student_name' => $meta['student_name'],
        'exam_title'   => $meta['exam_title'],
        'start_time'   => $meta['start_time'],
        'end_time'     => $meta['end_time'],
        'status'       => $meta['status'],
        'attempt_no'   => (int)$meta['attempt_no'],
        'score'        => is_null($meta['score']) ? null : (float)$meta['score'],
      ],
      'total_questions' => 0,
      'correct_count'   => 0,
      'wrong_count'     => 0,
      'items'           => []
    ], 200);
  }

  /* 3) fetch answers + question info (✅ เพิ่ม cognitive_level_id + labels) */
  $params = [':sid' => $sid];
  $ph = [];
  $case = [];
  foreach ($qidList as $i => $qid) {
    $k = ":q$i";
    $params[$k] = $qid;
    $ph[] = $k;
    $case[] = "WHEN $qid THEN " . ($i + 1);
  }

  $sql = "
    SELECT
      q.question_id,
      q.question_text,
      q.correct_choice,
      q.cognitive_level_id,             -- ✅ ระดับผลลัพธ์ใน question
      cl.code       AS cognitive_code,  -- (optional) code: UNDERSTAND/APPLY/ANALYZE
      cl.th_label   AS cognitive_th,    -- (optional) ป้ายไทย
      cl.en_label   AS cognitive_en,    -- (optional) ป้ายอังกฤษ
      a.selected_choice AS student_choice,
      CASE
        WHEN a.selected_choice IS NULL THEN 0
        WHEN CAST(a.selected_choice AS BINARY) = CAST(q.correct_choice AS BINARY) THEN 1
        ELSE 0
      END AS is_correct,
      co.content AS student_choice_text,
      cc.content AS correct_choice_text
    FROM (
      SELECT q.question_id, q.question_text, q.correct_choice, q.cognitive_level_id,
             CASE q.question_id " . implode(' ', $case) . " ELSE 9999 END AS ordx
      FROM question q
      WHERE q.question_id IN (" . implode(',', $ph) . ")
    ) q
    LEFT JOIN cognitive_levels cl
           ON cl.level_id = q.cognitive_level_id
    LEFT JOIN (
      SELECT a1.*
      FROM answer a1
      JOIN (
        SELECT question_id, MAX(answer_id) AS last_id
        FROM answer
        WHERE session_id = :sid
        GROUP BY question_id
      ) la ON la.last_id = a1.answer_id
    ) a  ON a.question_id = q.question_id
    LEFT JOIN choice co
           ON co.question_id = q.question_id
          AND CAST(co.label AS BINARY) = CAST(a.selected_choice AS BINARY)
    LEFT JOIN choice cc
           ON cc.question_id = q.question_id
          AND CAST(cc.label AS BINARY) = CAST(q.correct_choice AS BINARY)
    ORDER BY q.ordx, q.question_id
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  $total = count($items);
  $correct = 0;
  foreach ($items as $r) if ((int)$r['is_correct'] === 1) $correct++;
  $wrong = max(0, $total - $correct);

  out([
    'status' => 'success',
    'details' => [
      'session_id'   => (int)$meta['session_id'],
      'student_id'   => $meta['student_id'],
      'student_name' => $meta['student_name'],
      'exam_title'   => $meta['exam_title'],
      'start_time'   => $meta['start_time'],
      'end_time'     => $meta['end_time'],
      'status'       => $meta['status'],
      'attempt_no'   => (int)$meta['attempt_no'],
      'score'        => is_null($meta['score']) ? null : (float)$meta['score'],
    ],
    'total_questions' => $total,
    'correct_count'   => $correct,
    'wrong_count'     => $wrong,
    'items'           => $items
  ], 200);
} catch (Throwable $e) {
  error_log('[get_session_answers] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
  out(['status' => 'error', 'message' => 'Server error'], 500);
}
