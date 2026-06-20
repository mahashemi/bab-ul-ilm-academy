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

*Last updated:* 2026-06-19
