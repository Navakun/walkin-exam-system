<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/get_exam_statistics_error.log');

require_once __DIR__ . '/../config/db.php';

function out($o, int $code = 200)
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(int $code, string $msg)
{
    out(['status' => 'error', 'message' => $msg], $code);
}
function asInt($v)
{
    return (int)$v;
}

function getHeaderAuth(): string
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (!empty($h['Authorization'])) return $h['Authorization'];
        if (!empty($h['authorization'])) return $h['authorization'];
    }
    return $_SERVER['HTTP_AUTHORIZATION'] ?? $_ENV['HTTP_AUTHORIZATION'] ?? '';
}
function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $sql = "SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}
function hasTable(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return ((int)$st->fetchColumn()) > 0;
}
function normalizeDateRange(?string $from, ?string $to): array
{
    $from = trim($from ?? '');
    $to   = trim($to ?? '');
    $fromDt = $from ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from . ' 00:00:00' : $from) : null;
    $toDt   = $to   ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)   ? $to . ' 23:59:59'  : $to)   : null;
    return [$fromDt, $toDt];
}

// --- AUTH (ถ้าต้องใช้ helper token ก็มาเพิ่มตรงนี้) ---
$auth = getHeaderAuth();
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) fail(401, 'Unauthorized');

if (!isset($pdo)) fail(500, 'SERVER_ERROR');

// --- INPUT ---
$slotId     = isset($_GET['slot_id'])     ? asInt($_GET['slot_id'])     : 0;
$examsetId  = isset($_GET['examset_id'])  ? asInt($_GET['examset_id'])  : 0;
$fromStr    = $_GET['from'] ?? '';
$toStr      = $_GET['to']   ?? '';
[$fromDt, $toDt] = normalizeDateRange($fromStr, $toStr);

// --- WHERE ---
$where = [];
$params = [];
$where[] = "s.end_time IS NOT NULL"; // เฉพาะ session ที่สิ้นสุดแล้ว

