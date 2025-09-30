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
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
}

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) require_once $autoload;
require_once $root . '/api/helpers/instructor_helper.php'; // getInstructorFromToken()

if (!isset($pdo)) fail(500, 'SERVER_ERROR');

$auth = getHeaderAuth();
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) fail(401, 'Unauthorized');
$token = $m[1];

$inst = function_exists('getInstructorFromToken') ? getInstructorFromToken($token) : null;
if (!$inst || empty($inst['instructor_id'])) fail(401, 'Unauthorized');

$examsetId = isset($_GET['examset_id']) ? asInt($_GET['examset_id']) : 0;
$fromStr   = trim($_GET['from'] ?? '');
$toStr     = trim($_GET['to'] ?? '');

// build WHERE
$where = [];
$params = [];
// เฉพาะ session ที่จบแล้ว
$where[] = "s.end_time IS NOT NULL";

if ($examsetId > 0 && hasColumn($pdo, 'examsession', 'examset_id')) {
    $where[] = "s.examset_id = :examset_id";
    $params[':examset_id'] = $examsetId;
}
if ($fromStr !== '') {
    $where[] = "s.start_time >= :from_dt";
    $params[':from_dt'] = $fromStr;
}
if ($toStr !== '') {
    $where[] = "s.end_time <= :to_dt";
    $params[':to_dt'] = $toStr;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $pdo->exec("SET time_zone = '+07:00'");

    // 1) per question
    $sqlQ = "
    SELECT a.question_id,
           COUNT(*) AS attempts,
           SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS corrects
    FROM answer a
    JOIN examsession s ON s.session_id = a.session_id
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
            'question_id' => (int)$r['question_id'],
            'attempts'    => $attempts,
            'corrects'    => $corrects,
            'wrongs'      => max(0, $attempts - $corrects),
            'accuracy'    => $attempts > 0 ? round($corrects * 100.0 / $attempts, 2) : 0.0
        ];
    }

    // 2) per session -> histogram จำนวนข้อที่ทำถูก
    $sqlSess = "
    SELECT a.session_id,
           SUM(CASE WHEN a.is_correct = 1 THEN 1 ELSE 0 END) AS corrects,
           COUNT(*) AS total
    FROM answer a
    JOIN examsession s ON s.session_id = a.session_id
    $whereSql
    GROUP BY a.session_id
  ";
    $st2 = $pdo->prepare($sqlSess);
    $st2->execute($params);
    $perSession = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // histogram corrects -> students
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

    // meta
    $totalQuestions = 0;
    if (!empty($perQuestion)) $totalQuestions = count($perQuestion);
    else {
        // fallback จาก distinct questions
        $sqlDistinct = "
      SELECT COUNT(DISTINCT a.question_id)
      FROM answer a
      JOIN examsession s ON s.session_id = a.session_id
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

    // (เสริม) รายการ examset ที่มีให้เลือก (ถ้ามีคอลัมน์)
    $examsets = [];
    if (hasColumn($pdo, 'examsession', 'examset_id')) {
        $st4 = $pdo->query("SELECT DISTINCT examset_id FROM examsession WHERE examset_id IS NOT NULL ORDER BY examset_id DESC");
        $examsets = array_map(fn($x) => (int)$x['examset_id'], $st4->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    out([
        'status' => 'success',
        'data' => [
            'filters' => [
                'examset_id' => $examsetId ?: null,
                'from' => $fromStr ?: null,
                'to'   => $toStr ?: null
            ],
            'per_question' => $perQuestion,
            'histogram'    => $histogram,
            'totals' => [
                'sessions'          => $totalSessions,
                'total_questions'   => $totalQuestions,
                'avg_correct'       => $avgCorrect,
                'avg_score_percent' => $avgScorePercent
            ],
            'examsets' => $examsets
        ]
    ]);
} catch (Throwable $e) {
    error_log('[get_exam_statistics] ' . $e->getMessage());
    fail(500, 'SERVER_ERROR');
}
