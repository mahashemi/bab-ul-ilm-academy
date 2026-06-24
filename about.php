<?php
require_once __DIR__ . '/db.php';
$user = auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>About Us — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="courses.php">Courses</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <?php if ($user): ?>
            <a href="chat.php">Messages</a>
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
            <div class="nav-account">
                <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="Account menu">
                    <?= renderAvatar($user) ?>
                    <i data-lucide="chevron-down" class="lucide-icon"></i>
                </button>
                <div class="nav-account-menu">
                    <div class="nav-account-header">
                        <?= renderAvatar($user) ?>
                        <div>
                            <div class="nav-account-name"><?= e(displayNameOf($user)) ?></div>
                            <div class="nav-account-email"><?= e($user['email']) ?></div>
                        </div>
                    </div>
                    <div class="nav-menu-divider"></div>
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> Dashboard</a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                    <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                    <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> Admin Panel</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn">Login</a>
        <?php endif; ?>
    </div>
</nav>

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
</section>

<div class="container section">
    <h2 class="section-title">What Makes Us <span>Different</span></h2>
    <div class="grid-3">
        <div class="card"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.5rem;color:var(--green-deep)"><i data-lucide="landmark" class="lucide-icon"></i> Two Pillars</h3>
            <p style="color:var(--text-mid);font-size:.92rem">Sacred knowledge and core academics, side by side — Quran, Hadith, and Fiqh alongside Mathematics, Science, and university-level streams.</p>
        </div></div>
        <div class="card"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.5rem;color:var(--green-deep)"><i data-lucide="user" class="lucide-icon"></i> Qualified Teachers</h3>
            <p style="color:var(--text-mid);font-size:.92rem">Every course is taught by a verified scholar, hafiz, or qualified academic teacher — Adab and Ikhlas guide every classroom.</p>
        </div></div>
        <div class="card"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.5rem;color:var(--green-deep)"><i data-lucide="bar-chart-3" class="lucide-icon"></i> Real Progress</h3>
            <p style="color:var(--text-mid);font-size:.92rem">Students track completion lesson-by-lesson, and teachers see exactly how their students are progressing.</p>
        </div></div>
    </div>
    <div style="text-align:center;margin-top:2.5rem">
        <p style="color:var(--text-mid);margin-bottom:1rem">Have a question or suggestion?</p>
        <a href="feedback.php" class="btn btn-primary">Send Us Feedback</a>
    </div>
</div>

<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
