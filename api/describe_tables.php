<?php
require_once '../config/db.php';

try {
    // แสดงรายชื่อตารางทั้งหมด
    $stmt = $pdo->query("SHOW TABLES");
    echo "ตารางในฐานข้อมูล:\n";
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "- " . $row[0] . "\n";
    }

    // ตรวจสอบข้อมูลในตาราง examset
    $stmt = $pdo->query("SELECT * FROM examset");
    echo "\nข้อมูลในตาราง examset:\n";
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

    // ตรวจสอบข้อมูลในตาราง exam_slots
    $stmt = $pdo->query("SELECT * FROM exam_slots");
    echo "\nข้อมูลในตาราง exam_slots:\n";
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

    // ตรวจสอบข้อมูลในตาราง student
    $stmt = $pdo->query("SELECT student_id, name, email, registered_at FROM student");
    echo "\nข้อมูลในตาราง student:\n";
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
        echo "\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
