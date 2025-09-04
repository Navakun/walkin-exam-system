<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

function json_fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
function getAuthHeader(): string
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (isset($h['Authorization'])) return $h['Authorization'];
        if (isset($h['authorization'])) return $h['authorization'];
    }
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return $_SERVER['HTTP_AUTHORIZATION'];
    if (!empty($_ENV['HTTP_AUTHORIZATION']))   return $_ENV['HTTP_AUTHORIZATION'];
    return '';
}

try {
    $root = dirname(__DIR__);

    // ✅ ต้องมีบรรทัดนี้ (สำคัญ)
    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    } else {
        error_log('NO_AUTOLOAD in upload_material.php: ' . $autoload);
        json_fail(500, 'SERVER_ERROR');
    }

    require_once $root . '/config/db.php';
    require_once $root . '/api/helpers/instructor_helper.php';
    if (!isset($pdo)) json_fail(500, 'SERVER_ERROR');

    // --- auth ---
    $auth = getAuthHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        json_fail(401, 'กรุณาเข้าสู่ระบบ');
    }
    $token = $m[1];
    if (!function_exists('getInstructorFromToken')) json_fail(500, 'SERVER_ERROR');
    $ins = getInstructorFromToken($token);
    if (!$ins || empty($ins['instructor_id'])) json_fail(401, 'Token ไม่ถูกต้อง');
    $instructor_id = (string)$ins['instructor_id'];

    // --- validate inputs ---
    $title = isset($_POST['title']) ? trim((string)$_POST['title']) : '';
    $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
    if ($title === '') json_fail(400, 'กรุณากรอกชื่อเรื่อง');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        json_fail(400, 'กรุณาเลือกไฟล์ PDF');
    }

    $file = $_FILES['file'];
    $tmpPath = $file['tmp_name'];
    $origName = $file['name'];
    $size = (int)$file['size'];

    // ตรวจชนิดไฟล์จริง
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmpPath) ?: '';
    if ($mime !== 'application/pdf') {
        json_fail(400, 'อนุญาตเฉพาะไฟล์ PDF เท่านั้น');
    }

    // ตรวจขนาดไม่เกิน php.ini (อ้างอิง upload_max_filesize/post_max_size แล้ว)
    if ($size <= 0) json_fail(400, 'ไฟล์ไม่ถูกต้อง');

    // --- เตรียมโฟลเดอร์เก็บไฟล์ ---
    $relDir = 'uploads/materials';
    $absDir = $root . '/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0775, true)) {
        error_log('mkdir failed: ' . $absDir);
        json_fail(500, 'ไม่สามารถเตรียมโฟลเดอร์อัปโหลดได้');
    }

    // ชื่อไฟล์ให้ unique ปลอดภัย

    $allowed = ['pdf', 'ppt', 'pptx', 'doc', 'docx'];

    // หานามสกุลจริงจากไฟล์
    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'ชนิดไฟล์ไม่รองรับ (อนุญาตเฉพาะ PDF, PPT, PPTX, DOC, DOCX)'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $safeBase = pathinfo($origName, PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^A-Za-z0-9_\-ก-ฮะ-๙]+/u', '_', $safeBase);
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . ($safeBase ? "_$safeBase" : '') . $ext;

    $absPath = $absDir . '/' . $filename;
    $relPath = $filename; // ใน DB เก็บชื่อไฟล์ แล้วหน้าเว็บ prefix ด้วย uploads/materials/

    if (!move_uploaded_file($tmpPath, $absPath)) {
        error_log('move_uploaded_file failed to ' . $absPath);
        json_fail(500, 'อัปโหลดไฟล์ไม่สำเร็จ');
    }

    // เผื่อ Windows XAMPP ตั้งสิทธิ์ไม่ได้ ก็ข้าม; ถ้าเป็น *nix ควร chmod 0644
    @chmod($absPath, 0644);

    // --- insert DB ---
    $sql = "INSERT INTO teaching_materials
            (title, description, file_path, file_size, file_type, instructor_id)
            VALUES (:title, :description, :file_path, :file_size, :file_type, :instructor_id)";
    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        ':title'         => $title,
        ':description'   => $description,
        ':file_path'     => $relPath,
        ':file_size'     => $size,
        ':file_type'     => 'application/pdf',
        ':instructor_id' => $instructor_id
    ]);
    if (!$ok) {
        // rollback: ลบไฟล์ที่เพิ่งอัปโหลด
        @unlink($absPath);
        $ei = $stmt->errorInfo();
        error_log('DB insert failed: ' . ($ei[2] ?? 'unknown'));
        json_fail(500, 'บันทึกข้อมูลไม่สำเร็จ');
    }

    echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Upload material error: ' . $e->getMessage());
    json_fail(500, 'SERVER_ERROR');
}
