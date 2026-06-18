<?php
require_once __DIR__ . '/db.php';
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT c.*, u.name AS teacher_name, u.qualification, s.name AS subject_name, s.icon AS subject_icon
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
$lessons->execute([$id]);
$lessons = $lessons->fetchAll();

$studentCount = $pdo->prepare('SELECT COUNT(*) c FROM enrollments WHERE course_id = ?');
$studentCount->execute([$id]);
$studentCount = $studentCount->fetch()['c'];

$isEnrolled = false;
$completedLessons = [];
if ($user && $user['role'] === 'student') {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $id]);
    $isEnrolled = (bool) $e->fetch();

    if ($isEnrolled) {
        $cp = $pdo->prepare('SELECT lesson_id FROM lesson_progress WHERE student_id = ?');
        $cp->execute([$user['id']]);
        $completedLessons = array_column($cp->fetchAll(), 'lesson_id');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll'])) {
    requireAuth();
    verifyCsrf();
    if ($user['role'] === 'student' && !$isEnrolled) {
        $pdo->prepare('INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)')->execute([$user['id'], $id]);
        flash('success', 'Enrolled successfully! Start learning below.');
        redirect('course.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_lesson'])) {
    requireAuth();
    verifyCsrf();
    $lid = (int) $_POST['complete_lesson'];
    if ($isEnrolled) {
        $pdo->prepare('INSERT IGNORE INTO lesson_progress (student_id, lesson_id) VALUES (?, ?)')->execute([$user['id'], $lid]);
        redirect('course.php?id=' . $id);
    }
}

$progressPct = $lessons ? (int) round(count($completedLessons) / count($lessons) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($course['title']) ?> — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <div class="nav-brand">🕌 <?= e(SITE_NAME) ?></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <?php if ($user): ?><a href="dashboard.php">Dashboard</a><a href="logout.php" class="nav-btn">Logout</a>
        <?php else: ?><a href="login.php" class="nav-btn">Login</a><?php endif; ?>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:900px">
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <div class="card">
        <div class="course-cover" style="height:200px;font-size:5rem"><?= e($course['subject_icon'] ?: '📖') ?></div>
        <div class="card-body">
            <div style="display:flex;gap:.6rem;margin-bottom:.6rem;flex-wrap:wrap">
                <span class="badge badge-<?= e($course['level']) ?>"><?= e(ucfirst($course['level'])) ?></span>
                <span class="badge <?= $course['price'] == 0 ? 'badge-free' : 'badge-paid' ?>"><?= $course['price'] > 0 ? 'Rs ' . number_format((float) $course['price']) : 'Free' ?></span>
                <span class="badge" style="background:#f5f5f5;color:#555"><?= e($course['language']) ?></span>
            </div>
            <h1 style="font-size:1.5rem;margin-bottom:.6rem"><?= e($course['title']) ?></h1>
            <p style="color:var(--text-mid);margin-bottom:1rem"><?= e($course['description']) ?></p>

            <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--cream);border-radius:var(--radius-sm)">
                <div class="profile-avatar" style="width:48px;height:48px;font-size:1.1rem;margin:0;background:var(--gold)"><?= e(mb_substr($course['teacher_name'], 0, 1)) ?></div>
                <div>
                    <div style="font-weight:600"><?= e($course['teacher_name']) ?></div>
                    <div style="font-size:.82rem;color:var(--text-light)"><?= e($course['qualification'] ?: 'Qualified Teacher') ?></div>
                </div>
                <div style="margin-left:auto;font-size:.85rem;color:var(--text-light)">🎓 <?= (int) $studentCount ?> students enrolled</div>
            </div>
        </div>

        <div class="card-footer">
            <?php if (!$user): ?>
                <a href="login.php" class="btn btn-primary btn-full">Login to Enroll</a>
            <?php elseif ($user['role'] !== 'student'): ?>
                <div class="alert alert-info">Only students can enroll in courses.</div>
            <?php elseif ($isEnrolled): ?>
                <div class="alert alert-success">✓ You are enrolled in this course</div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $progressPct ?>%"></div></div>
                <p style="font-size:.85rem;color:var(--text-light);margin-top:.4rem"><?= $progressPct ?>% complete (<?= count($completedLessons) ?>/<?= count($lessons) ?> lessons)</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" name="enroll" value="1" class="btn btn-primary btn-full">Enroll Now <?= $course['price'] > 0 ? '— Rs ' . number_format((float) $course['price']) : '— Free' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <h3 style="margin:1.8rem 0 1rem;font-size:1.2rem;color:var(--green-deep)">📋 Lessons (<?= count($lessons) ?>)</h3>
    <div class="card">
        <ul class="lesson-list">
            <?php foreach ($lessons as $i => $l): ?>
                <?php $done = in_array($l['id'], $completedLessons); ?>
                <li class="lesson-item <?= $done ? 'done' : '' ?>">
                    <div class="lesson-num"><?= $done ? '✓' : $i + 1 ?></div>
                    <div style="flex:1">
                        <div class="lesson-title"><?= e($l['title']) ?></div>
                    </div>
                    <?php if ($isEnrolled && !$done): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="complete_lesson" value="<?= (int) $l['id'] ?>" class="btn btn-sm btn-outline">Mark Done</button>
                        </form>
                    <?php elseif ($done): ?>
                        <span class="lesson-check">✓</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
</body>
</html>
