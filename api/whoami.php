<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../api/helpers/jwt_helper.php';
require_once __DIR__ . '/../api/helpers/instructor_helper.php';

function getAuthHeader(): string {
  if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (isset($h['Authorization'])) return $h['Authorization'];
    if (isset($h['authorization'])) return $h['authorization'];
  }
  if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
  if (!empty($_ENV['HTTP_AUTHORIZATION'])) return $_ENV['HTTP_AUTHORIZATION'];
  return '';
}
$auth = getAuthHeader();
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'reason'=>'no header']); exit;
}
$token = $m[1];
$inst = getInstructorFromToken($token);
if (!$inst) { 
    http_response_code(401); 
    echo json_encode(['ok'=>false,'reason'=>'invalid token']); 
    exit; 
}
echo json_encode(['ok'=>true,'instructor'=>$inst]);
