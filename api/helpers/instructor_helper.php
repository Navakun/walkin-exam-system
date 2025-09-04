<?php
require_once __DIR__ . '/jwt_helper.php';

/**
 * ดึงข้อมูลอาจารย์จาก JWT (รองรับกรณีมีคำว่า "Bearer " นำหน้า)
 * ควรเก็บฟิลด์อย่างน้อย instructor_id ใน payload ของ token
 *
 * @param string $token JWT token (อาจมี "Bearer " นำหน้า)
 * @return array|null ['instructor_id' => string, 'name' => ?string] หรือ null ถ้าไม่ผ่าน
 */
function getInstructorFromToken(string $token): ?array {
    try {
        // ตัดคำว่า "Bearer " ออกถ้ามี
        if (stripos($token, 'Bearer ') === 0) {
            $token = substr($token, 7);
        }

        $decoded = verifyJwtToken($token);  // ✅ ใช้อันนี้แทน decodeToken()

        if (!$decoded || !isset($decoded['instructor_id'])) {
            error_log('Invalid token structure: instructor_id not found');
            return null;
        }

        return [
            'instructor_id' => (string)$decoded['instructor_id'],
            'name'          => $decoded['name'] ?? null,
        ];
    } catch (Throwable $e) {
        error_log('Error in getInstructorFromToken: ' . $e->getMessage());
        return null;
    }
}
/**
 * ตรวจสอบว่าอาจารย์มีสิทธิ์เข้าถึงคอร์สนี้หรือไม่
 *
 * @param PDO $pdo การเชื่อมต่อฐานข้อมูล
 * @param string $instructor_id รหัสอาจารย์
 * @param int $course_id รหัสคอร์ส
 * @return bool true ถ้ามีสิทธิ์, false ถ้าไม่มีสิทธิ์หรือเกิดข้อผิดพลาด
 */