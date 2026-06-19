# Bab ul Ilm Academy

**باب العلم** — "Gateway of Knowledge." An online learning platform connecting Islamic teachers with students worldwide — courses, lessons, enrollment, and progress tracking.

🌐 Domain: **babulilmacademy.com**

---

## What It Does

Bab ul Ilm Academy is a dual-role e-learning platform:

- **Teachers** register, create courses, organize them into lessons, and track enrolled students
- **Students** register, browse the course catalog, enroll, and mark lessons complete as they progress

## Tech Stack

- **PHP 7.4+** (no framework — plain PHP with PDO)
- **MySQL / MariaDB**
- **Vanilla HTML/CSS/JS** — no build step, deploy by copying files

## Features

| Feature | Status |
|---|---|
| Dual registration — Student or Teacher role at signup | ✅ |
| Teacher: create courses (subject, level, language, price) | ✅ |
| Teacher: add lessons to a course in order | ✅ |
| Student: browse/search course catalog by subject | ✅ |
| Student: enroll and mark lessons complete, see progress bar | ✅ |
| Admin panel — manage users & courses, suspend accounts, export CSV | ✅ |
| Certificates on course completion | 🔜 planned |
| Video lesson embedding (YouTube/Vimeo URL field exists, not yet rendered) | 🔜 planned |
| Paid course checkout | 🔜 planned |

## Project Structure

```
bab_ul_ilm_academy/
├── config.php           # DB credentials & site settings — EDIT THIS for your environment
├── db.php                # PDO connection + shared helper functions
├── schema.sql             # Database schema + one starter admin account
├── style.css              # Design system (Islamic academic green/teal/gold theme)
├── index.php              # Homepage / featured courses
├── register.php / login.php / logout.php
├── courses.php             # Browse all courses
├── course.php               # Course detail, enroll, lesson list
├── add-course.php            # Teacher: create a new course
├── add-lesson.php             # Teacher: add lessons to a course
├── dashboard.php               # Role-aware dashboard (teacher's courses / student's enrollments)
├── admin.php                    # Admin panel (users, courses, CSV export)
├── VISION.md                     # Product vision & mission
└── TASKS.md                       # Project task tracker
```

## Setup (Local — XAMPP)

1. Copy this folder into `C:\xampp\htdocs\bab_ul_ilm_academy`
2. Start Apache + MySQL in the XAMPP Control Panel
3. Import the schema:
   ```
   C:\xampp\mysql\bin\mysql.exe --default-character-set=utf8mb4 -u root < schema.sql
   ```
   > **Important:** always import with `--default-character-set=utf8mb4` — without it, the emoji subject icons get corrupted into `?` characters.
4. Visit `http://localhost/bab_ul_ilm_academy/`

## First Login

A single admin account is seeded by `schema.sql`:

- **Email:** `admin@babulilmacademy.com`
- **Password:** `Admin@123`

**Change this password immediately after your first login.** There is no "change password" UI yet — update it directly in the database:

```sql
UPDATE users SET password = '<new bcrypt hash>' WHERE email = 'admin@babulilmacademy.com';
```
(Generate a hash with PHP: `php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT);"`)

## Admin Panel

Visit `/admin.php` while logged in as the admin account (`role = 'admin'`) to:
- View platform stats (teachers, students, courses, enrollments)
- View and CSV-export all users; suspend/reactivate accounts (suspended users are blocked at login)
- View, publish/unpublish, and CSV-export all courses

## Roles

| Role | Capabilities |
|---|---|
| `student` | Browse courses, enroll, track lesson progress |
| `teacher` | Create courses, add lessons, see enrolled student counts |
| `admin` | Full platform oversight via `/admin.php` |

## Deployment

See [DEPLOY.md](DEPLOY.md) for the full commit → push → deploy workflow, including both shared-hosting (cPanel/FTP) and VPS (SSH + git pull) paths.

## Security Notes

- Passwords are hashed with `password_hash()` (bcrypt)
- All database queries use PDO prepared statements
- All forms are CSRF-protected
- Suspended accounts (`is_approved = 0`) cannot log in
- `config.php` ships with local XAMPP defaults (`root` / no password) — **you must change these before deploying to production**

## License

Private project. All rights reserved.
