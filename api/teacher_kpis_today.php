<?php
// api/teacher_kpis_today.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/verify_token.php';

function json_error($msg, $code = 400)
{
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $token = getBearerToken();
    if (!$token) json_error('No token', 401);
    $payload = decodeToken($token);
    if (!$payload) json_error('Invalid token', 401);
    $role = strtolower(strval($payload->role ?? $payload->user_role ?? ''));
    $teacherId = $payload->teacher_id ?? $payload->instructor_id ?? null;
    if ($role !== 'teacher' && !$teacherId) json_error('Forbidden', 403);

    // ถ้า timestamp เก็บเป็น UTC ให้ใช้กรอบเวลาวันนี้ของ Asia/Bangkok (+07:00) ในรูปแบบ UTC
    // สมมติ created_at เป็น UTC:
    $sqls = [
        // exambooking + created_at
        "SELECT COUNT(*) AS cnt
     FROM exambooking
     WHERE created_at >= CONVERT_TZ(CURRENT_DATE(), '+07:00', '+00:00')
       AND created_at <  CONVERT_TZ(CURRENT_DATE() + INTERVAL 1 DAY, '+07:00', '+00:00')",
        // exambooking + booking_time
        "SELECT COUNT(*) AS cnt
     FROM exambooking
     WHERE booking_time >= CONVERT_TZ(CURRENT_DATE(), '+07:00', '+00:00')
       AND booking_time <  CONVERT_TZ(CURRENT_DATE() + INTERVAL 1 DAY, '+07:00', '+00:00')",
        // exam_slot_registrations + created_at
        "SELECT COUNT(*) AS cnt
     FROM exam_slot_registrations
     WHERE created_at >= CONVERT_TZ(CURRENT_DATE(), '+07:00', '+00:00')
       AND created_at <  CONVERT_TZ(CURRENT_DATE() + INTERVAL 1 DAY, '+07:00', '+00:00')",
        // exam_slot_registrations + registered_at
        "SELECT COUNT(*) AS cnt
     FROM exam_slot_registrations
     WHERE registered_at >= CONVERT_TZ(CURRENT_DATE(), '+07:00', '+00:00')
       AND registered_at <  CONVERT_TZ(CURRENT_DATE() + INTERVAL 1 DAY, '+07:00', '+00:00')",
    ];

    $count = 0;
    $ok = false;
    foreach ($sqls as $q) {
        try {
            $stmt = $pdo->query($q);
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && isset($row['cnt'])) {
                    $count = intval($row['cnt']);
                    $ok = true;
                    break;
                }
            }
        } catch (Throwable $e) { /* ลองตัวถัดไป */
        }
    }

    if (!$ok) $count = 0;

    echo json_encode(['status' => 'success', 'data' => ['registered_today' => $count]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