if ($slotId > 0 && hasColumn($pdo, 'examsession', 'slot_id')) {
    $where[] = "s.slot_id = :slot_id";
    $params[':slot_id'] = $slotId;
}
if ($examsetId > 0 && hasTable($pdo, 'exam_slots') && hasColumn($pdo, 'exam_slots', 'examset_id')) {
    // กรองตาม examset ผ่าน join exam_slots
    $where[] = "es.examset_id = :examset_id";
    $params[':examset_id'] = $examsetId;
}
if ($fromDt) {
    $where[] = "s.start_time >= :from_dt";
    $params[':from_dt'] = $fromDt;
}
if ($toDt) {
    $where[] = "s.end_time   <= :to_dt";
    $params[':to_dt']   = $toDt;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // ---- per question (join question + exam_slots) ----
    $joinExamSlots = hasTable($pdo, 'exam_slots') ? "LEFT JOIN exam_slots es ON es.id = s.slot_id" : "";
    $sqlQ = "
    SELECT a.question_id,
           COUNT(*) AS attempts,
           SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS corrects,
           q.question_text
    FROM answer a
    JOIN examsession s ON s.session_id = a.session_id
    $joinExamSlots
    LEFT JOIN question q ON q.question_id = a.question_id
    $whereSql
    GROUP BY a.question_id
    ORDER BY a.question_id
  ";
    $st = $pdo->prepare($sqlQ);
    $st->execute($params);

    $perQuestion = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $attempts = (int)$r['attempts'];
        $corrects = (int)$r['corrects'];
        $perQuestion[] = [
            'id'            => (int)$r['question_id'],
            'attempts'      => $attempts,
            'corrects'      => $corrects,
            'wrongs'        => max(0, $attempts - $corrects),
            'accuracy'      => $attempts > 0 ? round($corrects * 100.0 / $attempts, 2) : 0.0,
            'question_text' => $r['question_text'] ?? null
        ];
    }

    // ---- per session → histogram ----
    $sqlSess = "
    SELECT a.session_id,
           SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS corrects,
           COUNT(*) AS total
    FROM answer a
    JOIN examsession s ON s.session_id = a.session_id
    $joinExamSlots
    $whereSql
    GROUP BY a.session_id
  ";
    $st2 = $pdo->prepare($sqlSess);
    $st2->execute($params);
    $perSession = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $hist = [];
    $totalSessions = 0;
    $sumCorrects = 0;
    $maxCorrectPossible = 0;
    foreach ($perSession as $r) {
        $c = (int)$r['corrects'];
        $t = (int)$r['total'];
        $hist[$c] = ($hist[$c] ?? 0) + 1;
        $totalSessions++;
        $sumCorrects += $c;
        if ($t > $maxCorrectPossible) $maxCorrectPossible = $t;
    }
    ksort($hist);
    $histogram = [];
    foreach ($hist as $c => $students) {
        $histogram[] = ['corrects' => (int)$c, 'students' => (int)$students];
    }

    // ---- totals ----
    $totalQuestions = !empty($perQuestion) ? count($perQuestion) : 0;
    if ($totalQuestions === 0) {
        $sqlDistinct = "
      SELECT COUNT(DISTINCT a.question_id)
      FROM answer a
      JOIN examsession s ON s.session_id = a.session_id
      $joinExamSlots
      $whereSql
    ";
        $st3 = $pdo->prepare($sqlDistinct);
        $st3->execute($params);
        $totalQuestions = (int)$st3->fetchColumn();
    }

    $avgCorrect = $totalSessions > 0 ? round($sumCorrects / $totalSessions, 2) : 0.0;
    $avgScorePercent = ($maxCorrectPossible > 0 && $totalSessions > 0)
        ? round(($sumCorrects / ($totalSessions * $maxCorrectPossible)) * 100, 2)
        : 0.0;

    // ---- dropdown: slots ----
    $slots = [];
    if (hasTable($pdo, 'exam_slots') && hasColumn($pdo, 'examsession', 'slot_id')) {
        $st4 = $pdo->query("
      SELECT DISTINCT s.slot_id AS id,
             es.exam_date,
             es.start_time,
             es.end_time,
             es.examset_id
      FROM examsession s
      LEFT JOIN exam_slots es ON es.id = s.slot_id
      WHERE s.slot_id IS NOT NULL
      ORDER BY s.slot_id DESC
    ");
        $slots = array_map(fn($x) => [
            'id'         => (int)$x['id'],
            'exam_date'  => $x['exam_date'] ?? null,
            'start_time' => $x['start_time'] ?? null,
            'end_time'   => $x['end_time'] ?? null,
            'examset_id' => isset($x['examset_id']) ? (int)$x['examset_id'] : null,
        ], $st4->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    // ---- dropdown: examsets (จาก exam_slots ที่เคยมี session) ----
    $examsets = [];
    if (hasTable($pdo, 'exam_slots') && hasTable($pdo, 'examset')) {
        $titleCol = hasColumn($pdo, 'examset', 'title') ? 'e.title' : 'NULL';
        $st5 = $pdo->query("
      SELECT DISTINCT es.examset_id AS id, $titleCol AS name
      FROM examsession s
      JOIN exam_slots es ON es.id = s.slot_id
      LEFT JOIN examset e ON e.examset_id = es.examset_id
      WHERE es.examset_id IS NOT NULL
      ORDER BY es.examset_id DESC
    ");
        $examsets = array_map(fn($x) => [
            'id' => (int)$x['id'],
            'name' => $x['name'] ?? null
        ], $st5->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    out([
        'status' => 'success',
        'data' => [
            'filters' => [
                'slot_id'    => $slotId ?: null,
                'examset_id' => $examsetId ?: null,
                'from'       => $fromStr ?: null,
                'to'         => $toStr ?: null
            ],
            'per_question' => $perQuestion,   // [{id, attempts, corrects, wrongs, accuracy, question_text}]
            'histogram'    => $histogram,     // [{corrects, students}]
            'totals' => [
                'sessions'          => $totalSessions,
                'total_questions'   => $totalQuestions,
                'avg_correct'       => $avgCorrect,
                'avg_score_percent' => $avgScorePercent
            ],
            'slots'    => $slots,             // [{id, exam_date, start_time, end_time, examset_id}]
            'examsets' => $examsets           // [{id, name|null}]
        ]
    ]);
} catch (Throwable $e) {
    error_log('[get_exam_statistics] ' . $e->getMessage());
    fail(500, 'SERVER_ERROR');
}
