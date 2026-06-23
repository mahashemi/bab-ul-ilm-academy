-- Bab ul Ilm Academy Database Schema (Production)
-- Run this file once to set up your database.
-- Command: mysql --default-character-set=utf8mb4 -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS bab_ul_ilm
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE bab_ul_ilm;

-- ── Site Settings (editable by admins at /admin.php) ───────────────────────
CREATE TABLE IF NOT EXISTS settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(50) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('SITE_NAME', 'Bab ul Ilm Academy'),
('SITE_TAGLINE', 'Teach and Learn Any Subject — All Levels, Anywhere, Everywhere'),
('SITE_AFFILIATION', 'Under Alia University of Holland');

-- ── Users (Teachers & Students) ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100) NOT NULL,
    email        VARCHAR(150) NOT NULL UNIQUE,
    password     VARCHAR(255) NOT NULL,
    role         ENUM('student','teacher','parent','institution','admin') DEFAULT 'student',
    gender       ENUM('male','female','unspecified') DEFAULT 'unspecified',
    date_of_birth DATE NULL,
    education_level VARCHAR(50) NULL,       -- Student/Parent: highest level completed or in progress
    preferred_language VARCHAR(50) NULL,
    organization_name VARCHAR(200) NULL,    -- Institution accounts
    phone        VARCHAR(30),
    phone_verified TINYINT(1) DEFAULT 0,
    country      VARCHAR(100),
    bio          TEXT,
    qualification TEXT,              -- Teacher: credentials, degrees
    headline     VARCHAR(150) NULL,  -- Teacher: short title shown under their name, e.g. "Developer and Lead Instructor"
    avatar       VARCHAR(300),
    is_approved  TINYINT(1) DEFAULT 1,  -- Admin can suspend
    is_verified  TINYINT(1) DEFAULT 0,
    verification_token   VARCHAR(64) NULL,
    verification_expires  DATETIME NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Fields of Study (top-level grouping for Subjects) ──────────────────────
