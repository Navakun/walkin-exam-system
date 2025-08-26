<?php
$pdo = new PDO(
  'mysql:host=127.0.0.1;dbname=walkin_exam_db;charset=utf8mb4',
  'YOUR_DB_USER',
  'YOUR_DB_PASS',
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]
);
