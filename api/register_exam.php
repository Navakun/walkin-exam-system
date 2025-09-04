<?php
declare(strict_types=1);

/**
 * api/get_available_slots.php
 * ส่งรายการช่วงเวลาที่ลงทะเบียนสอบได้ (ของนิสิตที่ล็อกอิน) เป็น JSON ล้วน ๆ
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* ---------- helpers ---------- */
function json_fail(int $status, string $msg, array $extra = []): void {
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok(array $payload): void {
    echo json_encode(['status' => 'success'] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}
function getAuthHeader(): string {
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (isset($h['Authorization'])) return $h['Authorization'];
        if (isset($h['authorization'])) return $h['authorization'];
    }
    return $_SERVER['HTTP_AUTHORIZATION'] ?? '';
}

try {
    /* ---------- bootstrap ---------- */
    $root = dirname(__DIR__);

    // composer autoload (สำหรับ firebase/php-jwt)
    $autoload = $root . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('NO_AUTOLOAD: ' . $autoload);
        json_fail(500, 'SERVER_ERROR', ['error_code' => 'NO_AUTOLOAD']);
    }
    require_once $autoload;

    // DB
    $dbFile = $root . '/config/db.php';
    if (!is_file($dbFile)) {
        error_log('NO_DB: ' . $dbFile);
        json_fail(500, 'SERVER_ERROR', ['error_code' => 'NO_DB']);
    }
    require_once $dbFile;
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log('PDO_NOT_SET');
        json_fail(500, 'SERVER_ERROR', ['error_code' => 'PDO_NOT_SET']);
    }

    // JWT helper
    $jwtHelper = $root . '/api/helpers/jwt_helper.php';
    if (!is_file($jwtHelper)) {
        error_log('NO_JWT_HELPER: ' . $jwtHelper);
        json_fail(500, 'SERVER_ERROR', ['error_code' => 'NO_JWT_HELPER']);
    }
    require_once $jwtHelper;

    // ถ้ายังไม่มี verifyJwtToken() ให้สร้าง wrapper ที่เรียก decodeToken()
    if (!function_exists('verifyJwtToken')) {
        function verifyJwtToken(string $token, array $requiredClaims = []) {
            if (!function_exists('decodeToken')) {
                error_log('decodeToken() not found');
                return null;
            }
            $decoded = decodeToken($token);
            if (!$decoded) return null;
            foreach ($requiredClaims as $c) {
                if (!isset($decoded->$c)) return null;
            }
            return $decoded;
        }
    }

    /* ---------- auth ---------- */
    $auth = getAuthHeader();
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        json_fail(401, 'Unauthorized', ['error_code' => 'NO_BEARER']);
    }
    $token = $m[1];

    $decoded = verifyJwtToken($token, ['student_id']);
    if (!$decoded) {
        json_fail(401, 'Unauthorized', ['error_code' => 'BAD_TOKEN']);
    }
    $student_id = (string)$decoded->student_id;

    /* ---------- query slots ---------- */
    /**
     * สมมติ schema:
     *   exam_slots(slot_id PK, slot_date DATE, slot_start TIME, slot_end TIME, capacity INT, booked INT)
     * - แสดงเฉพาะวันที่ >= วันนี้
     * - คำนวณที่นั่งคงเหลือ remaining = capacity - booked
     * ปรับชื่อตาราง/คอลัมน์ตามฐานข้อมูลจริงของคุณได้เลย
     */
    $sql = "
        SELECT
            s.slot_id,
            s.slot_date,
            s.slot_start,
            s.slot_end,
            s.capacity,
            s.booked,
            (s.capacity - s.booked) AS remaining
        FROM exam_slots s
        WHERE s.slot_date >= CURDATE()
        ORDER BY s.slot_date ASC, s.slot_start ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // แปลงชนิดข้อมูลให้ชัดเจน
    $slots = array_map(static function(array $r): array {
        return [
            'slot_id'   => (int)$r['slot_id'],
            'slot_date' => (string)$r['slot_date'],
            'slot_start'=> (string)$r['slot_start'],
            'slot_end'  => (string)$r['slot_end'],
            'capacity'  => isset($r['capacity']) ? (int)$r['capacity'] : null,
            'booked'    => isset($r['booked']) ? (int)$r['booked'] : null,
            'remaining' => isset($r['remaining']) ? (int)$r['remaining'] : null,
        ];
    }, $rows);

    json_ok(['slots' => $slots]);

} catch (Throwable $e) {
    error_log('get_available_slots FATAL: ' . $e->getMessage());
    json_fail(500, 'SERVER_ERROR', ['error_code' => 'UNSPECIFIED']);
}
