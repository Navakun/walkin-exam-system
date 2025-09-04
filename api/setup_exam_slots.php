<?php
require_once '../vendor/autoload.php';
require_once 'helpers/jwt_helper.php';
require_once '../config/db.php';

try {
    // อ่านไฟล์ SQL
    $sql = file_get_contents('sql/exam_slots.sql');
    
    // แยกคำสั่ง SQL เป็นส่วนๆ และประมวลผลทีละส่วน
    $queries = explode(';', $sql);
    
    foreach($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => 'สร้างตารางสำเร็จ'
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'เกิดข้อผิดพลาดในการสร้างตาราง: ' . $e->getMessage()
    ]);
}
?>
