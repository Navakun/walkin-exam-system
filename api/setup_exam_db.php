<?php
require_once '../config/db.php';

try {
    // อ่านและรันไฟล์ SQL สำหรับโครงสร้าง
    $sql = file_get_contents(__DIR__ . '/sql/exam_structure.sql');
    $pdo->exec($sql);
    echo "Created tables structure successfully\n";

    // อ่านและรันไฟล์ SQL สำหรับข้อมูลตัวอย่าง
    $sql = file_get_contents(__DIR__ . '/sql/exam_sample_data.sql');
    $pdo->exec($sql);
    echo "Inserted sample data successfully\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
