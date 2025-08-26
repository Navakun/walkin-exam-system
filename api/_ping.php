<?php
header('Content-Type: application/json; charset=utf-8');

try {
  $dbPath = __DIR__ . '/../config/db.php';
  if (!file_exists($dbPath)) throw new RuntimeException('db.php not found at ' . $dbPath);
  require $dbPath; // ต้องได้ $pdo

  if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined');

  $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
  $one    = $pdo->query('SELECT 1')->fetchColumn();

  // เช็คว่ามีตาราง student จริง
  $tbl = $pdo->query("SHOW TABLES LIKE 'student'")->fetchColumn();

  echo json_encode([
    'ok' => true,
    'database' => $dbName,
    'select1'  => $one,
    'has_student_table' => !!$tbl
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
