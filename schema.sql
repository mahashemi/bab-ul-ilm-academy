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
    role         ENUM('student','teacher','parent','institution','admin','customer_service') DEFAULT 'student',
    -- 'teacher' is a legacy role value kept only for rows created before
    -- teaching became an orthogonal capability (see teacher_status below);
    -- new accounts are always 'student' regardless of whether they teach.
    -- 'parent'/'institution' are likewise legacy-only -- signup no longer
    -- offers them (see register.php); a future dedicated intake flow may
    -- reintroduce them as their own "Become a Parent/Institution" upgrade,
    -- mirroring teacher_status, but that doesn't exist yet.
    teacher_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none', -- see isApprovedTeacher() in db.php -- the actual source of truth for teaching access, independent of role
    instructor_policies_agreed_at DATETIME NULL, -- set when the instructor application (become-instructor.php) is submitted, after they check "I agree" to instructor-policies.php
    gender       ENUM('male','female','unspecified') DEFAULT 'unspecified',
    date_of_birth DATE NULL,
    education_level VARCHAR(50) NULL,       -- Student/Parent: highest level completed or in progress
    preferred_language VARCHAR(50) NULL,
    organization_name VARCHAR(200) NULL,    -- legacy Institution accounts only
    phone        VARCHAR(30),
    phone_verified TINYINT(1) DEFAULT 0,
    country      VARCHAR(100),
    bio          TEXT,
    qualification TEXT,              -- Instructor application: credentials, degrees
    headline     VARCHAR(150) NULL,  -- Instructor application: short title shown under their name, e.g. "Developer and Lead Instructor"
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

ALTER TABLE subjects ADD UNIQUE KEY IF NOT EXISTS uniq_subject_name (name);

-- Taxonomy expansion (2026-06-25) — richer subject coverage per field,
-- and subjects for Postgraduate/PhD which previously had none at all.
INSERT IGNORE INTO subjects (field_of_study_id, name, icon) VALUES
(1, 'Tafsir (Quranic Exegesis)', 'book-open-text'),
(1, 'Seerah (Life of the Prophet)', 'footprints'),
(1, 'Islamic Finance', 'landmark'),
(1, 'Comparative Religion', 'globe-2'),
(1, 'Dawah & Islamic Communication', 'megaphone'),
(2, 'Physics', 'orbit'),
(2, 'Chemistry', 'flask-conical'),
(2, 'Biology', 'leaf'),
(2, 'Geography', 'map'),
(2, 'Foreign Languages', 'languages'),
(3, 'Computer Science & IT', 'laptop'),
(3, 'Law', 'gavel'),
(3, 'Education & Teaching', 'school'),
(3, 'Psychology', 'brain'),
(3, 'Agriculture', 'sprout'),
(4, 'IELTS / TOEFL', 'languages'),
(4, 'O-Levels', 'file-text'),
(4, 'A-Levels', 'file-text'),
(4, 'University Entrance Tests', 'target'),
(5, 'Research Methodology', 'search'),
(5, 'MBA / Business Administration', 'briefcase'),
(5, 'Master''s in Islamic Studies', 'moon-star'),
(5, 'Master''s in Education', 'school'),
(5, 'PhD Dissertation Writing', 'file-pen'),
(5, 'Academic Publishing', 'book-marked');

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
    content         LONGTEXT,            -- Sanitized rich-text HTML (see sanitizeLessonHtml())
    video_url       VARCHAR(500),        -- YouTube / Vimeo embed URL
    slides_url      VARCHAR(500),        -- Uploaded PDF slide deck, served from /uploads/lesson-slides/
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

-- ── Password reset (separate token/expiry from email verification, since
-- a user could have both a pending verification and a pending reset) ──
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_reset_token VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS password_reset_expires DATETIME NULL;

-- ── Display name (shown publicly instead of the full/legal name where
-- relevant — class chat, course instructor byline, reviews) — name itself
-- stays the account's legal/full name. Falls back to name via COALESCE
-- everywhere it's read, so this is purely additive. ──
ALTER TABLE users ADD COLUMN IF NOT EXISTS display_name VARCHAR(100) NULL;

