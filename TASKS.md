# Bab ul Ilm Academy — Project Tasks

## Status Legend
- `[ ]` Not started
- `[~]` In progress
- `[x]` Complete
- `[!]` Blocked / Needs decision

## Priority
- `P1` Critical / MVP must-have
- `P2` Important but can follow MVP
- `P3` Nice to have / future

---

## Phase 1 — Planning & Design
- [x] Define vision and mission (VISION.md) — P1
- [x] Choose app name: Bab ul Ilm Academy — P1
- [x] Choose domain: babulilmacademy.com — P1
- [x] Define teacher vs. student feature split — P1
- [x] Define course and lesson data structure — P1

## Phase 2 — Backend / Database
- [x] Write database schema (schema.sql) — P1
- [x] Create config.php — P1
- [x] Create db.php (PDO + helpers) — P1
- [x] Implement user registration (teacher / student role) — P1
- [x] Implement login / logout — P1
- [x] Implement course creation (teacher) — P1
- [x] Implement lesson creation per course (teacher) — P1
- [x] Implement course enrollment (student) — P1
- [x] Implement lesson progress tracking — P1
- [x] Implement course catalog with search/filter — P1
- [x] Teacher profile: qualification, bio, subjects — P2
- [x] Admin panel: suspend/reactivate users, publish/unpublish courses — P2
- [x] Cover image upload for courses (JPG/PNG/WEBP, 5MB max, server-validated) — P2
- [ ] Course reviews / ratings by students (table exists, no UI yet) — P2
- [ ] Certificate generation on completion — P3
- [ ] Payment integration for paid courses — P3

## Phase 3 — Frontend / UI
- [x] Create style.css (Islamic academic theme) — P1
- [x] Build index.php (landing + featured courses) — P1
- [x] Build register.php (dual-role) — P1
- [x] Build login.php — P1
- [x] Build courses.php (full catalog) — P1
- [x] Build course.php (detail + enroll button) — P1
- [x] Build dashboard.php (role-aware: teacher's courses / student's enrollments) — P1
- [x] Build add-course.php (teacher form) — P1
- [x] Build add-lesson.php (teacher form) — P1
- [x] Build admin.php (admin panel) — P2
- [x] Mobile responsive layout — P1
- [x] Progress bar on lesson list — P2
- [ ] Standalone lesson.php content view (lesson content currently shown inline via course.php) — P2
- [ ] Arabic / RTL layout option — P3

## Phase 4 — Production Readiness
- [x] Remove all demo/seed data — production DB starts with one admin account only — P1
- [x] Fix UTF-8 emoji encoding bug in subject icons (was corrupting to `?`) — P1
- [x] Write README.md with setup, admin credentials, and security notes — P1
- [x] Write DEPLOY.md with commit → push → deploy workflow — P1
- [x] Suspended accounts are blocked at login (is_approved check) — P1
- [ ] Add a "change password" UI (currently requires direct DB update) — P1
- [ ] Test teacher registration and course creation end-to-end — P1
- [ ] Test student enrollment and lesson completion end-to-end — P1
- [ ] Test search and filter on course catalog — P1
- [ ] Test mobile on Android / iOS browsers — P1
- [ ] Security audit (SQL injection, XSS, CSRF) — P1
- [ ] Test all form validations — P1

## Phase 5 — Deployment
- [ ] Choose hosting (cPanel shared hosting recommended) — P1
- [x] Register domain: babulilmacademy.com — P1
- [ ] Set up MySQL on hosting — P1
- [ ] Upload files via FTP — P1
- [ ] Run schema.sql on production (remember `--default-character-set=utf8mb4`) — P1
- [ ] Update config.php for production — P1
- [ ] Test on live server — P1
- [ ] Set up SSL — P1
- [ ] Configure email (registration confirmations) — P2

