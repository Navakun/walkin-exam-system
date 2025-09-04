<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';

try {
    // ตรวจสอบว่ามีตาราง examset หรือไม่
    $stmt = $pdo->query("SHOW TABLES LIKE 'examset'");
    if (!$stmt->fetch()) {
        // สร้างตาราง examset
        $sql = "CREATE TABLE IF NOT EXISTS examset (
            examset_id VARCHAR(10) PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $pdo->exec($sql);
        echo "Created examset table successfully\n";

        // เพิ่มข้อมูลตัวอย่างในตาราง examset
        $sql = "INSERT INTO examset (examset_id, title) VALUES 
            ('SET001', 'ชุดข้อสอบพื้นฐานการเขียนโปรแกรม 1'),
            ('SET002', 'ชุดข้อสอบพื้นฐานการเขียนโปรแกรม 2'),
            ('SET003', 'ชุดข้อสอบคณิตศาสตร์คอมพิวเตอร์ 1'),
            ('SET004', 'ชุดข้อสอบคณิตศาสตร์คอมพิวเตอร์ 2')";
        
        $pdo->exec($sql);
        echo "Added sample examsets successfully\n";
    }

    // ตรวจสอบว่ามีตาราง exam_set_question หรือไม่
    $stmt = $pdo->query("SHOW TABLES LIKE 'exam_set_question'");
    if (!$stmt->fetch()) {
        // สร้างตาราง exam_set_question
        $sql = "CREATE TABLE IF NOT EXISTS exam_set_question (
            id INT AUTO_INCREMENT PRIMARY KEY,
            examset_id VARCHAR(10),
            question_id VARCHAR(10),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (examset_id) REFERENCES examset(examset_id),
            FOREIGN KEY (question_id) REFERENCES question(question_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $pdo->exec($sql);
        echo "Created exam_set_question table successfully\n";
    }

    // อัพเดทโครงสร้างตาราง exam_slots
    $sql = "ALTER TABLE exam_slots 
            ADD COLUMN IF NOT EXISTS examset_id VARCHAR(10) AFTER id,
            ADD CONSTRAINT fk_exam_slots_examset 
            FOREIGN KEY (examset_id) REFERENCES examset(examset_id)";
    
    $pdo->exec($sql);
    echo "Updated exam_slots table successfully\n";

    // อัพเดทโครงสร้างตาราง exambooking
    $sql = "ALTER TABLE exambooking 
            ADD COLUMN IF NOT EXISTS examset_id VARCHAR(10) AFTER slot_id,
            ADD CONSTRAINT fk_exambooking_examset 
            FOREIGN KEY (examset_id) REFERENCES examset(examset_id)";
    
    $pdo->exec($sql);
    echo "Updated exambooking table successfully\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . __FILE__ . "\n";
    echo "Line: " . __LINE__ . "\n";
}
?>
