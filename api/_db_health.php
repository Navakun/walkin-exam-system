<?php
header('Content-Type: application/json; charset=utf-8');
$path = __DIR__ . '/../config/db.php';
if (!file_exists($path)) { echo json_encode(['ok'=>false,'err'=>'db.php not found','path'=>$path]); exit; }
require_once $path;       // db.php ต้องประกาศ $pdo = new PDO(...)

try {
  $r = $pdo->query('SELECT 1 AS v')->fetch();
  echo json_encode(['ok'=>true,'db'=>$r['v']??null]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'err'=>$e->getMessage()]);
}