-- ── Account activity log (security — lets a user see "is this really my
-- recent activity", same idea as Google/Facebook's account activity page) ──
CREATE TABLE IF NOT EXISTS account_activity_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    action     VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_learning_fields (
    user_id INT UNSIGNED NOT NULL,
    field_of_study_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, field_of_study_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (field_of_study_id) REFERENCES fields_of_study(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Gamification: points ──────────────────────────────────────────────────
-- Points map only to real platform actions (enrolling, completing lessons,
-- clean chat participation, reviews, course approval) — no fabricated
-- quiz/assignment features that don't exist yet.
CREATE TABLE IF NOT EXISTS user_points (
    user_id INT UNSIGNED PRIMARY KEY,
    points  INT NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS point_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    points     INT NOT NULL,
    reason     VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Gamification: badges ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS badges (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) UNIQUE NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    icon        VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_badges (
    user_id   INT UNSIGNED NOT NULL,
    badge_id  INT UNSIGNED NOT NULL,
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, badge_id),
    FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO badges (code, name, description, icon) VALUES
('first_enrollment',  'First Step',         'Enrolled in your first course', 'footprints'),
('first_completion',  'Course Graduate',    'Completed your first course', 'graduation-cap'),
('five_completions',  'Dedicated Learner',  'Completed 5 courses', 'medal'),
('skilled',           'Skilled',            'Listed 5 or more skills on your profile', 'sparkles'),
('chatty',            'Active Participant', 'Posted 10+ clean messages in class discussions', 'message-circle'),
('bronze_learner',    'Bronze Learner',     'Earned 100 points', 'award'),
('silver_learner',    'Silver Learner',     'Earned 500 points', 'award'),
('gold_learner',      'Gold Learner',       'Earned 1000 points', 'award'),
('published_teacher', 'Published Teacher',  'Had your first course approved', 'book-open-check'),
('popular_teacher',   'Popular Teacher',    'Reached 50 total students across your courses', 'users'),
('top_rated',         'Top Rated',          'Maintained a 4.5+ rating with 5+ reviews', 'star');

-- ── Notification log (anti-spam cooldown tracking for engagement emails) ──
-- Every notifyUser() call checks this before sending — if the same
-- (user, type, related_id) combination was already emailed within the
-- type's cooldown window, the send is skipped. This is the actual mechanism
-- that keeps inboxes from being overwhelmed by frequent events like chat
-- messages or enrollments.
CREATE TABLE IF NOT EXISTS notification_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    type       VARCHAR(50) NOT NULL,
    related_id INT UNSIGNED NOT NULL DEFAULT 0,
    sent_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_lookup (user_id, type, related_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase B: messaging upgrades ───────────────────────────────────────────
-- last_active_at drives the honest "Delivered" state for 1:1 messages
-- (recipient has been active on the site since the message was sent, even
-- if they haven't opened this specific conversation) — updated at most
-- once a minute per user from db.php, not a per-message ping.
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_active_at DATETIME NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(300) NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_type VARCHAR(10) NULL;
ALTER TABLE messages ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) NULL;

-- ── Phase D: quizzes ───────────────────────────────────────────────────────
-- Course-level (not per-lesson) so a teacher can have e.g. "Week 1 Quiz",
-- "Final Quiz" without needing to attach each to one specific lesson.
CREATE TABLE IF NOT EXISTS quizzes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id  INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    passing_score TINYINT UNSIGNED NOT NULL DEFAULT 60,
    sort_order SMALLINT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_questions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id    INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    sort_order SMALLINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_options (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    option_text VARCHAR(500) NOT NULL,
    is_correct  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order  SMALLINT UNSIGNED DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Retakes are allowed (each attempt is its own row) -- the dashboard/quiz
-- page shows the BEST attempt, not just the latest.
CREATE TABLE IF NOT EXISTS quiz_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id    INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    score      TINYINT UNSIGNED NOT NULL,
    total      TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_quiz_student (quiz_id, student_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS quiz_attempt_answers (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id  INT UNSIGNED NOT NULL,
    question_id INT UNSIGNED NOT NULL,
    option_id   INT UNSIGNED NULL,
    is_correct  TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    FOREIGN KEY (option_id) REFERENCES quiz_options(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase D: assignments ────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS assignments (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    description TEXT,
    due_date    DATE NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assignment_submissions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id INT UNSIGNED NOT NULL,
    student_id    INT UNSIGNED NOT NULL,
    content       TEXT,
    file_path     VARCHAR(300) NULL,
    file_name     VARCHAR(255) NULL,
    grade         TINYINT UNSIGNED NULL,
    feedback      TEXT NULL,
    submitted_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    graded_at     TIMESTAMP NULL,
    UNIQUE KEY one_submission (assignment_id, student_id),
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase D: certificates ───────────────────────────────────────────────────
-- Issued automatically on 100% lesson completion (same definition already
-- used for the "Course Graduate" badge). certificate_code is the public
-- verification handle -- printed on the certificate, looked up by anyone
-- via verify-certificate.php with no login required, so a certificate
-- can't be faked by just making up a code.
CREATE TABLE IF NOT EXISTS certificates (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id       INT UNSIGNED NOT NULL,
    course_id        INT UNSIGNED NOT NULL,
    certificate_code VARCHAR(32) NOT NULL UNIQUE,
    issued_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_certificate (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Phase E: per-course Q&A (distinct from live class chat — persistent,
-- structured reference material tied to one question each, not a chat
-- stream) ──
CREATE TABLE IF NOT EXISTS course_questions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id   INT UNSIGNED NOT NULL,
    student_id  INT UNSIGNED NOT NULL,
    question    TEXT NOT NULL,
    answer      TEXT NULL,
    answered_by INT UNSIGNED NULL,
    answered_at TIMESTAMP NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (answered_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_course (course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── AI-assisted course authoring ──
-- Optional reference textbook, used to ground the AI lesson-generation
-- prompt in real source material instead of the AI's general knowledge.
ALTER TABLE courses ADD COLUMN textbook VARCHAR(255) NULL AFTER requirements;

-- Prompt text lives in the DB (not hardcoded in PHP) specifically so an
-- admin can tune wording without a code deploy. template_text uses
-- {{placeholder}} tokens substituted at render time -- see
-- renderAiPrompt() in db.php. Seed rows are inserted by a one-time PHP
-- script (not raw SQL here) since the prompt text is long and contains
-- quotes/braces that are error-prone to hand-escape in SQL.
CREATE TABLE IF NOT EXISTS ai_prompt_templates (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_key       VARCHAR(50) NOT NULL UNIQUE,
    label              VARCHAR(150) NOT NULL,
    template_text      TEXT NOT NULL,
    placeholders_help  TEXT NULL,
    updated_by         INT UNSIGNED NULL,
    updated_at         TIMESTAMP NULL DEFAULT NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Customer Service role ──
-- Scoped support staff: can author courses/lessons/quizzes/assignments on
-- behalf of a teacher who called in for help, but cannot manage users,
-- settings, or moderation like a full admin can. The existing
-- courses.updated_by / lessons-side activity log (see logActivity() calls
-- in the authoring pages) record the REAL actor, while teacher_id always
-- stays the actual teacher being helped -- so "who really created this"
-- stays auditable even though the content is attributed to the teacher.
ALTER TABLE users MODIFY role ENUM('student','teacher','parent','institution','admin','customer_service') DEFAULT 'student';

-- ── Social login (Google / Facebook / Microsoft / GitHub) ──
-- One linked identity per account, not a separate identities table --
-- simplest thing that satisfies "ease the signup process"; if someone
-- later logs in via a different provider under the same email, the link
-- just gets overwritten to that provider rather than supporting multiple
-- simultaneous links. password stays NOT NULL even for OAuth-only
-- accounts (a random, never-shared bcrypt hash is stored at signup) so
-- no existing password? code path anywhere else in the app needs a
-- null-check. Multiple NULL rows are fine under this UNIQUE key --
-- MySQL doesn't treat NULL <> NULL as a uniqueness violation.
ALTER TABLE users ADD COLUMN oauth_provider VARCHAR(20) NULL AFTER password;
ALTER TABLE users ADD COLUMN oauth_id VARCHAR(255) NULL AFTER oauth_provider;
ALTER TABLE users ADD UNIQUE KEY uniq_oauth (oauth_provider, oauth_id);

-- ── Cart & Checkout (Stripe / PayPal) ──
-- Revenue model: the platform's own gateway account collects 100% of every
-- payment (same approach Udemy itself uses internally -- see
-- support.udemy.com/hc/en-us/articles/229604008 -- their instructor payouts
-- are a separate, manually-batched accounting step, not a real-time
-- split-payment gateway either). Teacher payouts happen outside this system
-- for now. order_items snapshots price/teacher_id at purchase time so a
-- later price change or the course being edited never rewrites history.
CREATE TABLE IF NOT EXISTS cart_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id  INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NOT NULL,
    added_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_cart_item (student_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id        INT UNSIGNED NOT NULL,
    total_amount      DECIMAL(10,2) NOT NULL,
    currency          VARCHAR(3) NOT NULL DEFAULT 'USD',
    status            ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
    payment_gateway   ENUM('stripe','paypal') NOT NULL,
    payment_reference VARCHAR(255) NULL,  -- Stripe Checkout Session id, or PayPal Order id
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at           TIMESTAMP NULL,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_student (student_id),
    INDEX idx_reference (payment_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    course_id   INT UNSIGNED NOT NULL,
    teacher_id  INT UNSIGNED NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Initial Admin Account ───────────────────────────────────────────────
-- Default password: Admin@123
-- IMPORTANT: Log in immediately and change this password via your profile.
INSERT INTO users (name, email, password, role, is_verified) VALUES
('Site Admin', 'admin@babulilmacademy.com',
 '$2y$10$Rn49XbRBi1VaO9H6AnkdfOhBEGhhe.D.4.HYAJaquZDWuHT7qXS2q', 'admin', 1);
