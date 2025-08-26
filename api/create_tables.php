<?php
require_once '../config/db.php';

try {
    // สร้างตาราง exam_slots
    $sql = "CREATE TABLE IF NOT EXISTS exam_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id VARCHAR(10),
        slot_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        max_seats INT DEFAULT 30,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (exam_id) REFERENCES examset(examset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql);
    echo "Created exam_slots table successfully\n";

    // เพิ่มข้อมูลตัวอย่างใน exam_slots ถ้ามี examset อยู่แล้ว
    $stmt = $pdo->query("SELECT examset_id FROM examset LIMIT 1");
    $examset = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($examset) {
        $examset_id = $examset['examset_id'];
        echo "Found examset_id: " . $examset_id . "\n";
        
        $sql = "INSERT INTO exam_slots (exam_id, slot_date, start_time, end_time, max_seats) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$examset_id, '2025-08-25', '09:00:00', '10:30:00', 30]);
        $stmt->execute([$examset_id, '2025-08-25', '13:00:00', '14:30:00', 30]);
        echo "Added sample exam slots\n";
    } else {
        echo "No examset found in database\n";
    }

    // สร้างตาราง exambooking
    $sql = "CREATE TABLE IF NOT EXISTS exambooking (
        booking_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id VARCHAR(10) NOT NULL,
        slot_id INT,
        examset_id VARCHAR(10),
        scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('registered', 'completed', 'cancelled', 'absent') DEFAULT 'registered',
        FOREIGN KEY (slot_id) REFERENCES exam_slots(id),
        FOREIGN KEY (examset_id) REFERENCES examset(examset_id),
        FOREIGN KEY (student_id) REFERENCES student(student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql);
    echo "Created exambooking table successfully\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
