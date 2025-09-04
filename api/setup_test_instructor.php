<?php
require_once '../config/db.php';

try {
    // อ่านไฟล์ SQL
    $sql = file_get_contents('sql/test_instructor.sql');
    
    // Execute SQL
    $pdo->exec($sql);
    
    echo json_encode([
        'status' => 'success',
        'message' => 'เพิ่มข้อมูลอาจารย์ทดสอบสำเร็จ'
    ]);

} catch (PDOException $e) {
    // ถ้าเกิด error เพราะมีข้อมูลอยู่แล้ว ให้ถือว่าสำเร็จ
    if ($e->getCode() == 23000) { // Duplicate entry
        echo json_encode([
            'status' => 'success',
            'message' => 'มีข้อมูลอาจารย์ทดสอบอยู่แล้ว'
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()
        ]);
    }
}
?>
