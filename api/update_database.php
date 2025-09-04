<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../config/db.php';

try {
    // อ่านและรันไฟล์ SQL สำหรับอัพเดทโครงสร้าง
    $sql = file_get_contents(__DIR__ . '/sql/update_structure.sql');
    
    // แยกคำสั่ง SQL และรันทีละคำสั่ง
    $commands = explode(';', $sql);
    foreach ($commands as $command) {
        $command = trim($command);
        if (!empty($command)) {
            $pdo->exec($command);
            echo "Executed: " . substr($command, 0, 50) . "...\n";
        }
    }

    echo "\nDatabase structure updated successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . __FILE__ . "\n";
    echo "Line: " . __LINE__ . "\n";
}
