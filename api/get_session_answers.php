<?php
// api/get_session_answers.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';

$sid = isset($_GET['session_id']) ? (int)$_GET['session_id'] : 0;
if ($sid <= 0) {
  http_response_code(400);
  echo json_encode(['status'=>'error','message'=>'missing session_id'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $pdo = function_exists('pdo') ? pdo() : $pdo;

  // 1) meta + question_ids
  // แทนคิวรีเดิมที่มี s.status
$st = $pdo->prepare("
  SELECT s.session_id, s.student_id, st.name AS student_name,
         s.examset_id, es.title AS exam_title,
         s.start_time, s.end_time, s.attempt_no, s.score, s.question_ids,
         CASE
           WHEN s.end_time IS NOT NULL THEN 'completed'
           WHEN s.start_time IS NOT NULL THEN 'in_progress'
           ELSE 'registered'
         END AS status
  FROM examsession s
  JOIN student st ON st.student_id = s.student_id
  JOIN examset  es ON es.examset_id  = s.examset_id
  WHERE s.session_id = :sid
  LIMIT 1
");

  $st->execute([':sid'=>$sid]);
  $meta = $st->fetch();
  if (!$meta) {
    http_response_code(404);
    echo json_encode(['status'=>'error','message'=>'session not found'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $examsetId = (int)$meta['examset_id'];

  // 2) รวมแหล่ง question_id
  $qidList = [];

  // 2.1 จาก question_ids (JSON)
  if (!empty($meta['question_ids'])) {
    $tmp = json_decode($meta['question_ids'], true);
    if (is_array($tmp)) {
      foreach ($tmp as $q) { $q = (int)$q; if ($q>0) $qidList[] = $q; }
    }
  }

  // 2.2 ถ้ายังว่าง ลองจาก exam_set_question
  if (!$qidList) {
    try {
      $pdo->query("SELECT 1 FROM exam_set_question LIMIT 1");
      $st = $pdo->prepare("SELECT question_id FROM exam_set_question WHERE examset_id = :esid ORDER BY COALESCE(seq, question_id)");
      $st->execute([':esid'=>$examsetId]);
      $qidList = array_map('intval', array_column($st->fetchAll(), 'question_id'));
    } catch (Throwable $e) {
      // ไม่มีตาราง mapping ก็ข้าม
    }
  }

  // 2.3 ถ้ายังว่างอีก ลองจาก answer ของ session นี้
  if (!$qidList) {
    $st = $pdo->prepare("SELECT DISTINCT question_id FROM answer WHERE session_id = :sid ORDER BY answer_id");
    $st->execute([':sid'=>$sid]);
    $qidList = array_map('intval', array_column($st->fetchAll(), 'question_id'));
  }

  // unique
  $qidList = array_values(array_unique(array_filter($qidList, fn($v)=>$v>0)));

  // 3) ถ้ายังไม่มีคำถามจริง ๆ
  if (!$qidList) {
    echo json_encode([
      'status'          => 'success',
      'details'         => [
        'session_id'=>(int)$meta['session_id'],
        'student_id'=>$meta['student_id'],
        'student_name'=>$meta['student_name'],
        'exam_title'=>$meta['exam_title'],
        'start_time'=>$meta['start_time'],
        'end_time'=>$meta['end_time'],
        'status'=>$meta['status'],
        'attempt_no'=>(int)$meta['attempt_no'],
        'score'=> is_null($meta['score'])? null : (float)$meta['score'],
      ],
      'total_questions' => 0,
      'correct_count'   => 0,
      'wrong_count'     => 0,
      'items'           => []
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // 4) placeholders + ORDER BY CASE เพื่อคงลำดับ
  $params = [':sid'=>$sid];
  $ph = [];
  $case = [];
  foreach ($qidList as $i=>$qid) {
    $k=":q$i"; $params[$k]=$qid; $ph[]=$k; $case[]="WHEN $qid THEN ".($i+1);
  }

  // 5) ดึงทุกข้อ + คำตอบล่าสุดต่อข้อ (LEFT JOIN)
  // NOTE: ถ้าตารางคุณชื่อ `questions` ให้เปลี่ยนจาก `question` เป็น `questions`
  $sql = "
    SELECT
      q.question_id,
      q.question_text,
      q.correct_choice,
      a.selected_choice AS student_choice,
      CASE
        WHEN a.selected_choice IS NULL THEN 0
        WHEN a.selected_choice = q.correct_choice THEN 1
        ELSE 0
      END AS is_correct,
      co.content AS student_choice_text,
      cc.content AS correct_choice_text
    FROM (
      SELECT q.question_id, q.question_text, q.correct_choice,
             CASE q.question_id ".implode(' ', $case)." ELSE 9999 END AS ordx
      FROM question q
      WHERE q.question_id IN (".implode(',', $ph).")
    ) q
    LEFT JOIN (
      SELECT a1.*
      FROM answer a1
      JOIN (
        SELECT question_id, MAX(answer_id) AS last_id
        FROM answer
        WHERE session_id = :sid
        GROUP BY question_id
      ) la ON la.last_id = a1.answer_id
    ) a ON a.question_id = q.question_id
    LEFT JOIN choice co ON co.question_id = q.question_id AND co.label = a.selected_choice
    LEFT JOIN choice cc ON cc.question_id = q.question_id AND cc.label = q.correct_choice
    ORDER BY q.ordx, q.question_id
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll();

  // 6) สรุปจากรายการเดียวกับที่แสดง
  $total   = count($items);
  $correct = 0; foreach ($items as $r) if ((int)$r['is_correct'] === 1) $correct++;
  $wrong   = $total - $correct;

  echo json_encode([
    'status'          => 'success',
    'details'         => [
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
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
