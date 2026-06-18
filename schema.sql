-- Bab ul Ilm Academy Database Schema
-- Run this file once to set up your database.
-- Command: mysql -u root -p < schema.sql

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

-- ── Demo Users ────────────────────────────────────────────────────────────
-- Default password for all demo users: Admin@123
INSERT INTO users (name, email, password, role, country, qualification) VALUES
('Admin',          'admin@babulilm.com',   '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin',   'Pakistan', NULL),
('Sheikh Ibrahim', 'sheikh@babulilm.com',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'Pakistan', 'MA Islamic Studies, IIU Islamabad. Hafiz-ul-Quran. 10 years teaching experience.'),
('Ustadha Zainab', 'zainab@babulilm.com',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'Pakistan', 'Qari and Arabic teacher, Jamia Karachi. Specialises in Tajweed for beginners.'),
('Student Ahmad',  'ahmad@example.com',    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'UK',       NULL);

-- ── Demo Courses ──────────────────────────────────────────────────────────
INSERT INTO courses (teacher_id, subject_id, title, description, level, language, price, is_published) VALUES
(2, 1, 'Tajweed for Beginners — Learn to Read Quran Correctly',
 'A complete beginner course on the rules of Tajweed. You will learn the Makharij (articulation points), Sifaat, Madd rules, and how to recite the Quran with proper pronunciation. No prior knowledge needed.',
 'beginner', 'English', 0.00, 1),
(2, 2, 'Forty Hadith of Imam Nawawi — Explained',
 'Study the famous 40 Hadith collection with detailed explanation of each narration, its chain, meaning, and practical lessons for daily life.',
 'intermediate', 'English', 500.00, 1),
(3, 4, 'Arabic for Absolute Beginners',
 'Learn to read, write, and speak basic Arabic from scratch. This course covers the Arabic alphabet, basic vocabulary, and simple sentence structure.',
 'beginner', 'Urdu/English', 0.00, 1),
(3, 1, 'Advanced Tajweed — Ahkaam ul Tajweed',
 'Deep dive into the advanced rules of Tajweed for students who already know basics. Covers Waqf, Ibtida, Ghunna levels, and special rules.',
 'advanced', 'Urdu', 800.00, 1);

-- ── Demo Lessons ──────────────────────────────────────────────────────────
INSERT INTO lessons (course_id, title, content, sort_order) VALUES
(1, 'Welcome & Course Overview',      'In this lesson we introduce ourselves and explain the full course structure, what you will learn, and how to get the most from each session.', 1),
(1, 'The Arabic Alphabet — Full Review', 'We will review all 28 letters of the Arabic alphabet, their forms (initial, medial, final, isolated), and their names.', 2),
(1, 'Makharij — Points of Articulation', 'Learn where each Arabic letter is articulated from: throat, tongue, lips, nasal passage. Correct Makharij is the foundation of Tajweed.', 3),
(2, 'Introduction to the 40 Hadith',  'Who is Imam Nawawi? The history of this famous collection, and why these 40 Hadith are considered the foundation of Islam.', 1),
(2, 'Hadith 1 — Actions by Intentions', 'Full text, chain of narration, explanation of "innama al-a''maal bil-niyyaat" and its implications in worship and daily life.', 2),
(3, 'Arabic Alphabet — Letters 1-10', 'Learn to write and pronounce the first 10 letters: Alif, Ba, Ta, Tha, Jim, Ha, Kha, Dal, Dhal, Ra.', 1),
(3, 'Arabic Alphabet — Letters 11-28', 'Complete the alphabet. Practice writing each letter in all four forms.', 2);

-- ── Demo Enrollments ──────────────────────────────────────────────────────
INSERT INTO enrollments (student_id, course_id) VALUES
(4, 1),
(4, 3);
