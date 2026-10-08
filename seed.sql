USE teaching_work_diary;

-- Authoritative Teachers (Annexure-I)
INSERT INTO teachers(name, email, short_code, designation, active) VALUES 
('Dr. Manisha Deka', NULL, 'MD-IT', NULL, 1),
('Mr. Pinak Loshan Patowary', NULL, 'PLP-IT', NULL, 1),
('Mr. Masud Alam Rofi', NULL, 'MAR-IT', NULL, 1),
('Mr. Abhijit Choudhury', NULL, 'AC-IT', NULL, 1),
('Mr. Manjit Kumar Nath', NULL, 'MN-IT', NULL, 1),
('Mr. Prity Prakash Deva Sarma', NULL, 'PPDS-CSC', NULL, 1),
('Dr. Fakhar Uddin Ahmed', NULL, 'FUA-CSC', NULL, 1),
('Dr. Bijoy Komol Bhattacharyya', NULL, 'BKB-MAT', NULL, 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), email=VALUES(email), designation=VALUES(designation), active=VALUES(active);

-- Programmes
INSERT INTO programmes(name, short_code, active) VALUES 
('Bachelor of Computer Applications', 'BCA', 1),
('Bachelor of Science in Information Technology', 'B.Sc-IT', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), short_code=VALUES(short_code), active=VALUES(active);

-- Semesters
INSERT INTO semesters(name, active) VALUES 
('1st', 1),
('3rd', 1),
('5th', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), active=VALUES(active);

-- Rooms / Labs
INSERT INTO rooms(name, room_type, active) VALUES 
('C219', 'Classroom', 1),
('C220', 'Classroom', 1),
('C222', 'Classroom', 1),
('C223', 'Classroom', 1),
('C224', 'Classroom', 1),
('C225', 'Classroom', 1),
('LAB III', 'Lab', 1),
('C219/LAB III', 'Lab', 1)
ON DUPLICATE KEY UPDATE name=VALUES(name), room_type=VALUES(room_type), active=VALUES(active);

-- Default Settings
INSERT INTO settings(setting_key, setting_value) VALUES
('institution_name', 'LALIT CHANDRA BHARALI COLLEGE, MALIGAON, GUWAHATI – 781011'),
('institution_address', 'Maligaon, Guwahati – 781011, Assam'),
('department', 'Department of Information Technology'),
('report_title', 'Teaching & Work Diary'),
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('smtp_security', 'tls'),
('smtp_username', ''),
('smtp_password', ''),
('from_name', 'Teaching & Work Diary'),
('from_email', ''),
('reply_to', '')
ON DUPLICATE KEY UPDATE setting_value=IF(setting_value IS NULL OR setting_value='', VALUES(setting_value), setting_value);

-- Gauhati University Calendar Year 2026: Official general/local holidays.
-- Multi-day periods represented as individual calendar dates.
INSERT INTO holidays(holiday_date, title, holiday_type, active) VALUES
('2026-01-13', 'Magh Bihu', 'official', 1),
('2026-01-14', 'Magh Bihu', 'official', 1),
('2026-01-15', 'Magh Bihu', 'official', 1),
('2026-01-23', 'Saraswati Puja', 'official', 1),
('2026-01-26', 'Republic Day / G.U. Foundation Day', 'official', 1),
('2026-01-27', 'Gwther Bathau San', 'official', 1),
('2026-01-31', 'Me-Dam-Me-phi', 'official', 1),
('2026-02-01', 'Bir Chilarai Divas', 'official', 1),
('2026-02-15', 'Sivaratri', 'official', 1),
('2026-03-02', 'Khring Khring Baithow Puja', 'official', 1),
('2026-03-03', 'Dol Jatra', 'official', 1),
('2026-03-21', 'Id-Ul-Fitre', 'official', 1),
('2026-03-22', 'Id-Ul-Fitre', 'official', 1),
('2026-04-03', 'Good Friday', 'official', 1),
('2026-04-14', 'Bohag Bihu', 'official', 1),
('2026-04-15', 'Bohag Bihu', 'official', 1),
('2026-04-16', 'Bohag Bihu', 'official', 1),
('2026-05-01', 'May Day / Buddha Purnima', 'official', 1),
('2026-05-27', 'Id-Uz-Zuha', 'official', 1),
('2026-05-28', 'Id-Uz-Zuha', 'official', 1),
('2026-08-15', 'Independence Day', 'official', 1),
('2026-09-01', 'Tirubhav Tithi of Sri Sri Madhabdeva', 'official', 1),
('2026-09-04', 'Janmastomi', 'official', 1),
('2026-09-12', 'Tirubhav Tithi of Sri Sri Sankardev', 'official', 1),
('2026-09-21', 'Janmostav of Sri Sri Sankardev', 'official', 1),
('2026-10-02', 'Birthday of Mahatma Gandhi', 'official', 1),
('2026-10-17', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-18', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-19', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-20', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-21', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-22', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-23', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-24', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-10-25', 'Durga Puja, Kati Bihu, & Lakshmi Puja', 'official', 1),
('2026-11-08', 'Kali Puja & Dipawali', 'official', 1),
('2026-11-24', 'Guru Nanak’s Birthday & Lachit Divas', 'official', 1),
('2026-12-02', 'Asom Divas (Su-ka-Pha Divas)', 'official', 1),
('2026-12-25', 'Christmas Day', 'official', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title), holiday_type=VALUES(holiday_type), active=1;

-- Restricted holidays from the same 2026 Gauhati University list.
-- Kept separate so they do NOT automatically mark classes as holiday.
INSERT INTO holidays(holiday_date, title, holiday_type, active) VALUES
('2026-01-01', 'New Year’s Day', 'restricted', 1),
('2026-01-17', 'Silpi Divas', 'restricted', 1),
('2026-02-04', 'Shab-E-Barat', 'restricted', 1),
('2026-02-18', 'Ali Aye Ligang', 'restricted', 1),
('2026-03-31', 'Mahabir Jayanti', 'restricted', 1),
('2026-04-18', 'Tithi of Damodar Dev', 'restricted', 1),
('2026-04-21', 'Sati Sadhani Divas', 'restricted', 1),
('2026-04-22', 'Tithi of Gopal Dev', 'restricted', 1),
('2026-05-16', 'Tithi of Hari Dev', 'restricted', 1),
('2026-06-01', 'Janmotsab of Sri Sri Madhab Dev', 'restricted', 1),
('2026-06-17', 'Muharram', 'restricted', 1),
('2026-06-20', 'Death Anniversary of Bishnu Prasad Rabha', 'restricted', 1),
('2026-08-26', 'Fateha-E-Dwaz Daham', 'restricted', 1),
('2026-09-17', 'Biswakarma Puja', 'restricted', 1),
('2026-11-11', 'Bhatri Dwitiya', 'restricted', 1),
('2026-11-15', 'Chhath Puja', 'restricted', 1),
('2026-12-10', 'Martyr’s Day', 'restricted', 1),
('2026-12-24', 'Christmas Eve', 'restricted', 1)
ON DUPLICATE KEY UPDATE title=VALUES(title), holiday_type=VALUES(holiday_type), active=1;
