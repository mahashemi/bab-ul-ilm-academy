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
- [x] Choose domain: babulilm.com — P1
- [ ] Define teacher vs. student feature split — P1
- [ ] Define course and lesson data structure — P1
- [ ] Wireframe: Home/landing page — P1
- [ ] Wireframe: Course detail page — P1
- [ ] Wireframe: Teacher dashboard — P1
- [ ] Wireframe: Student dashboard — P1

## Phase 2 — Backend / Database
- [~] Write database schema (schema.sql) — P1
- [~] Create config.php — P1
- [~] Create db.php (PDO + helpers) — P1
- [ ] Implement user registration (teacher / student role) — P1
- [ ] Implement login / logout — P1
- [ ] Implement course creation (teacher) — P1
- [ ] Implement lesson creation per course (teacher) — P1
- [ ] Implement course enrollment (student) — P1
- [ ] Implement lesson progress tracking — P1
- [ ] Implement course catalog with search/filter — P1
- [ ] Teacher profile: qualification, bio, subjects — P2
- [ ] Student profile: name, country, bio — P2
- [ ] Course reviews / ratings by students — P2
- [ ] Certificate generation on completion — P3
- [ ] Payment integration for paid courses — P3
- [ ] Admin panel: approve/reject teachers — P2

## Phase 3 — Frontend / UI
- [~] Create style.css (Islamic academic theme) — P1
- [~] Build index.php (landing + featured courses) — P1
- [~] Build register.php (dual-role) — P1
- [~] Build login.php — P1
- [ ] Build courses.php (full catalog) — P1
- [ ] Build course.php (detail + enroll button) — P1
- [ ] Build lesson.php (content view) — P1
- [ ] Build teacher-dashboard.php — P1
- [ ] Build student-dashboard.php — P1
- [ ] Build add-course.php (teacher form) — P1
- [ ] Build add-lesson.php (teacher form) — P1
- [ ] Mobile responsive layout — P1
- [ ] Progress bar on lesson list — P2
- [ ] Arabic / RTL layout option — P3

## Phase 4 — Testing
- [ ] Test teacher registration and course creation — P1
- [ ] Test student enrollment and lesson access — P1
- [ ] Test search and filter on course catalog — P1
- [ ] Test mobile on Android / iOS browsers — P1
- [ ] Security audit (SQL injection, XSS, CSRF) — P1
- [ ] Test all form validations — P1

## Phase 5 — Deployment
- [ ] Choose hosting (cPanel shared hosting recommended) — P1
- [ ] Register domain: babulilm.com — P1
- [ ] Set up MySQL on hosting — P1
- [ ] Upload files via FTP — P1
- [ ] Run schema.sql on production — P1
- [ ] Update config.php for production — P1
- [ ] Test on live server — P1
- [ ] Set up SSL — P1
- [ ] Configure email (registration confirmations) — P2

## Phase 6 — Launch & Growth
- [ ] Recruit first 5 qualified teachers — P1
- [ ] Launch with 10 free courses — P1
- [ ] Announce on Islamic community groups — P2
- [ ] Collect student feedback — P2
- [ ] Add video hosting (YouTube embed / Vimeo) — P2
- [ ] Add live session scheduling (Zoom links) — P3
- [ ] Mobile app (Android) — P3
- [ ] Multi-language: Arabic, Urdu, Farsi, Indonesian — P3

---

## Open Questions / Decisions Needed
- [!] Teacher verification process: who reviews and approves teacher credentials?
- [!] Paid courses: fee split between teacher and platform?
- [!] Video hosting: embed YouTube/Vimeo, or self-host?
- [!] Will there be a free tier for all students?
- [!] Certificate authority: who signs certificates (shaykh, institution)?

---

*Last updated:* 2026-06-17
