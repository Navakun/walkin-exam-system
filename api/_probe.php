<?php
header('Content-Type: application/json; charset=utf-8');

$out = [
  'php_version' => PHP_VERSION,
  'extensions'  => get_loaded_extensions(),
  'pdo_loaded'  => extension_loaded('pdo'),
  'pdo_mysql'   => extension_loaded('pdo_mysql'),
  'cwd'         => getcwd(),
  '__DIR__'     => __DIR__,
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
