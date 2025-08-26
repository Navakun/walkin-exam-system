-- ปิดการตรวจสอบ Foreign Key ชั่วคราว
SET FOREIGN_KEY_CHECKS = 0;

-- ลบตารางตามลำดับ (ตารางที่มี foreign key ต้องลบก่อน)
DROP TABLE IF EXISTS exambooking;
DROP TABLE IF EXISTS exam_slots;
DROP TABLE IF EXISTS examset;
DROP TABLE IF EXISTS answer;
DROP TABLE IF EXISTS choice;
DROP TABLE IF EXISTS exam_set_question;
DROP TABLE IF EXISTS examsession;
DROP TABLE IF EXISTS question;
DROP TABLE IF EXISTS student;
DROP TABLE IF EXISTS instructor;

-- เปิดการตรวจสอบ Foreign Key
SET FOREIGN_KEY_CHECKS = 1;

-- สร้างตาราง student
CREATE TABLE student (
    student_id VARCHAR(15) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- สร้างตาราง instructor
CREATE TABLE instructor (
    instructor_id VARCHAR(15) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- สร้างตาราง examset
CREATE TABLE examset (
    examset_id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    chapter INT,
    description TEXT,
    created_by VARCHAR(15),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES instructor(instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- สร้างตาราง exam_slots
CREATE TABLE exam_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT,
    slot_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    max_seats INT DEFAULT 30,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES examset(examset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- สร้างตาราง exambooking
CREATE TABLE exambooking (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id VARCHAR(15) NOT NULL,
    slot_id INT,
    examset_id INT,
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('registered', 'completed', 'cancelled', 'absent') DEFAULT 'registered',
    FOREIGN KEY (slot_id) REFERENCES exam_slots(id),
    FOREIGN KEY (examset_id) REFERENCES examset(examset_id),
    FOREIGN KEY (student_id) REFERENCES student(student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
