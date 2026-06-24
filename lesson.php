<?php
require_once __DIR__ . '/db.php';
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT l.*, c.id AS course_id, c.title AS course_title, c.teacher_id, c.moderation_status
     FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?'
);
$stmt->execute([$id]);
$lesson = $stmt->fetch();

if (!$lesson) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Lesson not found. <a href="courses.php">Go back</a></p>');
}

$isOwnerOrAdmin = $user && ((int) $lesson['teacher_id'] === (int) $user['id'] || ($user['role'] ?? '') === 'admin');
if ($lesson['moderation_status'] !== 'approved' && !$isOwnerOrAdmin) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">This course isn\'t public yet. <a href="courses.php">Go back</a></p>');
}

$isEnrolled = false;
if ($user && canEnroll($user['role'] ?? null)) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $lesson['course_id']]);
    $isEnrolled = (bool) $e->fetch();
}

// Server-side access gate — this is the actual enforcement, not just hiding a link.
// Anyone who can guess/visit a lesson ID is still subject to this check.
$canAccess = $isEnrolled || (int) $lesson['is_preview'] === 1 || $isOwnerOrAdmin;
if (!$canAccess) {
    http_response_code(403);
    $msg = $user
        ? 'You need to enroll in this course to view this lesson.'
        : 'Please log in and enroll in this course to view this lesson.';
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">' . htmlspecialchars($msg) . ' <a href="course.php?id=' . (int) $lesson['course_id'] . '">Go to course page</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_lesson'])) {
    requireAuth();
    verifyCsrf();
    if ($isEnrolled) {
        $ins = $pdo->prepare('INSERT IGNORE INTO lesson_progress (student_id, lesson_id) VALUES (?, ?)');
        $ins->execute([$user['id'], $id]);
        if ($ins->rowCount() > 0) {
            awardPoints($pdo, $user['id'], 15, 'Completed a lesson in "' . $lesson['course_title'] . '"');
            $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?');
            $totalStmt->execute([$lesson['course_id']]);
            $totalCount = (int) $totalStmt->fetchColumn();
            $doneStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
                 WHERE lp.student_id = ? AND l.course_id = ?'
            );
            $doneStmt->execute([$user['id'], $lesson['course_id']]);
            if ($totalCount > 0 && $totalCount === (int) $doneStmt->fetchColumn()) {
                awardPoints($pdo, $user['id'], 50, 'Completed the course "' . $lesson['course_title'] . '"');
                issueCertificateIfEligible($pdo, $user['id'], (int) $lesson['course_id']);
            }
        }
        redirect('lesson.php?id=' . $id);
    }
}

$allLessons = $pdo->prepare('SELECT id, title, is_preview FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
$allLessons->execute([$lesson['course_id']]);
$allLessons = $allLessons->fetchAll();

$completedLessons = [];
if ($user && $isEnrolled) {
    $cp = $pdo->prepare('SELECT lesson_id FROM lesson_progress WHERE student_id = ?');
    $cp->execute([$user['id']]);
    $completedLessons = array_column($cp->fetchAll(), 'lesson_id');
}
$isDone = in_array($id, $completedLessons, true);

$myIndex = null;
foreach ($allLessons as $i => $l) { if ((int) $l['id'] === $id) { $myIndex = $i; break; } }
$prevLesson = $myIndex !== null && $myIndex > 0 ? $allLessons[$myIndex - 1] : null;
$nextLesson = $myIndex !== null && $myIndex < count($allLessons) - 1 ? $allLessons[$myIndex + 1] : null;

function lessonAccessible(array $l, bool $isEnrolled, bool $isOwnerOrAdmin): bool {
    return $isEnrolled || (int) $l['is_preview'] === 1 || $isOwnerOrAdmin;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($lesson['title']) ?> — <?= e($lesson['course_title']) ?></title>
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

<div class="dashboard-wrap" style="max-width:1180px">
    <p style="font-size:.85rem;margin-bottom:1rem"><a href="course.php?id=<?= (int) $lesson['course_id'] ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($lesson['course_title']) ?></a></p>

    <div class="course-layout">
        <div class="course-main-col">
            <div class="card" style="margin-bottom:1.5rem">
                <?php if ($lesson['video_url']): ?>
                    <div style="position:relative;padding-top:56.25%;background:#000">
                        <iframe src="<?= e($lesson['video_url']) ?>" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen></iframe>
                    </div>
                <?php endif; ?>
                <div class="card-body">
                    <?php if ($lesson['is_preview']): ?><span class="badge badge-free" style="margin-bottom:.6rem">Free Preview</span><?php endif; ?>
                    <h1 style="font-size:1.4rem;margin-bottom:.6rem"><?= e($lesson['title']) ?></h1>
                    <?php if ($lesson['content']): ?>
                        <div style="color:var(--text-mid);white-space:pre-line;line-height:1.7"><?= e($lesson['content']) ?></div>
                    <?php elseif (!$lesson['video_url']): ?>
                        <p style="color:var(--text-light);font-style:italic">No content has been added for this lesson yet.</p>
                    <?php endif; ?>
                </div>
                <?php if ($isEnrolled): ?>
                <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <?php if ($isDone): ?>
                        <span class="alert alert-success" style="margin:0"><i data-lucide="check" class="lucide-icon"></i> Completed</span>
                    <?php else: ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="complete_lesson" value="<?= (int) $id ?>" class="btn btn-primary btn-sm">Mark as Complete</button>
                        </form>
                    <?php endif; ?>
                    <div style="display:flex;gap:.6rem">
                        <?php if ($prevLesson): ?><a href="lesson.php?id=<?= (int) $prevLesson['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="chevron-left" class="lucide-icon"></i> Previous</a><?php endif; ?>
                        <?php if ($nextLesson): ?><a href="lesson.php?id=<?= (int) $nextLesson['id'] ?>" class="btn btn-sm btn-outline">Next <i data-lucide="chevron-right" class="lucide-icon"></i></a><?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="enroll-card">
            <div class="card"><div class="card-body">
                <h3 style="font-size:.95rem;margin-bottom:.8rem;color:var(--green-deep)">Course Content</h3>
                <ul class="lesson-list" style="margin:0 -1.2rem">
                    <?php foreach ($allLessons as $i => $l): ?>
                        <?php $accessible = lessonAccessible($l, $isEnrolled, $isOwnerOrAdmin); $done = in_array((int) $l['id'], $completedLessons, true); ?>
                        <?php if ($accessible): ?>
                        <a href="lesson.php?id=<?= (int) $l['id'] ?>" class="lesson-item <?= $done ? 'done' : '' ?>" style="text-decoration:none;color:inherit;<?= (int) $l['id'] === $id ? 'background:var(--cream)' : '' ?>">
                            <div class="lesson-num"><?= $done ? '<i data-lucide="check" class="lucide-icon"></i>' : $i + 1 ?></div>
                            <div class="lesson-title" style="flex:1"><?= e($l['title']) ?></div>
                        </a>
                        <?php else: ?>
                        <div class="lesson-item" style="opacity:.5;cursor:default">
                            <div class="lesson-num"><i data-lucide="lock" class="lucide-icon"></i></div>
                            <div class="lesson-title" style="flex:1"><?= e($l['title']) ?></div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div></div>
        </div>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
