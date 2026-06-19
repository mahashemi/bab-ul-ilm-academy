-- Bab ul Ilm Academy Database Schema (Production)
-- Run this file once to set up your database.
-- Command: mysql --default-character-set=utf8mb4 -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS bab_ul_ilm
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE bab_ul_ilm;

-- ── Users (Teachers & Students) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(150) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('student','teacher','admin') DEFAULT 'student',
    phone        VARCHAR(30),
    country      VARCHAR(100),
    bio          TEXT,
    qualification TEXT,              -- Teacher: credentials, degrees
    avatar       VARCHAR(300),
    is_approved  TINYINT(1) DEFAULT 1,  -- Admin can suspend
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB;

-- ── Course Categories ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subjects (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(10)
) ENGINE=InnoDB;

INSERT INTO subjects (name, icon) VALUES
('Quran & Tajweed',               '📖'),
('Hadith & Sunnah',               '☪️'),
('Islamic Jurisprudence (Fiqh)',   '⚖️'),
('Arabic Language',               '🌙'),
('Islamic History',               '🏛️'),
('Aqeedah (Belief)',              '✨'),
('Akhlaq & Spirituality',         '🌿'),
('Children''s Education',         '🧒');

-- ── Courses ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id   INT UNSIGNED NOT NULL,
    subject_id   INT UNSIGNED,
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    level        ENUM('beginner','intermediate','advanced') DEFAULT 'beginner',
    language     VARCHAR(50) DEFAULT 'English',
    price        DECIMAL(10,2) DEFAULT 0.00,   -- 0 = free
    cover_url    VARCHAR(500),
    is_published TINYINT(1) DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id)  REFERENCES subjects(id) ON DELETE SET NULL,
    INDEX idx_teacher (teacher_id),
    INDEX idx_published (is_published)
) ENGINE=InnoDB;

-- ── Lessons ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lessons (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    content     LONGTEXT,            -- Text/HTML content
    video_url   VARCHAR(500),        -- YouTube / Vimeo embed URL
    sort_order  SMALLINT UNSIGNED DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB;

-- ── Enrollments ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_enrollment (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Lesson Progress ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lesson_progress (
    student_id   INT UNSIGNED NOT NULL,
    lesson_id    INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, lesson_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Reviews ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS course_reviews (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id  INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    rating     TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment    TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_review (course_id, student_id),
    FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Initial Admin Account ───────────────────────────────────────────────
-- Default password: Admin@123
-- IMPORTANT: Log in immediately and change this password via your profile.
INSERT INTO users (name, email, password, role) VALUES
('Site Admin', 'admin@babulilmacademy.com',
 '$2y$10$Rn49XbRBi1VaO9H6AnkdfOhBEGhhe.D.4.HYAJaquZDWuHT7qXS2q', 'admin');
