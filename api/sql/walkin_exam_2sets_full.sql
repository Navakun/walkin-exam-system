
-- Walk-in Exam Database (2 ชุด ชุดละ 50 ข้อ รวม 100 ข้อ)
DROP DATABASE IF EXISTS walkin_exam_db;
CREATE DATABASE walkin_exam_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE walkin_exam_db;

-- ตาราง Student
CREATE TABLE Student (
  student_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  fullname VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตาราง Instructor
CREATE TABLE Instructor (
  instructor_id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  fullname VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ตาราง ExamSet
CREATE TABLE ExamSet (
  examset_id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(100) NOT NULL,
  chapter VARCHAR(50),
  duration_seconds INT NOT NULL DEFAULT 5400,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES Instructor(instructor_id)
);

-- ตาราง Question
CREATE TABLE Question (
  question_id INT AUTO_INCREMENT PRIMARY KEY,
  question_text TEXT NOT NULL,
  correct_choice CHAR(1) NOT NULL,
  difficulty_level TINYINT NOT NULL DEFAULT 1
);

-- ตาราง Choice
CREATE TABLE Choice (
  choice_id INT AUTO_INCREMENT PRIMARY KEY,
  question_id INT NOT NULL,
  choice_label CHAR(1) NOT NULL,
  choice_text VARCHAR(255) NOT NULL,
  FOREIGN KEY (question_id) REFERENCES Question(question_id)
);

-- ตาราง Mapping ExamSet <-> Question
CREATE TABLE exam_set_question (
  examset_id INT NOT NULL,
  question_id INT NOT NULL,
  PRIMARY KEY (examset_id, question_id),
  FOREIGN KEY (examset_id) REFERENCES ExamSet(examset_id),
  FOREIGN KEY (question_id) REFERENCES Question(question_id)
);

-- ตาราง ExamSession
CREATE TABLE ExamSession (
  session_id INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  examset_id INT NOT NULL,
  start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
  end_time DATETIME,
  score DECIMAL(5,2),
  FOREIGN KEY (student_id) REFERENCES Student(student_id),
  FOREIGN KEY (examset_id) REFERENCES ExamSet(examset_id)
);

-- ตาราง Answer
CREATE TABLE Answer (
  answer_id INT AUTO_INCREMENT PRIMARY KEY,
  session_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_choice CHAR(1),
  answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  is_correct TINYINT(1) DEFAULT NULL,
  FOREIGN KEY (session_id) REFERENCES ExamSession(session_id),
  FOREIGN KEY (question_id) REFERENCES Question(question_id)
);

-- Trigger คำนวณความถูกต้องอัตโนมัติ
DELIMITER //
CREATE TRIGGER check_answer_before_insert
BEFORE INSERT ON Answer
FOR EACH ROW
BEGIN
  DECLARE correct CHAR(1);
  SELECT correct_choice INTO correct FROM Question WHERE question_id = NEW.question_id;
  SET NEW.is_correct = (NEW.selected_choice = correct);
END;
//
CREATE TRIGGER check_answer_before_update
BEFORE UPDATE ON Answer
FOR EACH ROW
BEGIN
  DECLARE correct CHAR(1);
  SELECT correct_choice INTO correct FROM Question WHERE question_id = NEW.question_id;
  SET NEW.is_correct = (NEW.selected_choice = correct);
END;
//
DELIMITER ;

-- Seed Instructor & Students
INSERT INTO Instructor (username, password_hash, fullname)
VALUES ('teacher1', '$2y$10$Y8QO1fMbHqkIoWjQYJ0OuOpj8W7QXbYgGdJBoOAJ6wHoFHVQAG6vW', 'ผศ.ดร. มานะ เก่งมาก');

INSERT INTO Student (student_code, fullname, username, password_hash)
VALUES 
('66010001', 'สมชาย ใจดี', '66010001', '$2y$10$Qj2U3A6Fh8G9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6A7B8C9D0E1'),
('66010002', 'สมหญิง มีสุข', '66010002', '$2y$10$Qj2U3A6Fh8G9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6A7B8C9D0E1');

-- ExamSet 2 ชุด
INSERT INTO ExamSet (title, chapter, duration_seconds, is_active, created_by)
VALUES ('บทที่ 1: ความรู้เบื้องต้น', 'บทที่ 1', 1800, 1, 1),
       ('บทที่ 2: พ.ร.บ. คอมพิวเตอร์', 'บทที่ 2', 2700, 1, 1);

-- >>> ตัวอย่างคำถาม/ตัวเลือก 100 ข้อ (1-100) <<<
-- หมายเหตุ: ตรงนี้จะต้องนำข้อมูลจากไฟล์ 1-100_reindexed.sql ที่คุณมีอยู่แล้วมาใส่
-- เพื่อให้ครบทั้ง Questions + Choices + Mapping exam_set_question
-- โครงสร้างจะเป็น INSERT INTO Question / Choice / exam_set_question แบบต่อเนื่อง
-- ชุดที่ 1 ครอบคลุมข้อ 1-50, ชุดที่ 2 ครอบคลุมข้อ 51-100



-- ข้อสอบ 1-100 (จากไฟล์ 1-100_reindexed.sql)
USE walkin_exam_db;

-- ล้างข้อมูลเก่าทั้งหมด
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE Answer;
TRUNCATE TABLE ExamSession;
TRUNCATE TABLE exam_set_question;
TRUNCATE TABLE Choice;
TRUNCATE TABLE Question;
TRUNCATE TABLE ExamSet;
TRUNCATE TABLE Student;
TRUNCATE TABLE Instructor;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. ข้อมูลนักศึกษา (Student)
INSERT INTO Student (student_id, name, email, password) VALUES
('66010001', 'สมชาย ใจดี', 'somchai.j@example.com', '$2y$10$Qj2U3A6Fh8G9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6A7B8C9D0E1'),
('66010002', 'สมหญิง มีสุข', 'somying.m@example.com', '$2y$10$Qj2U3A6Fh8G9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6A7B8C9D0E1');

-- 2. ข้อมูลอาจารย์ (Instructor)
INSERT INTO Instructor (instructor_id, name, email, password) VALUES
('I001', 'ผศ.ดร. มานะ เก่งมาก', 'mana.k@example.com', '$2y$10$A1B2C3D4E5F6G7H8I9J0K1L2M3N4O5P6Q7R8S9T0U1V2W3X4Y5Z6A7B8');

-- 3. ข้อมูลชุดข้อสอบ (ExamSet)
INSERT INTO ExamSet (title, chapter, created_by) VALUES
('ชุดข้อสอบกฎหมายเทคโนโลยีสารสนเทศ บทที่ 1', 1, 'I001'),
('ชุดข้อสอบกฎหมายเทคโนโลยีสารสนเทศ บทที่ 2', 2, 'I001');

-- 4. ข้อมูลคำถามและตัวเลือก

-- -------- ชุดที่ 1 (คำถาม 1-50) --------
INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('กฎหมายเทคโนโลยีสารสนเทศคืออะไร?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'กฎหมายเกี่ยวกับสิ่งแวดล้อม'),(@question_id, 'B', 'กฎหมายเกี่ยวกับการใช้เทคโนโลยีสารสนเทศและคอมพิวเตอร์'),(@question_id, 'C', 'กฎหมายเกี่ยวกับการศึกษา'),(@question_id, 'D', 'กฎหมายเกี่ยวกับการขนส่ง');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นข้อมูลส่วนบุคคล?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ชื่อและนามสกุล'),(@question_id, 'B', 'ตารางราคาอาหาร'),(@question_id, 'C', 'สภาพอากาศ'),(@question_id, 'D', 'ข้อมูลสาธารณะ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('กฎหมายคุ้มครองข้อมูลส่วนบุคคลของไทยคือพระราชบัญญัติอะไร?', 'C', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'พ.ร.บ.แรงงาน'),(@question_id, 'B', 'พ.ร.บ.คอมพิวเตอร์'),(@question_id, 'C', 'พ.ร.บ.ข้อมูลส่วนบุคคล'),(@question_id, 'D', 'พ.ร.บ.ลิขสิทธิ์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ซอฟต์แวร์เถื่อนผิดกฎหมายหรือไม่?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นอยู่กับกรณี'),(@question_id, 'D', 'ถูกถ้าไม่ทำกำไร');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครมีหน้าที่เก็บรักษาข้อมูลส่วนบุคคล?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'ลูกค้า'),(@question_id, 'D', 'รัฐบาลเท่านั้น');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('Cybercrime หมายถึงอะไร?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การใช้เทคโนโลยีเพื่อการศึกษา'),(@question_id, 'B', 'การกระทำผิดทางอาชญากรรมที่เกี่ยวกับคอมพิวเตอร์'),(@question_id, 'C', 'การซื้อขายสินค้าออนไลน์'),(@question_id, 'D', 'การพัฒนาเว็บไซต์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การปลอมแปลงข้อมูลคอมพิวเตอร์คืออะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เปลี่ยนแปลงข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'B', 'พัฒนาโปรแกรมใหม่'),(@question_id, 'C', 'สำรองข้อมูล'),(@question_id, 'D', 'อัปเดตซอฟต์แวร์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('กฎหมายที่เกี่ยวกับการป้องกันโจรกรรมข้อมูลคือ?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'พ.ร.บ.คอมพิวเตอร์'),(@question_id, 'B', 'พ.ร.บ.แรงงาน'),(@question_id, 'C', 'พ.ร.บ.ภาษี'),(@question_id, 'D', 'พ.ร.บ.ลิขสิทธิ์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเข้าถึงข้อมูลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ไม่ผิด'),(@question_id, 'B', 'ผิด'),(@question_id, 'C', 'ขึ้นอยู่กับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าไม่เผยแพร่');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การส่งสแปมผิดกฎหมายหรือไม่?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ถูก'),(@question_id, 'B', 'ผิดในบางประเทศ'),(@question_id, 'C', 'ไม่ผิด'),(@question_id, 'D', 'ไม่มีผลทางกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดไม่ใช่การละเมิดลิขสิทธิ์?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ใช้ภาพฟรีที่ได้รับอนุญาต'),(@question_id, 'B', 'ดาวน์โหลดเพลงผิดกฎหมาย'),(@question_id, 'C', 'แจกซอฟต์แวร์เถื่อน'),(@question_id, 'D', 'ทำซ้ำโปรแกรมโดยไม่ได้รับอนุญาต');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครตรวจสอบกฎหมายไซเบอร์ในไทย?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ตำรวจไซเบอร์'),(@question_id, 'B', 'กรมสรรพากร'),(@question_id, 'C', 'กระทรวงเกษตร'),(@question_id, 'D', 'สำนักงานแรงงาน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นตัวอย่างมัลแวร์?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โทรจัน'),(@question_id, 'B', 'โปรแกรมป้องกันไวรัส'),(@question_id, 'C', 'ระบบปฏิบัติการ'),(@question_id, 'D', 'โปรแกรมสแกนไวรัส');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดความเป็นส่วนตัวทางดิจิทัลคือ?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เปิดเผยข้อมูลส่วนตัวโดยไม่ได้รับอนุญาต'),(@question_id, 'B', 'ใช้ข้อมูลตามกฎหมาย'),(@question_id, 'C', 'ลบข้อมูลส่วนตัวของตัวเอง'),(@question_id, 'D', 'อัปเดตข้อมูลส่วนตัว');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ธุรกรรมอิเล็กทรอนิกส์ที่ถูกกฎหมายคือ?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ซื้อสินค้าผ่านเว็บไซต์มีใบอนุญาต'),(@question_id, 'B', 'ใช้บัตรเครดิตผู้อื่น'),(@question_id, 'C', 'โอนเงินโดยไม่มีหลักฐาน'),(@question_id, 'D', 'ซื้อสินค้าจากเว็บไซต์ปลอม');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('พระราชบัญญัติว่าด้วยการกระทำความผิดเกี่ยวกับคอมพิวเตอร์ มีวัตถุประสงค์อะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ป้องกันและลงโทษการกระทำผิดไซเบอร์'),(@question_id, 'B', 'ควบคุมการขายคอมพิวเตอร์'),(@question_id, 'C', 'คุ้มครองสิทธิแรงงาน'),(@question_id, 'D', 'จัดการภาษี');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อมูลส่วนบุคคลประเภทใดคุ้มครองเป็นพิเศษ?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ข้อมูลสุขภาพ'),(@question_id, 'B', 'ชื่อเล่น'),(@question_id, 'C', 'ข้อมูลที่เปิดเผยต่อสาธารณะ'),(@question_id, 'D', 'ข้อมูลสภาพอากาศ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บรวบรวมข้อมูลต้องทำอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ต้องได้รับความยินยอม'),(@question_id, 'B', 'เก็บโดยไม่จำกัด'),(@question_id, 'C', 'ส่งต่อข้อมูลได้ทุกกรณี'),(@question_id, 'D', 'ไม่ต้องแจ้งเจ้าของข้อมูล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดกฎหมายลิขสิทธิ์มีบทลงโทษอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'จำคุกและปรับ'),(@question_id, 'B', 'ไม่มีบทลงโทษ'),(@question_id, 'C', 'ให้รางวัล'),(@question_id, 'D', 'ถูกละเว้น');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การปลอมแปลงเอกสารอิเล็กทรอนิกส์ผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าไม่เผยแพร่');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อมูลส่วนบุคคลใช้เพื่ออะไรได้บ้าง?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ใช้ได้ตามวัตถุประสงค์ที่ได้รับอนุญาต'),(@question_id, 'B', 'ใช้ได้ทุกกรณี'),(@question_id, 'C', 'ส่งต่อได้โดยไม่จำกัด'),(@question_id, 'D', 'ใช้ได้เฉพาะหน่วยงานรัฐ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การโจมตีแบบ DoS คืออะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การโจมตีให้ระบบล่ม'),(@question_id, 'B', 'การขโมยข้อมูล'),(@question_id, 'C', 'การพัฒนาโปรแกรม'),(@question_id, 'D', 'การส่งสแปม');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเผยแพร่ข้อมูลเท็จผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับบริบท'),(@question_id, 'D', 'ถูกกฎหมายถ้าเป็นความคิดเห็น');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นสิทธิของเจ้าของข้อมูลตาม PDPA?', 'D', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ขอเข้าถึงข้อมูล'),(@question_id, 'B', 'ขอแก้ไขข้อมูล'),(@question_id, 'C', 'ขอให้ลบข้อมูล'),(@question_id, 'D', 'ถูกทุกข้อ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ลายมือชื่ออิเล็กทรอนิกส์แทนลายมือชื่อกระดาษถูกกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ถูกต้อง'),(@question_id, 'B', 'ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ไม่มีผลทางกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ซอฟต์แวร์ผิดลิขสิทธิ์มีบทลงโทษอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'จำคุกและปรับ'),(@question_id, 'B', 'ไม่มีบทลงโทษ'),(@question_id, 'C', 'ให้รางวัล'),(@question_id, 'D', 'ถูกกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครควบคุมกฎหมายไซเบอร์ในไทย?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ตำรวจไซเบอร์'),(@question_id, 'B', 'กระทรวงเกษตร'),(@question_id, 'C', 'กรมสรรพากร'),(@question_id, 'D', 'สำนักงานแรงงาน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บข้อมูลสุขภาพต้องปฏิบัติอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ต้องได้รับความยินยอมและรักษาความปลอดภัยสูง'),(@question_id, 'B', 'เก็บได้โดยไม่ต้องแจ้ง'),(@question_id, 'C', 'ห้ามเก็บ'),(@question_id, 'D', 'ใช้ได้โดยทุกคน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำลายข้อมูลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าเจ้าของอนุญาต');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บข้อมูลส่วนบุคคลได้นานเท่าไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เท่าที่จำเป็นตามวัตถุประสงค์'),(@question_id, 'B', 'ไม่จำกัดเวลา'),(@question_id, 'C', 'ไม่เกิน 1 เดือน'),(@question_id, 'D', 'ต้องลบทันที');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดความเป็นส่วนตัวทางดิจิทัลหมายถึง?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เปิดเผยข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'B', 'การใช้ข้อมูลตามกฎหมาย'),(@question_id, 'C', 'ลบข้อมูลส่วนตัว'),(@question_id, 'D', 'ปรับปรุงข้อมูล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การลงนามอิเล็กทรอนิกส์ต้องมีอะไร?', 'D', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความสัมพันธ์กับผู้ลงนาม'),(@question_id, 'B', 'ตรวจสอบความถูกต้องได้'),(@question_id, 'C', 'ป้องกันการปลอมแปลง'),(@question_id, 'D', 'ถูกทุกข้อ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การฟิชชิ่ง (Phishing) คืออะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การหลอกขโมยข้อมูลส่วนตัวทางอินเทอร์เน็ต'),(@question_id, 'B', 'การโจมตีระบบ'),(@question_id, 'C', 'การสำรองข้อมูล'),(@question_id, 'D', 'การอัปเดตซอฟต์แวร์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดลิขสิทธิ์ซอฟต์แวร์ส่งผลอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โดนปรับและจำคุก'),(@question_id, 'B', 'ไม่มีผล'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกต้องตามกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำธุรกรรมอิเล็กทรอนิกส์ต้องใช้ลายมือชื่อแบบใด?', 'B', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ลายมือชื่อกระดาษเท่านั้น'),(@question_id, 'B', 'ลายมือชื่ออิเล็กทรอนิกส์ได้'),(@question_id, 'C', 'ไม่มีลายมือชื่อ'),(@question_id, 'D', 'ใช้โทรเลขเท่านั้น');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเผยแพร่ข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าเป็นความเห็นส่วนตัว');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ข้อมูลส่วนบุคคลเพื่อการตลาดโดยไม่ได้รับอนุญาตเป็นความผิดหรือไม่?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิดตาม PDPA'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับองค์กร'),(@question_id, 'D', 'ไม่มีข้อกำหนด');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำลายข้อมูลอิเล็กทรอนิกส์โดยเจตนาเข้าข่ายความผิดฐานใด?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การปลอมแปลงข้อมูล'),(@question_id, 'B', 'การทำลายข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'C', 'การละเมิดลิขสิทธิ์'),(@question_id, 'D', 'การละเมิดสัญญา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('บทลงโทษสำหรับผู้กระทำความผิดตาม พ.ร.บ.คอมพิวเตอร์มีอะไรบ้าง?', 'C', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ปรับเงิน'),(@question_id, 'B', 'จำคุก'),(@question_id, 'C', 'ทั้งปรับและจำคุก'),(@question_id, 'D', 'ไม่มีบทลงโทษ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การฟ้องร้องคดีข้อมูลส่วนบุคคลต้องดำเนินการภายในกี่ปี?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', '1 ปี'),(@question_id, 'B', '3 ปี'),(@question_id, 'C', '5 ปี'),(@question_id, 'D', '10 ปี');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('หลักการสำคัญของ PDPA คืออะไร?', 'D', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความโปร่งใส'),(@question_id, 'B', 'จำกัดการเก็บข้อมูล'),(@question_id, 'C', 'รักษาความปลอดภัยข้อมูล'),(@question_id, 'D', 'ถูกทุกข้อ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('องค์ประกอบใดสำคัญของลายมือชื่ออิเล็กทรอนิกส์?', 'D', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความสัมพันธ์กับผู้ลงนาม'),(@question_id, 'B', 'ตรวจสอบความถูกต้องได้'),(@question_id, 'C', 'ป้องกันการปลอมแปลง'),(@question_id, 'D', 'ถูกทุกข้อ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเข้าถึงระบบโดยไม่ได้รับอนุญาตผิดกฎหมายใด?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'กฎหมายลิขสิทธิ์'),(@question_id, 'B', 'พ.ร.บ.คอมพิวเตอร์'),(@question_id, 'C', 'กฎหมายภาษี'),(@question_id, 'D', 'กฎหมายแรงงาน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การแก้ไขข้อมูลโดยไม่ได้รับอนุญาตเข้าข่ายความผิดฐานใด?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การปลอมแปลงข้อมูล'),(@question_id, 'B', 'การละเมิดลิขสิทธิ์'),(@question_id, 'C', 'การทำลายข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'D', 'การละเมิดสัญญา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การจัดเก็บข้อมูลส่วนบุคคลที่อ่อนไหวต้องมีมาตรการอย่างไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'มีมาตรการรักษาความปลอดภัยสูง'),(@question_id, 'B', 'ไม่ต้องมีมาตรการพิเศษ'),(@question_id, 'C', 'สามารถเผยแพร่ได้'),(@question_id, 'D', 'ไม่มีข้อกำหนด');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครเป็นผู้รับผิดชอบหลักในการคุ้มครองข้อมูลส่วนบุคคล?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'รัฐบาล'),(@question_id, 'D', 'บุคคลทั่วไป');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การขายข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าได้ผลประโยชน์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นการละเมิดความปลอดภัยไซเบอร์?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การโจมตีระบบด้วยมัลแวร์'),(@question_id, 'B', 'การอัปเดตซอฟต์แวร์'),(@question_id, 'C', 'การสำรองข้อมูล'),(@question_id, 'D', 'การลบข้อมูลตัวเอง');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำธุรกรรมทางอิเล็กทรอนิกส์ที่ถูกต้องต้องมีอะไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ลายมือชื่ออิเล็กทรอนิกส์ที่ถูกต้อง'),(@question_id, 'B', 'ใบเสร็จรับเงินกระดาษ'),(@question_id, 'C', 'การประชุมด้วยตัว'),(@question_id, 'D', 'ไม่ต้องมีเอกสาร');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดกฎหมายเทคโนโลยีสารสนเทศส่งผลอย่างไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โทษจำคุกและปรับ'),(@question_id, 'B', 'ไม่มีผล'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกกฎหมาย');

-- -------- ชุดที่ 2 (คำถาม 51-100) --------
INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อมูลส่วนบุคคลหมายถึงอะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ข้อมูลที่ระบุตัวบุคคลได้'),(@question_id, 'B', 'ข้อมูลทั่วไปของบริษัท'),(@question_id, 'C', 'ข้อมูลสภาพอากาศ'),(@question_id, 'D', 'ข้อมูลสินค้าในร้านค้า');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('พ.ร.บ.คอมพิวเตอร์มีวัตถุประสงค์เพื่ออะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ปกป้องข้อมูลในระบบคอมพิวเตอร์'),(@question_id, 'B', 'จัดการแรงงาน'),(@question_id, 'C', 'กำหนดภาษี'),(@question_id, 'D', 'ควบคุมการศึกษา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ซอฟต์แวร์ลิขสิทธิ์แท้ทำให้เกิดอะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ถูกกฎหมายและปลอดภัย'),(@question_id, 'B', 'ผิดกฎหมาย'),(@question_id, 'C', 'ทำให้เครื่องเสียหาย'),(@question_id, 'D', 'ไม่มีผล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครเป็นผู้ควบคุมข้อมูลส่วนบุคคล?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'ลูกค้า'),(@question_id, 'D', 'รัฐบาลเท่านั้น');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การส่งอีเมลขยะ (Spam) คืออะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การส่งข้อความโฆษณาที่ไม่พึงประสงค์'),(@question_id, 'B', 'การส่งข่าวสารสำคัญ'),(@question_id, 'C', 'การส่งข้อความส่วนตัว'),(@question_id, 'D', 'การส่งเอกสารราชการ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเข้าถึงระบบโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ถูก'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ไม่มีผล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('Cybercrime หมายถึงอะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'อาชญากรรมที่ใช้เทคโนโลยีสารสนเทศ'),(@question_id, 'B', 'การซื้อขายสินค้าออนไลน์'),(@question_id, 'C', 'การใช้โซเชียลมีเดีย'),(@question_id, 'D', 'การเรียนออนไลน์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดลิขสิทธิ์คืออะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ใช้ซอฟต์แวร์โดยไม่ได้รับอนุญาต'),(@question_id, 'B', 'ใช้ซอฟต์แวร์ฟรี'),(@question_id, 'C', 'ซื้อซอฟต์แวร์ลิขสิทธิ์แท้'),(@question_id, 'D', 'พัฒนาโปรแกรมเอง');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครรับผิดชอบในการรักษาข้อมูลส่วนบุคคล?', 'B', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'รัฐบาลเท่านั้น'),(@question_id, 'D', 'บุคคลทั่วไป');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ลายมือชื่ออิเล็กทรอนิกส์ถูกกฎหมายหรือไม่?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ถูกต้อง'),(@question_id, 'B', 'ผิด'),(@question_id, 'C', 'ไม่มีผล'),(@question_id, 'D', 'ขึ้นกับกรณี');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเปิดเผยข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ถูก'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ไม่มีผล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อมูลประเภทใดที่ถือว่าเป็นข้อมูลอ่อนไหว?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ข้อมูลสุขภาพ'),(@question_id, 'B', 'ข้อมูลสาธารณะ'),(@question_id, 'C', 'ข้อมูลสินค้า'),(@question_id, 'D', 'ข้อมูลทั่วไป');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บรวบรวมข้อมูลต้องได้รับอะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความยินยอมจากเจ้าของข้อมูล'),(@question_id, 'B', 'ไม่ต้องขออนุญาต'),(@question_id, 'C', 'ขออนุญาตเฉพาะบางกรณี'),(@question_id, 'D', 'ขึ้นอยู่กับผู้เก็บ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การโจมตีด้วยมัลแวร์คืออะไร?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การโจมตีที่ใช้ซอฟต์แวร์ประสงค์ร้าย'),(@question_id, 'B', 'การสำรองข้อมูล'),(@question_id, 'C', 'การอัปเดตซอฟต์แวร์'),(@question_id, 'D', 'การติดตั้งโปรแกรมใหม่');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเผยแพร่ข้อมูลเท็จบนโลกออนไลน์ผิดกฎหมายหรือไม่?', 'A', 1);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ถูก'),(@question_id, 'C', 'ขึ้นกับบริบท'),(@question_id, 'D', 'ไม่มีผล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การส่งข้อมูลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ถูก'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ไม่มีผล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การฟิชชิ่ง (Phishing) คืออะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'หลอกลวงเพื่อขโมยข้อมูลส่วนตัว'),(@question_id, 'B', 'การส่งข้อความโฆษณา'),(@question_id, 'C', 'การโจมตีระบบ'),(@question_id, 'D', 'การสำรองข้อมูล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นสิทธิของเจ้าของข้อมูลตาม PDPA?', 'D', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ขอเข้าถึงข้อมูล'),(@question_id, 'B', 'ขอแก้ไขข้อมูล'),(@question_id, 'C', 'ขอให้ลบข้อมูล'),(@question_id, 'D', 'ทุกข้อที่กล่าวมา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ซอฟต์แวร์ละเมิดลิขสิทธิ์มีบทลงโทษอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'จำคุกและปรับ'),(@question_id, 'B', 'ไม่มีผลทางกฎหมาย'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การโจมตีแบบ DoS คืออะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การโจมตีให้ระบบล่ม'),(@question_id, 'B', 'การขโมยข้อมูล'),(@question_id, 'C', 'การพัฒนาโปรแกรม'),(@question_id, 'D', 'การส่งสแปม');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดไม่ใช่ Cybercrime?', 'C', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ฟิชชิ่ง'),(@question_id, 'B', 'แฮกเกอร์'),(@question_id, 'C', 'การปลูกผักออนไลน์'),(@question_id, 'D', 'การปลอมแปลงเอกสาร');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ลายมือชื่ออิเล็กทรอนิกส์ต้องมีอะไรบ้าง?', 'D', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความสัมพันธ์กับผู้ลงนาม'),(@question_id, 'B', 'ตรวจสอบความถูกต้องได้'),(@question_id, 'C', 'ป้องกันการปลอมแปลง'),(@question_id, 'D', 'ทุกข้อที่กล่าวมา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเผยแพร่ข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตส่งผลอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ถูกฟ้องร้องได้'),(@question_id, 'B', 'ไม่มีผลทางกฎหมาย'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกกฎหมายถ้าไม่เผยแพร่');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บข้อมูลสุขภาพต้องทำอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ต้องได้รับความยินยอมและรักษาความปลอดภัยสูง'),(@question_id, 'B', 'เก็บโดยไม่ต้องแจ้ง'),(@question_id, 'C', 'ห้ามเก็บ'),(@question_id, 'D', 'ใช้ได้โดยทุกคน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นบทลงโทษของ พ.ร.บ.คอมพิวเตอร์?', 'C', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ปรับเงิน'),(@question_id, 'B', 'จำคุก'),(@question_id, 'C', 'ทั้งปรับและจำคุก'),(@question_id, 'D', 'ไม่มีบทลงโทษ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การฟ้องร้องคดีเกี่ยวกับข้อมูลส่วนบุคคลต้องทำภายในกี่ปี?', 'B', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', '1 ปี'),(@question_id, 'B', '3 ปี'),(@question_id, 'C', '5 ปี'),(@question_id, 'D', '10 ปี');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดความเป็นส่วนตัวทางดิจิทัลหมายถึง?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เปิดเผยข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'B', 'การใช้ข้อมูลตามกฎหมาย'),(@question_id, 'C', 'ลบข้อมูลส่วนตัว'),(@question_id, 'D', 'ปรับปรุงข้อมูล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำลายข้อมูลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าเจ้าของอนุญาต');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดไม่ใช่องค์ประกอบของลายมือชื่ออิเล็กทรอนิกส์?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ตรวจสอบความถูกต้องไม่ได้'),(@question_id, 'B', 'ความสัมพันธ์กับผู้ลงนาม'),(@question_id, 'C', 'ป้องกันการปลอมแปลง'),(@question_id, 'D', 'ความน่าเชื่อถือ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดลิขสิทธิ์ซอฟต์แวร์มีผลอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โดนลงโทษจำคุกและปรับ'),(@question_id, 'B', 'ไม่มีผล'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกต้องตามกฎหมาย');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเก็บข้อมูลส่วนบุคคลต้องเก็บได้นานเท่าไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เท่าที่จำเป็นตามวัตถุประสงค์'),(@question_id, 'B', 'ไม่จำกัดเวลา'),(@question_id, 'C', 'ไม่เกิน 1 เดือน'),(@question_id, 'D', 'ต้องลบทันที');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การใช้ข้อมูลส่วนบุคคลเพื่อการตลาดโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับองค์กร'),(@question_id, 'D', 'ไม่มีข้อกำหนด');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การแก้ไขข้อมูลโดยไม่ได้รับอนุญาตเข้าข่ายความผิดฐานใด?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การปลอมแปลงข้อมูล'),(@question_id, 'B', 'การละเมิดลิขสิทธิ์'),(@question_id, 'C', 'การทำลายข้อมูล'),(@question_id, 'D', 'การละเมิดสัญญา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครรับผิดชอบหลักในการคุ้มครองข้อมูลส่วนบุคคล?', 'B', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'รัฐบาล'),(@question_id, 'D', 'บุคคลทั่วไป');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นการละเมิดความปลอดภัยไซเบอร์?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โจมตีด้วยมัลแวร์'),(@question_id, 'B', 'อัปเดตซอฟต์แวร์'),(@question_id, 'C', 'สำรองข้อมูล'),(@question_id, 'D', 'ลบข้อมูลตัวเอง');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำธุรกรรมอิเล็กทรอนิกส์ที่ถูกต้องต้องมีอะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ลายมือชื่ออิเล็กทรอนิกส์ที่ถูกต้อง'),(@question_id, 'B', 'ใบเสร็จรับเงินกระดาษ'),(@question_id, 'C', 'การประชุมด้วยตัว'),(@question_id, 'D', 'ไม่ต้องมีเอกสาร');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อมูลส่วนบุคคลประเภทใดคุ้มครองเป็นพิเศษ?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ข้อมูลสุขภาพ'),(@question_id, 'B', 'ข้อมูลทั่วไป'),(@question_id, 'C', 'ข้อมูลสาธารณะ'),(@question_id, 'D', 'ข้อมูลสินค้า');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การขายข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าได้ผลประโยชน์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเผยแพร่ข้อมูลเท็จส่งผลอย่างไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิดกฎหมาย'),(@question_id, 'B', 'ถูกกฎหมาย'),(@question_id, 'C', 'ไม่มีผล'),(@question_id, 'D', 'ขึ้นกับบริบท');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การโจมตีระบบด้วยมัลแวร์เรียกว่าอะไร?', 'A', 2);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การละเมิดความปลอดภัยไซเบอร์'),(@question_id, 'B', 'การอัปเดตระบบ'),(@question_id, 'C', 'การสำรองข้อมูล'),(@question_id, 'D', 'การแก้ไขข้อมูล');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การฟ้องร้องคดีเกี่ยวกับข้อมูลส่วนบุคคลต้องดำเนินการภายในกี่ปี?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', '1 ปี'),(@question_id, 'B', '3 ปี'),(@question_id, 'C', '5 ปี'),(@question_id, 'D', '10 ปี');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('องค์ประกอบสำคัญของลายมือชื่ออิเล็กทรอนิกส์คืออะไร?', 'D', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ความสัมพันธ์กับผู้ลงนาม'),(@question_id, 'B', 'ตรวจสอบความถูกต้องได้'),(@question_id, 'C', 'ป้องกันการปลอมแปลง'),(@question_id, 'D', 'ถูกทุกข้อ');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การเข้าถึงระบบโดยไม่ได้รับอนุญาตผิดกฎหมายตามกฎหมายใด?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'กฎหมายลิขสิทธิ์'),(@question_id, 'B', 'พ.ร.บ.คอมพิวเตอร์'),(@question_id, 'C', 'กฎหมายภาษี'),(@question_id, 'D', 'กฎหมายแรงงาน');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำลายข้อมูลโดยเจตนาเข้าข่ายความผิดฐานใด?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'การปลอมแปลงข้อมูล'),(@question_id, 'B', 'การทำลายข้อมูลโดยไม่ได้รับอนุญาต'),(@question_id, 'C', 'การละเมิดลิขสิทธิ์'),(@question_id, 'D', 'การละเมิดสัญญา');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การจัดเก็บข้อมูลส่วนบุคคลที่อ่อนไหวต้องมีมาตรการอย่างไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'รักษาความปลอดภัยสูง'),(@question_id, 'B', 'ไม่มีมาตรการพิเศษ'),(@question_id, 'C', 'เผยแพร่ได้'),(@question_id, 'D', 'ไม่มีข้อกำหนด');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ใครเป็นผู้รับผิดชอบหลักในการคุ้มครองข้อมูลส่วนบุคคล?', 'B', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'เจ้าของข้อมูล'),(@question_id, 'B', 'ผู้ควบคุมข้อมูล'),(@question_id, 'C', 'รัฐบาล'),(@question_id, 'D', 'บุคคลทั่วไป');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การขายข้อมูลส่วนบุคคลโดยไม่ได้รับอนุญาตผิดกฎหมายหรือไม่?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ผิด'),(@question_id, 'B', 'ไม่ผิด'),(@question_id, 'C', 'ขึ้นกับกรณี'),(@question_id, 'D', 'ถูกกฎหมายถ้าได้ผลประโยชน์');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('ข้อใดเป็นการละเมิดความปลอดภัยไซเบอร์?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'โจมตีด้วยมัลแวร์'),(@question_id, 'B', 'อัปเดตซอฟต์แวร์'),(@question_id, 'C', 'สำรองข้อมูล'),(@question_id, 'D', 'ลบข้อมูลตัวเอง');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การทำธุรกรรมอิเล็กทรอนิกส์ที่ถูกต้องต้องมีอะไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'ลายมือชื่ออิเล็กทรอนิกส์ที่ถูกต้อง'),(@question_id, 'B', 'ใบเสร็จรับเงินกระดาษ'),(@question_id, 'C', 'การประชุมด้วยตัว'),(@question_id, 'D', 'ไม่ต้องมีเอกสาร');

