CREATE DATABASE IF NOT EXISTS teaching_work_diary CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE teaching_work_diary;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    role ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
    teacher_id INT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NULL,
    short_code VARCHAR(30) UNIQUE,
    designation VARCHAR(100) NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS programmes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    short_code VARCHAR(40) NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS semesters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS papers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    programme_id INT NULL,
    semester_id INT NULL,
    name VARCHAR(180) NOT NULL,
    code VARCHAR(80) NULL,
    active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE SET NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS rooms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    room_type VARCHAR(40) NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS class_strength (
    id INT AUTO_INCREMENT PRIMARY KEY,
    programme_id INT NOT NULL,
    semester_id INT NOT NULL,
    strength INT NOT NULL DEFAULT 0,
    session_name VARCHAR(80) NOT NULL,
    active TINYINT(1) DEFAULT 1,
    UNIQUE KEY uq_strength(programme_id, semester_id, session_name),
    FOREIGN KEY (programme_id) REFERENCES programmes(id) ON DELETE CASCADE,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS timetable (
    id INT AUTO_INCREMENT PRIMARY KEY,
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    teacher_id INT NOT NULL,
    programme_id INT NOT NULL,
    semester_id INT NOT NULL,
    paper_id INT NOT NULL,
    room_id INT NULL,
    class_type VARCHAR(30) DEFAULT 'Theory',
    active TINYINT(1) DEFAULT 1,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id),
    FOREIGN KEY (programme_id) REFERENCES programmes(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
    FOREIGN KEY (paper_id) REFERENCES papers(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL UNIQUE,
    title VARCHAR(180) NOT NULL,
    holiday_type ENUM('official','restricted','local') DEFAULT 'official',
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS diary (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    diary_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    teacher_id INT NOT NULL,
    original_teacher_id INT NULL,
    programme_id INT NOT NULL,
    semester_id INT NOT NULL,
    paper_id INT NOT NULL,
    room_id INT NULL,
    class_type VARCHAR(30) DEFAULT 'Theory',
    topic VARCHAR(255) NULL,
    present INT NOT NULL DEFAULT 0,
    total INT NOT NULL DEFAULT 0,
    status VARCHAR(40) NOT NULL DEFAULT 'Conducted',
    remarks TEXT NULL,
    original_date DATE NULL,
    original_start_time TIME NULL,
    original_end_time TIME NULL,
    timetable_id INT NULL,
    materialized_from VARCHAR(30) DEFAULT 'timetable',
    is_edited TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_diary_template (timetable_id, diary_date),
    FOREIGN KEY (teacher_id) REFERENCES teachers(id),
    FOREIGN KEY (original_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL,
    FOREIGN KEY (programme_id) REFERENCES programmes(id),
    FOREIGN KEY (semester_id) REFERENCES semesters(id),
    FOREIGN KEY (paper_id) REFERENCES papers(id),
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    FOREIGN KEY (timetable_id) REFERENCES timetable(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    timetable_id INT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    action ENUM('cancel','substitute') NOT NULL,
    substitute_teacher_id INT NULL,
    reason VARCHAR(255) NULL,
    remarks TEXT NULL,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id),
    FOREIGN KEY (timetable_id) REFERENCES timetable(id) ON DELETE SET NULL,
    FOREIGN KEY (substitute_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS duties (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    duty_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    teacher_id INT NOT NULL,
    duty_type VARCHAR(80) NOT NULL,
    details TEXT NULL,
    remarks TEXT NULL,
    status VARCHAR(40) DEFAULT 'Planned',
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
);

CREATE TABLE IF NOT EXISTS additional_works (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    work_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    teacher_id INT NOT NULL,
    work_type VARCHAR(100) NOT NULL,
    details TEXT NULL,
    remarks TEXT NULL,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id)
);

CREATE TABLE IF NOT EXISTS duty_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    active TINYINT(1) DEFAULT 1
);

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL
);