CREATE TABLE IF NOT EXISTS fields_of_study (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(30)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- icon = a Lucide (https://lucide.dev) icon name, rendered via <i data-lucide="...">
INSERT INTO fields_of_study (name, icon) VALUES
('Islamic Studies',      'moon-star'),
('School Subjects',      'backpack'),
('Bachelor Streams',     'graduation-cap'),
('Exam Preparation',     'target'),
('Postgraduate / PhD',   'award');

-- ── Course Categories ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS subjects (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    field_of_study_id INT UNSIGNED NULL,
    name              VARCHAR(100) NOT NULL,
    icon              VARCHAR(30),
    FOREIGN KEY (field_of_study_id) REFERENCES fields_of_study(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO subjects (field_of_study_id, name, icon) VALUES
(1, 'Quran & Tajweed',               'book-open'),
(1, 'Hadith & Sunnah',               'scroll-text'),
(1, 'Islamic Jurisprudence (Fiqh)',   'scale'),
(1, 'Arabic Language',               'languages'),
(1, 'Islamic History',               'landmark'),
(1, 'Aqeedah (Belief)',              'sparkles'),
(1, 'Akhlaq & Spirituality',         'sprout'),
(1, 'Children''s Education',         'baby'),
-- School subjects (Grade 1–12)
(2, 'Mathematics',                   'calculator'),
(2, 'Science',                       'atom'),
(2, 'English Language & Literature', 'library'),
(2, 'Computer Science / ICT',        'laptop'),
(2, 'Social Studies (History & Geography)', 'globe'),
-- Bachelor-level streams
(3, 'Pre-Medical Studies',           'stethoscope'),
(3, 'Pre-Engineering Studies',       'cog'),
(3, 'Business & Commerce',          'briefcase'),
(3, 'Arts & Humanities',            'palette'),
-- Standardized exam preparation
(4, 'GCSE',                         'file-text'),
(4, 'GED',                          'graduation-cap'),
(4, 'SAT',                          'pencil');

-- ── Courses ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS courses (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id   INT UNSIGNED NOT NULL,
    subject_id   INT UNSIGNED,
    title        VARCHAR(200) NOT NULL,
    description  TEXT,
    learning_objectives TEXT,  -- "What you'll learn" — one bullet per line
    requirements TEXT,         -- one bullet per line
    level        ENUM('beginner','intermediate','advanced') DEFAULT 'beginner',
    language     VARCHAR(50) DEFAULT 'English',
    price        DECIMAL(10,2) DEFAULT 0.00,   -- 0 = free
    cover_url    VARCHAR(500),
    is_published TINYINT(1) DEFAULT 0,
    moderation_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by   INT UNSIGNED NULL,
    updated_at   TIMESTAMP NULL,
    FOREIGN KEY (teacher_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id)  REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by)  REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_teacher (teacher_id),
    INDEX idx_published (is_published),
    INDEX idx_moderation (moderation_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lessons ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lessons (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id       INT UNSIGNED NOT NULL,
    section_title   VARCHAR(150) DEFAULT NULL,  -- groups lessons into curriculum sections (Udemy-style)
    title           VARCHAR(200) NOT NULL,
    content         LONGTEXT,            -- Text/HTML content
    video_url       VARCHAR(500),        -- YouTube / Vimeo embed URL
    duration_minutes SMALLINT UNSIGNED DEFAULT 0,
    is_preview      TINYINT(1) DEFAULT 0, -- viewable without enrolling
    sort_order      SMALLINT UNSIGNED DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Enrollments ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS enrollments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_enrollment (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Lesson Progress ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lesson_progress (
    student_id   INT UNSIGNED NOT NULL,
    lesson_id    INT UNSIGNED NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, lesson_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (lesson_id)  REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Messages (Teacher ↔ Student) ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id   INT UNSIGNED NOT NULL,
    receiver_id INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NULL,
    body        TEXT NOT NULL,
    is_read     TINYINT(1) DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id)   REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id)   REFERENCES courses(id) ON DELETE SET NULL,
    INDEX idx_conversation (sender_id, receiver_id),
    INDEX idx_receiver (receiver_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Skills (reusable across all users — managed from Edit Profile) ────────
CREATE TABLE IF NOT EXISTS skills (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_skills (
    user_id  INT UNSIGNED NOT NULL,
    skill_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, skill_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Class Chat (group discussion per course — teacher + enrolled students) ──
CREATE TABLE IF NOT EXISTS class_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id  INT UNSIGNED NOT NULL,
    sender_id  INT UNSIGNED NOT NULL,
    body       TEXT NOT NULL,
    is_broadcast TINYINT(1) DEFAULT 0,   -- sent via "Message Class" rather than typed in the thread
    is_deleted TINYINT(1) DEFAULT 0,     -- soft-deleted by teacher/admin after a flag review
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Moderation flags (heuristic — rule-based, not a live ML/LLM call) ──────
CREATE TABLE IF NOT EXISTS message_flags (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL,
    flag_type   ENUM('spam','disrespect','suspicious_link') NOT NULL,
    reason      VARCHAR(255),
    status      ENUM('pending','warned','deleted','dismissed') DEFAULT 'pending',
    reviewed_by INT UNSIGNED NULL,
    reviewed_at TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (message_id)  REFERENCES class_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Internal behavior score (teacher-only, never shown to students) ───────
CREATE TABLE IF NOT EXISTS student_behavior_scores (
    student_id INT UNSIGNED NOT NULL,
    course_id  INT UNSIGNED NOT NULL,
    score      INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Feedback / Advice ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS feedback (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    name        VARCHAR(100) NOT NULL,
    email       VARCHAR(150) NOT NULL,
    message     TEXT NOT NULL,
    is_read     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Curriculum sections, lesson duration/preview, course learning objectives ──
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS section_title VARCHAR(150) DEFAULT NULL AFTER course_id;
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS duration_minutes SMALLINT UNSIGNED DEFAULT 0;
ALTER TABLE lessons ADD COLUMN IF NOT EXISTS is_preview TINYINT(1) DEFAULT 0;
ALTER TABLE courses ADD COLUMN IF NOT EXISTS learning_objectives TEXT AFTER description;
ALTER TABLE courses ADD COLUMN IF NOT EXISTS requirements TEXT AFTER learning_objectives;

-- ── Richer registration: account types, gender, profile details ──
ALTER TABLE users MODIFY role ENUM('student','teacher','parent','institution','admin') DEFAULT 'student';
ALTER TABLE users ADD COLUMN IF NOT EXISTS gender ENUM('male','female','unspecified') DEFAULT 'unspecified';
ALTER TABLE users ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS education_level VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS preferred_language VARCHAR(50) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS organization_name VARCHAR(200) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_verified TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN IF NOT EXISTS headline VARCHAR(150) NULL;

-- ── Personalization (occupation + learning fields, for course recommendations) ──
ALTER TABLE users ADD COLUMN IF NOT EXISTS occupation VARCHAR(150) DEFAULT NULL;

CREATE TABLE IF NOT EXISTS user_learning_fields (
    user_id INT UNSIGNED NOT NULL,
    field_of_study_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, field_of_study_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (field_of_study_id) REFERENCES fields_of_study(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Initial Admin Account ───────────────────────────────────────────────
-- Default password: Admin@123
-- IMPORTANT: Log in immediately and change this password via your profile.
INSERT INTO users (name, email, password, role, is_verified) VALUES
('Site Admin', 'admin@babulilmacademy.com',
 '$2y$10$Rn49XbRBi1VaO9H6AnkdfOhBEGhhe.D.4.HYAJaquZDWuHT7qXS2q', 'admin', 1);
