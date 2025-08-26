-- เพิ่มข้อมูล student 2 คน
INSERT INTO student (student_id, name, email, password, registered_at) VALUES
('6410404182', 'สมคิด ไทยพัฒนา', 'somkit.t@example.com', '$2y$10$WO12FAE7HnErvmZ5p2OnxujjoKEv0DHqQugotQqY1dP5ZNJXGksbm', '2023-08-11 08:45:00'),
('6410404183', 'สมบูรณ์ ศิริพัฒนา', 'somboon.s@example.com', '$2y$10$WO12FAE7HnErvmZ5p2OnxujjoKEv0DHqQugotQqY1dP5ZNJXGksbm', '2023-08-11 09:30:00');

-- เพิ่มข้อมูลตาราง instructor
INSERT INTO instructor (instructor_id, name, email, password) VALUES
('A001', 'รศ.ดร.ครู สอนดี', 'kru.s@example.com', '$2y$10$WO12FAE7HnErvmZ5p2OnxujjoKEv0DHqQugotQqY1dP5ZNJXGksbm');

-- เพิ่มข้อมูลตาราง examset 2 ชุด
INSERT INTO examset (title, chapter, created_by, created_at) VALUES
('แบบทดสอบการเขียนโปรแกรม 1', 1, 'A001', '2023-08-11 10:00:00'),
('แบบทดสอบการเขียนโปรแกรม 2', 2, 'A001', '2023-08-11 10:30:00');

-- เก็บ ID ที่ถูกสร้างล่าสุด
SET @examset1_id = LAST_INSERT_ID();
SET @examset2_id = @examset1_id + 1;

-- เพิ่มข้อมูลตาราง exam_slots
INSERT INTO exam_slots (exam_id, slot_date, start_time, end_time, max_seats) VALUES
(@examset1_id, '2023-08-25', '09:00:00', '10:30:00', 30),
(@examset1_id, '2023-08-25', '13:00:00', '14:30:00', 30),
(@examset2_id, '2023-08-26', '09:00:00', '10:30:00', 30),
(@examset2_id, '2023-08-26', '13:00:00', '14:30:00', 30);
