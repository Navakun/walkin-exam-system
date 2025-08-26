<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'SERVER_FATAL','detail'=>$e['message']]);
  }
});

function getBearerToken(): ?string {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');
  if (!$auth && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
      if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; break; }
    }
  }
  if (!$auth) return null;
  $auth = trim(preg_replace('/\s+/', ' ', $auth));
  if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) return null;
  return preg_replace('/[\x00-\x1F\x7F]/', '', $m[1]) ?: null;
}

try {
  // 1) include DB
  $dbPath = __DIR__ . '/../config/db.php';
  if (!file_exists($dbPath)) throw new RuntimeException('db.php not found');
  require_once $dbPath; // ต้องประกาศ $pdo (PDO MySQL, DB: walkin_exam_db)
  if (!isset($pdo)) throw new RuntimeException('PDO $pdo not defined');

  // 2) Auth (ใช้ token ครู)
  $token = getBearerToken();
  if (!$token || strlen($token) < 16) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'UNAUTHORIZED']);
    exit;
  }
  // (ถ้าจะตรวจ token จริง ให้เช็คใน DB/Redis ตรงนี้)

  // 3) ดึงผลสอบ (join ตาม schema)
  $sql = "
    SELECT
      s.session_id,
      st.student_id,
      st.name        AS student_name,
      es.title       AS exam_title,
      sess.start_time,
      sess.end_time,
      sess.score
    FROM examsession sess
    JOIN student st   ON st.student_id = sess.student_id
    JOIN examset es   ON es.examset_id = sess.examset_id
    ORDER BY sess.start_time DESC, sess.session_id DESC
  ";

  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['status'=>'success','results'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  error_log('[get_all_results] '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
