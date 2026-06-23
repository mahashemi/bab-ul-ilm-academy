<?php
require_once __DIR__ . '/db.php';
$user = auth();

// Landing page shows only the most popular subjects to avoid overwhelming visitors
// with the full subject list (the rest live on courses.php). Popularity = real
// student demand (enrollments) and teacher supply (distinct teachers), so this
// list re-ranks itself automatically as the platform grows — no manual curation.
$subjects = $pdo->query(
    "SELECT s.*,
            (SELECT COUNT(*) FROM courses c WHERE c.subject_id = s.id AND c.is_published = 1 AND c.moderation_status = 'approved') AS course_count,
            (SELECT COUNT(DISTINCT c.teacher_id) FROM courses c WHERE c.subject_id = s.id AND c.is_published = 1 AND c.moderation_status = 'approved') AS teacher_count,
            (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.subject_id = s.id AND c.is_published = 1 AND c.moderation_status = 'approved') AS enrollment_count
     FROM subjects s
     ORDER BY enrollment_count DESC, teacher_count DESC, course_count DESC, s.name ASC
     LIMIT 8"
)->fetchAll();

$courses = $pdo->query(
    "SELECT c.*, u.name AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
     FROM courses c
     JOIN users u ON u.id = c.teacher_id
     LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE c.is_published = 1 AND c.moderation_status = 'approved'
     ORDER BY c.created_at DESC LIMIT 12"
)->fetchAll();

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users WHERE role='teacher') AS teachers,
        (SELECT COUNT(*) FROM users WHERE role='student') AS students,
        (SELECT COUNT(*) FROM courses WHERE is_published=1 AND moderation_status='approved') AS courses"
)->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(SITE_NAME) ?> — <?= e(SITE_TAGLINE) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>

<nav class="navbar">
    <a class="nav-brand" href="index.php"><i data-lucide="landmark" class="lucide-icon"></i> <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <?php if ($user): ?>
            <a href="chat.php">Messages</a>
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
            <div class="nav-account">
                <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="Account menu">
                    <span class="nav-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
                    <i data-lucide="chevron-down" class="lucide-icon"></i>
                </button>
                <div class="nav-account-menu">
                    <div class="nav-account-header">
                        <span class="nav-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
                        <div>
                            <div class="nav-account-name"><?= e($user['name']) ?></div>
                            <div class="nav-account-email"><?= e($user['email']) ?></div>
                        </div>
                    </div>
                    <div class="nav-menu-divider"></div>
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> Dashboard</a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                    <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> Admin Panel</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn">Login</a>
        <?php endif; ?>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
    </div>
</nav>

<header class="hero">
    <div class="hero-content">
        <div class="hero-arabic">باب العلم</div>
        <h1>Teach and Learn Any Subject — <span>All Levels, Anywhere, Everywhere</span></h1>
        <p style="font-size:1.15rem;font-weight:600;opacity:.9;margin-bottom:.8rem;letter-spacing:.3px;color:var(--gold)"><?= e(SITE_AFFILIATION) ?></p>
        <p>Islamic studies and core academics — from Quran, Hadith, and Fiqh to Mathematics, Science, and Bachelor-level streams — taught by qualified teachers, anywhere in the world. Structured courses, real progress tracking, sincere teaching, Grade 1 through university.</p>
        <div class="hero-actions">
            <?php if ($user): ?>
                <a href="courses.php" class="btn btn-primary">Browse Courses</a>
            <?php else: ?>
                <a href="register.php" class="btn btn-primary">Start Learning Free</a>
            <?php endif; ?>
            <a href="#courses" class="btn btn-secondary">Explore</a>
        </div>
        <div class="hero-stats">
            <div class="hero-stat"><div class="num"><?= (int) $stats['teachers'] ?></div><div class="lbl">Teachers</div></div>
            <div class="hero-stat"><div class="num"><?= (int) $stats['students'] ?></div><div class="lbl">Students</div></div>
            <div class="hero-stat"><div class="num"><?= (int) $stats['courses'] ?></div><div class="lbl">Courses</div></div>
        </div>
    </div>
