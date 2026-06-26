<?php
require_once __DIR__ . '/db.php';
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$profile = $stmt->fetch();

if (!$profile) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">This profile doesn\'t exist. <a href="courses.php">Browse courses</a></p>');
}

$isTeacher = isApprovedTeacher($profile);

$teacherStats = ['course_count' => 0, 'student_count' => 0];
$courses = [];
if ($isTeacher) {
    $statsStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT c.id) AS course_count, COUNT(DISTINCT e.student_id) AS student_count
         FROM courses c LEFT JOIN enrollments e ON e.course_id = c.id
         WHERE c.teacher_id = ? AND c.is_published = 1 AND c.moderation_status = 'approved'"
    );
    $statsStmt->execute([$id]);
    $teacherStats = $statsStmt->fetch();

    $coursesStmt = $pdo->prepare(
        "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
                (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
                (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating
         FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
         WHERE c.teacher_id = ? AND c.is_published = 1 AND c.moderation_status = 'approved'
         ORDER BY c.created_at DESC"
    );
    $coursesStmt->execute([$id]);
    $courses = $coursesStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e(displayNameOf($profile)) ?> — <?= e(SITE_NAME) ?></title>
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
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
            <?= renderCartIcon($pdo, $user) ?>
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
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> Become an Instructor</a><?php endif; ?>
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

<div class="dashboard-wrap" style="max-width:860px">
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <div class="instructor-card">
            <?= renderAvatar($profile, 'profile-avatar') ?>
            <div>
                <h1 style="font-size:1.3rem;margin-bottom:.1rem"><?= e(displayNameOf($profile)) ?></h1>
                <?php if ($isTeacher && $profile['headline']): ?>
                    <div style="font-size:.92rem;color:var(--text-mid)"><?= e($profile['headline']) ?></div>
                <?php endif; ?>
                <?php if ($isTeacher): ?>
                <div class="instructor-stats" style="margin-top:.5rem">
                    <span><i data-lucide="book-open" class="lucide-icon"></i> <?= (int) $teacherStats['course_count'] ?> course<?= $teacherStats['course_count'] == 1 ? '' : 's' ?></span>
                    <span><i data-lucide="users" class="lucide-icon"></i> <?= (int) $teacherStats['student_count'] ?> student<?= $teacherStats['student_count'] == 1 ? '' : 's' ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isTeacher && $profile['qualification']): ?>
            <h3 style="font-size:1rem;margin:1.2rem 0 .4rem;color:var(--green-deep)">Qualification</h3>
            <p style="font-size:.9rem;color:var(--text-mid);white-space:pre-line"><?= e($profile['qualification']) ?></p>
        <?php endif; ?>
        <?php if ($profile['bio']): ?>
            <h3 style="font-size:1rem;margin:1.2rem 0 .4rem;color:var(--green-deep)">About</h3>
            <p style="font-size:.9rem;color:var(--text-mid);white-space:pre-line"><?= e($profile['bio']) ?></p>
        <?php endif; ?>
    </div></div>

    <?php if ($isTeacher): ?>
        <h3 style="font-size:1.1rem;margin-bottom:1rem;color:var(--green-deep)">Courses by <?= e(displayNameOf($profile)) ?></h3>
        <?php if (!$courses): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="book-open" class="lucide-icon"></i></div><h3>No published courses yet</h3></div>
        <?php else: ?>
        <div class="grid-3">
            <?php foreach ($courses as $c): ?><?= renderCourseCard($c) ?><?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
