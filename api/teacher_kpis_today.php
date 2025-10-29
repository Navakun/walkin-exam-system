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
    // ----- AUTH -----
    $token = getBearerToken();
    if (!$token) json_error('No token', 401);
    $payload = decodeToken($token);
    if (!$payload) json_error('Invalid token', 401);
    $role = strtolower(strval($payload->role ?? $payload->user_role ?? ''));
    $teacherId = $payload->teacher_id ?? $payload->instructor_id ?? null;
    if ($role !== 'teacher' && !$teacherId) json_error('Forbidden', 403);

    // ----- INPUT (optional tz) -----
    // หากต้องการส่ง timezone มา เช่น tz=Asia/Bangkok
    $tz = $_GET['tz'] ?? null;
    if ($tz) {
        try {
            $pdo->query("SET time_zone = " . $pdo->quote($tz));
        } catch (Throwable $e) { /* ignore */
        }
    }

    // ---------- KPI 1: นิสิตที่ลงทะเบียน "วันนี้" ----------
    // ใช้ตาราง exam_slot_registrations (ตรงกับ dump ล่าสุด)
    // ใส่ fallback ให้รองรับชื่อคอลัมน์/ตารางที่ต่างกันได้
    $todayRegistered = 0;
    $sqlToday = [
        // A) exam_slot_registrations.registered_at
        "SELECT COUNT(*) FROM exam_slot_registrations WHERE DATE(registered_at) = CURDATE()",
        // B) exam_slot_registrations.created_at
        "SELECT COUNT(*) FROM exam_slot_registrations WHERE DATE(created_at) = CURDATE()",
        // C) exambooking.created_at
        "SELECT COUNT(*) FROM exambooking WHERE DATE(created_at) = CURDATE()",
        // D) exambooking.booking_time
        "SELECT COUNT(*) FROM exambooking WHERE DATE(booking_time) = CURDATE()",
    ];
    foreach ($sqlToday as $q) {
        try {
            $todayRegistered = (int)$pdo->query($q)->fetchColumn();
            break;
        } catch (Throwable $e) { /* try next */
        }
    }

    // ---------- KPI 2: จำนวนข้อสอบทั้งหมด (ในคลัง) ----------
    // นับจากตาราง question (ถ้ามีคอลัมน์ active ก็กรอง active=1 หากไม่มีให้ไม่นับ)
    $totalQuestions = 0;
    try {
        // ลองมีคอลัมน์ active
        $totalQuestions = (int)$pdo->query("SELECT COUNT(*) FROM question WHERE COALESCE(active,1)=1")->fetchColumn();
    } catch (Throwable $e) {
        try {
            $totalQuestions = (int)$pdo->query("SELECT COUNT(*) FROM question")->fetchColumn();
        } catch (Throwable $e2) {
            $totalQuestions = 0;
        }
    }

    // ---------- KPI 3: จำนวน session ที่ "จบ" วันนี้ (optional) ----------
    // ใช้ exam_sessions ถ้ามี; พยายามเดาชื่อคอลัมน์ end_time/finished_at/status
    $completedToday = 0;
    $sqlCompleted = [
        "SELECT COUNT(*) FROM exam_sessions WHERE DATE(end_time)=CURDATE() AND COALESCE(status,'') IN ('completed','finished','done')",
        "SELECT COUNT(*) FROM exam_sessions WHERE DATE(end_time)=CURDATE()",
        "SELECT COUNT(*) FROM exam_sessions WHERE DATE(finished_at)=CURDATE()",
    ];
    foreach ($sqlCompleted as $q) {
        try {
            $completedToday = (int)$pdo->query($q)->fetchColumn();
            break;
        } catch (Throwable $e) { /* try next */
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            // << ใช้ตัวนี้ไปโชว์ที่ KPI "จำนวนนิสิต" บน dashboard ตามที่ขอ
            'registered_today' => $todayRegistered,

            // เพิ่ม KPI ประกอบอื่น ๆ
            'questions_total'  => $totalQuestions,
            'completed_today'  => $completedToday,
        ]
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    json_error($e->getMessage(), 500);
}
