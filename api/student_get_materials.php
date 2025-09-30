<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/student_get_materials_error.log');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function authHeader(): string
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        $a = $h['Authorization'] ?? $h['authorization'] ?? '';
        if ($a) return $a;
    }
    return $_SERVER['HTTP_AUTHORIZATION'] ?? $_ENV['HTTP_AUTHORIZATION'] ?? '';
}

require_once __DIR__ . '/../config/db.php'; // สร้าง $pdo

if (!isset($pdo)) {
    error_log('DB not connected');
    fail(500, 'SERVER_ERROR');
}

// ---- ตรวจ Authorization แบบหลวม ๆ (พอให้รู้ว่า login แล้ว) ----
$auth = authHeader();
if (!preg_match('/^Bearer\s+.+/i', $auth)) {
    fail(401, 'Unauthorized');
}

// ---- ดึงรายการเอกสาร (ไม่ผูกอาจารย์ก่อน ช่วยตัดปัญหา join/คีย์หาย) ----
try {
    $sql = "
    SELECT
      m.material_id,
      m.title,
      COALESCE(m.description,'') AS description,
      m.file_path,
      COALESCE(m.file_size,0)    AS file_size,
      COALESCE(m.file_type,'')   AS file_type,
      m.upload_date,
      COALESCE(m.download_count,0) AS download_count,
      i.name AS teacher_name
    FROM teaching_materials m
    LEFT JOIN instructor i ON i.instructor_id = m.instructor_id
    ORDER BY COALESCE(m.upload_date, m.material_id) DESC
  ";
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ทำความสะอาดข้อมูลส่งออก
    $materials = array_map(static function (array $r): array {
        return [
            'material_id'    => (int)$r['material_id'],
            'title'          => (string)($r['title'] ?? ''),
            'description'    => (string)($r['description'] ?? ''),
            'file_path'      => (string)($r['file_path'] ?? ''),
            'file_size'      => (int)($r['file_size'] ?? 0),
            'file_type'      => (string)($r['file_type'] ?? ''),
            'upload_date'    => (string)($r['upload_date'] ?? ''),
            'download_count' => (int)($r['download_count'] ?? 0),
            'teacher_name'   => (string)($r['teacher_name'] ?? ''),
        ];
    }, $rows);

    echo json_encode(['status' => 'success', 'materials' => $materials], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // บันทึก error จริงลงไฟล์นี้: api/student_get_materials_error.log
    error_log('[student_get_materials] ' . $e->getMessage());
    fail(500, 'SERVER_ERROR');
}
