<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*, s.name AS subject_name FROM courses c LEFT JOIN subjects s ON s.id = c.subject_id WHERE c.id = ?');
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isOwner = $course['teacher_id'] == $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You do not have permission to view this course\'s students. <a href="course.php?id=' . $id . '">Go back</a></p>');
}

$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.country, e.enrolled_at,
            (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id WHERE l.course_id = ? AND lp.student_id = u.id) AS completed_count,
            (SELECT COUNT(*) FROM lessons WHERE course_id = ?) AS lesson_count
     FROM enrollments e
     JOIN users u ON u.id = e.student_id
     WHERE e.course_id = ?
     ORDER BY e.enrolled_at DESC"
);
$stmt->execute([$id, $id, $id]);
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Students — <?= e($course['title']) ?> — <?= e(SITE_NAME) ?></title>
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
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
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
    </div>
</nav>

<div class="dashboard-wrap">
    <div class="dashboard-header">
        <h2><i data-lucide="graduation-cap" class="lucide-icon"></i> Enrolled Students</h2>
        <p><?= e($course['title']) ?><?= $course['subject_name'] ? ' · ' . e($course['subject_name']) : '' ?> — <?= count($students) ?> student(s)</p>
    </div>

    <?php if (!$students): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="graduation-cap" class="lucide-icon"></i></div><h3>No students enrolled yet</h3></div>
    <?php else: ?>
    <div class="grid-2">
        <?php foreach ($students as $s): ?>
        <?php $pct = $s['lesson_count'] ? (int) round($s['completed_count'] / $s['lesson_count'] * 100) : 0; ?>
        <div class="student-card">
            <div class="student-avatar"><?= e(mb_substr($s['name'], 0, 1)) ?></div>
            <div class="student-info">
                <div class="student-name"><?= e($s['name']) ?></div>
                <div class="student-meta"><?= e($s['country'] ?: 'Country not set') ?> · Enrolled <?= date('M j, Y', strtotime($s['enrolled_at'])) ?></div>
                <div class="student-meta"><?= $pct ?>% complete (<?= (int) $s['completed_count'] ?>/<?= (int) $s['lesson_count'] ?> lessons)</div>
            </div>
            <a href="chat.php?with=<?= (int) $s['id'] ?>&course=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline">Message</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