## Phase 6 — Launch & Growth
- [ ] Recruit first 5 qualified teachers — P1
- [ ] Launch with 10 free courses — P1
- [ ] Announce on Islamic community groups — P2
- [ ] Collect student feedback — P2
- [ ] Add video hosting (YouTube embed / Vimeo) — field exists in schema, not yet rendered — P2
- [ ] Add live session scheduling (Zoom links) — P3
- [ ] Mobile app (Android) — P3
- [ ] Multi-language: Arabic, Urdu, Farsi, Indonesian — P3

---

## Open Questions / Decisions Needed
- [!] Teacher verification process: who reviews and approves teacher credentials before they can publish?
- [!] Paid courses: fee split between teacher and platform?
- [!] Video hosting: embed YouTube/Vimeo, or self-host?
- [!] Will there be a free tier for all students?
- [!] Certificate authority: who signs certificates (shaykh, institution)?

---

## Regression Test Scenarios & Credentials

### Local Test Users (seeded in `schema.sql`)

| User | Email | Password | Role | teacher_status | Purpose |
|---|---|---|---|---|---|
| Site Admin (main) | admin@babulilmacademy.com | Admin@123 | admin | none | The ONLY admin who can demote/suspend other admins |
| Admin-User | adminuser@test.com | Test@123 | admin | approved | Admin + instructor — dashboard teaching view, approve own courses, edit lessons |
| Admin-2 | admin2@test.com | Test@123 | admin | none | Pure admin — verifies non-main admin can't touch other admins; redirected to admin.php |
| Instructor | teacher@test.com | Test@123 | student | approved | Normal teacher flow (create/publish courses) |
| Student | student@test.com | Test@123 | student | none | Regular learner account |

### Regression Scenarios

1. **Admin visibility in admin panel** — Login as Site Admin → `admin.php?tab=users` → all 5 users visible including Admin-User and Admin-2 rows.
2. **Main-admin-only demote/suspend** — Login as Admin-2 → `admin.php?tab=users` → Admin-2 sees NO suspend/role-change controls on other admin rows (controls hidden + server-guarded). Login as Site Admin → controls ARE visible on admin rows.
3. **Admin+instructor dashboard** — Login as Admin-User → `dashboard.php` → sees "My Courses" teaching section with Approve/Reject buttons on pending courses.
4. **Pure admin redirect** — Login as Admin-2 → `dashboard.php` → redirected to `admin.php` (not the teacher dashboard).
5. **Edit-course publish step approve/reject** — Login as Site Admin → `edit-course.php?id=1&step=publish` → Approve/Reject buttons appear for admin on a pending course.
6. **Edit-lesson access for admin** — Login as Admin-User → `edit-lesson.php?id=1` → opens directly, NOT redirected to admin.php.
7. **Edit-quiz access for admin** — Login as Admin-User → `edit-quiz.php?id=1` → opens directly (if a quiz exists).
8. **Course description step** — `edit-course.php?id=1&step=details` → "Save & Continue" button + "Textbook / Reference Material (optional)" field both present.
9. **Course creation flow** — `add-course.php` → fill basics → "Save & Continue" → redirects to `edit-course.php?id=X&step=details` → description + textbook fields visible.

### Local Setup (XAMPP)

```bash
# 1. Start XAMPP (Apache + MySQL)
sudo /Applications/XAMPP/xamppfiles/xampp start

# 2. Fix MySQL temp dir permissions (one-time)
sudo chown -R _mysql:_mysql /Applications/XAMPP/xamppfiles/temp/mysql

# 3. Copy app to htdocs (if not already there)
sudo cp -r workspace/bab-ul-ilm-academy /Applications/XAMPP/htdocs/

# 4. Create + import the database
/Applications/XAMPP/xamppfiles/bin/mysql -u root -e "DROP DATABASE IF EXISTS bab_ul_ilm; CREATE DATABASE bab_ul_ilm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
/Applications/XAMPP/xamppfiles/bin/mysql -u root --default-character-set=utf8mb4 bab_ul_ilm < workspace/bab-ul-ilm-academy/schema.sql

# 5. Open in browser
open "https://localhost/bab-ul-ilm-academy/"
```

---

*Last updated:* 2026-08-01