</header>

<?php if (!$user): ?>
<section class="mission-band">
    <div class="mission-grid">
        <div>
            <h3><i data-lucide="target" class="lucide-icon"></i> Our Vision</h3>
            <p>To become the foremost online learning institution for the Muslim Ummah — connecting qualified scholars and teachers with students worldwide, making both sacred knowledge and core academic education accessible to every Muslim, regardless of geography or resources.</p>
        </div>
        <div>
            <h3><i data-lucide="globe" class="lucide-icon"></i> Our Mission</h3>
            <p>A structured, trust-based e-learning platform spanning two pillars: Islamic studies (Quran, Hadith, Fiqh, Arabic) and core academics (Grade 1 through Bachelor-level streams). Teachers publish courses, students track real progress — knowledge, religious or worldly, is one of the highest acts of worship.</p>
        </div>
    </div>
    <div class="mission-cta">
        <p>Already have an account?</p>
        <div class="hero-actions" style="justify-content:center">
            <a href="login.php" class="btn btn-primary">Log In</a>
            <a href="register.php" class="btn btn-outline">Create Free Account</a>
        </div>
    </div>
</section>
<?php endif; ?>

<div class="container section" id="courses">
    <h2 class="section-title">Browse by <span>Subject</span></h2>
    <div class="chip-row">
        <?php foreach ($subjects as $s): ?>
            <a href="courses.php?subject=<?= (int) $s['id'] ?>" class="cat-chip"><?= catIcon($s['icon']) ?> <?= e($s['name']) ?></a>
        <?php endforeach; ?>
        <a href="courses.php" class="chip-view-all">View All Subjects <i data-lucide="arrow-right" class="lucide-icon"></i></a>
    </div>

    <h2 class="section-title">Featured <span>Courses</span></h2>
    <p class="section-sub">Taught by qualified Islamic scholars and educators</p>

    <div class="grid-3">
        <?php foreach ($courses as $c): ?>
        <a href="course.php?id=<?= (int) $c['id'] ?>" class="course-card" style="text-decoration:none;color:inherit">
            <div class="course-cover">
                <?php if ($c['cover_url']): ?><img src="<?= e($c['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($c['subject_icon']) ?><?php endif; ?>
                <span class="badge badge-<?= e($c['level']) ?> course-level"><?= e(ucfirst($c['level'])) ?></span>
            </div>
            <div class="course-body">
                <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                <div class="course-title"><?= e($c['title']) ?></div>
                <div class="course-desc"><?= e($c['description']) ?></div>
                <div class="course-meta">
                    <span><i data-lucide="user" class="lucide-icon"></i> <?= e($c['teacher_name']) ?></span>
                    <span><i data-lucide="graduation-cap" class="lucide-icon"></i> <?= (int) $c['student_count'] ?> enrolled</span>
                </div>
            </div>
            <div class="course-footer">
                <span class="course-price <?= $c['price'] == 0 ? 'free' : '' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
                <span class="btn btn-outline btn-sm">View Course <i data-lucide="arrow-right" class="lucide-icon"></i></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<footer>
    <div class="footer-grid">
        <div>
            <div class="footer-brand"><i data-lucide="landmark" class="lucide-icon"></i> <?= e(SITE_NAME) ?></div>
            <p>Seek Knowledge — From the Cradle to the Grave.</p>
        </div>
        <div>
            <div class="footer-heading">Learn</div>
            <ul class="footer-links">
                <li><a href="courses.php">All Courses</a></li>
                <li><a href="register.php">Join Free</a></li>
            </ul>
        </div>
        <div>
            <div class="footer-heading">Account</div>
            <ul class="footer-links">
                <li><a href="login.php">Login</a></li>
                <li><a href="dashboard.php">Dashboard</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Built with <i data-lucide="heart" class="lucide-icon"></i> for the Ummah.</div>
</footer>

<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
