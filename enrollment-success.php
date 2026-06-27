<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

// Accepts either ?course_id=X (the existing single free-course enroll
// path) or ?course_ids=X,Y,Z (a multi-course paid order from
// checkout-success.php) -- one shared "you're in" page regardless of how
// the student got here, matching the Udemy screenshot's "Great choice!"
// treatment for both.
$courseIds = [];
if (!empty($_GET['course_ids'])) {
    $courseIds = array_filter(array_map('intval', explode(',', $_GET['course_ids'])));
} elseif (!empty($_GET['course_id'])) {
    $courseIds = [(int) $_GET['course_id']];
}

if (!$courseIds) {
    redirect('dashboard.php');
}

// Only show courses this student is actually enrolled in -- never lets
// this page be used to peek at a course's existence/cover via a guessed id.
$placeholders = implode(',', array_fill(0, count($courseIds), '?'));
$stmt = $pdo->prepare(
    "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     JOIN enrollments e ON e.course_id = c.id
     WHERE c.id IN ($placeholders) AND e.student_id = ?
     ORDER BY e.enrolled_at DESC"
);
$stmt->execute([...$courseIds, $user['id']]);
$courses = $stmt->fetchAll();

if (!$courses) {
    redirect('dashboard.php');
}
$firstCourse = $courses[0];
$restCourses = array_slice($courses, 1);

$courseSelect = "c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
            (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
            (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
            (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating";
$enrolledExclude = array_merge($courseIds, [0]);
$excludePlaceholders = implode(',', array_fill(0, count($enrolledExclude), '?'));
$recommended = $pdo->prepare(
    "SELECT $courseSelect FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE c.is_published = 1 AND c.moderation_status = 'approved' AND c.id NOT IN ($excludePlaceholders)
       AND c.id NOT IN (SELECT course_id FROM enrollments WHERE student_id = ?)
     ORDER BY student_count DESC, avg_rating DESC LIMIT 4"
);
$recommended->execute([...$enrolledExclude, $user['id']]);
$recommended = $recommended->fetchAll();

$firstName = explode(' ', displayNameOf($user))[0];
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>You're Enrolled! — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
</head>
<body>
<?= renderPointsCelebration() ?>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="<?= e(t('nav_search_placeholder')) ?>">
    </form>
    <div class="nav-links">
        <a href="index.php"><?= t('nav_home') ?></a>
        <a href="courses.php"><?= t('nav_courses') ?></a>
        <a href="dashboard.php">Dashboard</a>
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
                <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> <?= t('nav_dashboard') ?></a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="container section" style="max-width:900px">
    <div class="enroll-success-banner">
        <div><i data-lucide="check-circle-2" class="lucide-icon"></i> Great choice, <?= e($firstName) ?>!</div>
        <button type="button" class="btn btn-outline btn-sm" onclick="navigator.share ? navigator.share({title: document.title, url: location.href}) : alert('Copy this page\'s link to share: ' + location.href)"><i data-lucide="share-2" class="lucide-icon"></i> Share <?= count($courses) === 1 ? 'this course' : 'these courses' ?></button>
    </div>

    <h2 class="section-title" style="margin-top:2rem">Jump Right <span>In</span></h2>
    <div class="jump-right-in">
        <div class="jump-right-in-cover">
            <?php if ($firstCourse['cover_url']): ?><img src="<?= e($firstCourse['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($firstCourse['subject_icon']) ?><?php endif; ?>
        </div>
        <div class="jump-right-in-body">
            <h3><?= e($firstCourse['title']) ?></h3>
            <p style="opacity:.75;font-size:.85rem">By <?= e($firstCourse['teacher_name']) ?></p>
            <div style="margin:1rem 0">
                <p style="font-size:.78rem;opacity:.7;margin-bottom:.4rem">Your progress</p>
                <div class="profile-progress-track" style="background:rgba(255,255,255,.15)"><div class="profile-progress-fill" style="width:0%"></div></div>
            </div>
            <a href="course.php?id=<?= (int) $firstCourse['id'] ?>" class="btn btn-primary">Start Course</a>
        </div>
    </div>

    <?php if ($restCourses): ?>
        <h3 style="margin:2rem 0 1rem;color:var(--green-deep)">Also in this purchase</h3>
        <?php foreach ($restCourses as $c): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.8rem 0;border-bottom:1px solid var(--border)">
            <div>
                <a href="course.php?id=<?= (int) $c['id'] ?>" style="font-weight:700;color:var(--text)"><?= e($c['title']) ?></a>
                <div style="font-size:.78rem;color:var(--text-light)">By <?= e($c['teacher_name']) ?></div>
            </div>
            <a href="course.php?id=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Start</a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($recommended): ?>
    <h3 style="margin:2.5rem 0 1.2rem;color:var(--green-deep)">Bestsellers You Might Like</h3>
    <div class="grid-3">
        <?php foreach ($recommended as $c): ?><?= renderCourseCard($c) ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
