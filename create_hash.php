<?php
include('config/db.php'); 

// --- 1. เลือกรหัสผ่านต้นฉบับที่ต้องการใช้สำหรับนักเรียนทุกคน
$plainPassword = '123456';
$hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

echo "🔐 รหัสผ่านคือ: $plainPassword <br>";
echo "🔑 Hash ที่ได้คือ: $hashedPassword <br><br>";

// --- 2. ดึง student_id ทั้งหมด
$sql = "SELECT student_id FROM Student ORDER BY student_id ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "🎓 รายชื่อนิสิตที่ได้รับการอัปเดตรหัสผ่าน:<br>";
    while($row = $result->fetch_assoc()) {
        $student_id = $row['student_id'];

        // --- 3. อัปเดตรหัสผ่านเข้ารหัสกลับเข้าไปในตาราง
        $hashed = password_hash($plainPassword, PASSWORD_BCRYPT);
        $updateSql = "UPDATE Student SET password = '$hashed' WHERE student_id = '$student_id'";
        
        if ($conn->query($updateSql) === TRUE) {
            echo "✅ $student_id อัปเดตรหัสผ่านเรียบร้อย<br>";
        } else {
            echo "❌ เกิดข้อผิดพลาดกับ $student_id: " . $conn->error . "<br>";
        }
    }
} else {
    echo "ไม่พบนิสิตในระบบ";
}

// --- 4. อัปเดต Admin password (ถ้ามี)
$adminPassword = '123456';
$adminHash = password_hash($adminPassword, PASSWORD_BCRYPT);
echo "<br>👨‍🏫 รหัสผ่าน Admin: $adminPassword<br>";
echo "Hash: $adminHash<br>";
echo "<br>";

$sql = "SELECT student_id FROM Student ORDER BY student_id ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "🎓 รายชื่อนิสิต:<br>";
    while($row = $result->fetch_assoc()) {
        echo $row['student_id']."<br>";
        
    }
} else {
    echo "ไม่พบนิสิตในระบบ";
}
echo "<br>";

    echo "f4b8d0837bfc2596a8f03aa92a674c20c1fd7c262a0d50a74851e1a08ee79e6c"."<br>";
    echo "c3d7f0b4c9ab6e5da202fbb2e360123a0e8f7cf42f734e7f0862aa4e4d0c1ea1"."<br>";


echo "<br>";
$sql = "SELECT instructor_id FROM instructor ORDER BY instructor_id ASC";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "🎓 รายชื่ออาจารย์:<br>";
    while($row = $result->fetch_assoc()) {
        echo $row['instructor_id']."<br>";
        
    }
} else {
    echo "ไม่พบอาจารย์ในระบบ";
}
echo "<br>";

    echo "d57a9c8e90f6fcb62f0e05e01357ed9cfb50a3b1e121c84a3cdb3fae8a1c71ef"."<br>";


$conn->close();
?>
