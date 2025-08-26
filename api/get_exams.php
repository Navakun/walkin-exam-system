<?php
// ปิด error บน prod ได้ภายหลัง
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
  // 1) DB
  require_once __DIR__ . '/../config/db.php'; // สร้าง $pdo (PDO)

  // 2) รับ/เช็ค token แบบ "opaque"
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'MISSING_BEARER_TOKEN']); exit;
  }
  $token = trim($m[1]);
  if ($token === '') {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'EMPTY_TOKEN']); exit;
  }
  // **สำคัญ**: ตอนนี้เรา "ไม่" decode JWT ใด ๆ แค่ตรวจว่ามี token มาก็พอ
  // (ในอนาคตค่อยเปลี่ยนเป็น JWT จริงพร้อมตรวจลายเซ็น)

  // 3) ดึงชุดข้อสอบ
  $stmt = $pdo->query("SELECT examset_id, title, chapter, created_at FROM examset ORDER BY examset_id ASC");
  $rows = $stmt->fetchAll();

  echo json_encode([
    'status' => 'success',
    'exams'  => $rows
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