INSERT INTO Question (question_text, correct_choice, difficulty_level) VALUES ('การละเมิดกฎหมายเทคโนโลยีสารสนเทศส่งผลอย่างไร?', 'A', 3);
SET @question_id = LAST_INSERT_ID();
INSERT INTO Choice (question_id, label, content) VALUES (@question_id, 'A', 'จำคุกและปรับ'),(@question_id, 'B', 'ไม่มีผล'),(@question_id, 'C', 'ได้รับรางวัล'),(@question_id, 'D', 'ถูกกฎหมาย');


-- 5. ข้อมูลเชื่อมโยงคำถามกับชุดข้อสอบ (exam_set_question)
INSERT INTO exam_set_question (examset_id, question_id)
SELECT 1, q.question_id FROM Question q WHERE q.question_id BETWEEN 1 AND 50;

INSERT INTO exam_set_question (examset_id, question_id)
SELECT 2, q.question_id FROM Question q WHERE q.question_id BETWEEN 51 AND 100; 

-- 1) เพิ่มคอลัมน์รหัสนิสิต (กำหนดรูปแบบเป็นตัวเลข 8 หลัก; ปรับ regex ตามรูปแบบที่ใช้จริงได้)
ALTER TABLE Student 
  ADD student_code VARCHAR(20) UNIQUE AFTER student_id;

ALTER TABLE Student
  ADD CONSTRAINT ck_student_code CHECK (student_code REGEXP '^[0-9]{8}$');
