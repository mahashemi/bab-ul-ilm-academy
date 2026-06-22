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
        <?php if ($user): ?><span class="nav-user"><i data-lucide="user" class="lucide-icon"></i> <?= e($user['name']) ?></span><a href="chat.php">Messages</a>
            <a href="dashboard.php">Dashboard</a>
            <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php">Admin</a><?php endif; ?>
            <a href="logout.php" class="nav-btn">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php" class="nav-btn">Join Free</a>
        <?php endif; ?>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
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

<footer>
    <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Seek Knowledge — From the Cradle to the Grave.</div>
</footer>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
