<?php
require_once __DIR__ . '/db.php';
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT c.*, u.name AS teacher_name, u.qualification, s.name AS subject_name, s.icon AS subject_icon,
            e.name AS editor_name, e.role AS editor_role
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     LEFT JOIN users e ON e.id = c.updated_by
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isOwnerOrAdmin = $user && ((int) $course['teacher_id'] === (int) $user['id'] || ($user['role'] ?? '') === 'admin');
if ($course['moderation_status'] !== 'approved' && !$isOwnerOrAdmin) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">This course is awaiting admin review and isn\'t public yet. <a href="courses.php">Go back</a></p>');
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
$lessons->execute([$id]);
$lessons = $lessons->fetchAll();

$studentCount = $pdo->prepare('SELECT COUNT(*) c FROM enrollments WHERE course_id = ?');
$studentCount->execute([$id]);
$studentCount = $studentCount->fetch()['c'];

$isEnrolled = false;
$completedLessons = [];
if ($user && ($user['role'] ?? '') === 'student') {
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
    if (($user['role'] ?? '') === 'student' && !$isEnrolled) {
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

<div class="dashboard-wrap" style="max-width:900px">
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <div class="card">
        <div class="course-cover" style="height:200px;font-size:5rem">
            <?php if ($course['cover_url']): ?><img src="<?= e($course['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($course['subject_icon']) ?><?php endif; ?>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:.6rem;margin-bottom:.6rem;flex-wrap:wrap">
                <span class="badge badge-<?= e($course['level']) ?>"><?= e(ucfirst($course['level'])) ?></span>
                <span class="badge <?= $course['price'] == 0 ? 'badge-free' : 'badge-paid' ?>"><?= $course['price'] > 0 ? '$' . number_format((float) $course['price']) : 'Free' ?></span>
                <span class="badge" style="background:#f5f5f5;color:#555"><?= e($course['language']) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
                <h1 style="font-size:1.5rem;margin-bottom:.6rem"><?= e($course['title']) ?></h1>
                <?php if ($isOwnerOrAdmin): ?>
                    <a href="edit-course.php?id=<?= $id ?>" class="btn btn-sm btn-outline"><i data-lucide="pencil" class="lucide-icon"></i> Edit</a>
                <?php endif; ?>
            </div>
            <?php if ($isOwnerOrAdmin && $course['moderation_status'] !== 'approved'): ?>
                <div class="alert <?= $course['moderation_status'] === 'rejected' ? 'alert-error' : 'alert-info' ?>" style="margin-bottom:1rem">
                    <?= $course['moderation_status'] === 'rejected' ? '<i data-lucide="ban" class="lucide-icon"></i> This course was rejected by an admin and is not visible to students.' : '<i data-lucide="clock" class="lucide-icon"></i> This course is awaiting admin review and is not yet visible to students.' ?>
                </div>
            <?php endif; ?>
            <p style="color:var(--text-mid);margin-bottom:1rem"><?= e($course['description']) ?></p>
            <?php if ($course['editor_name']): ?>
                <div style="font-size:.78rem;color:var(--text-light);margin-bottom:1rem">
                    Last edited by <?= e($course['editor_name']) ?><?= $course['editor_role'] === 'admin' ? ' (Admin)' : '' ?>
                    on <?= date('M j, Y', strtotime($course['updated_at'])) ?>
                </div>
            <?php endif; ?>

            <div style="display:flex;align-items:center;gap:1rem;padding:1rem;background:var(--cream);border-radius:var(--radius-sm)">
                <div class="profile-avatar" style="width:48px;height:48px;font-size:1.1rem;margin:0;background:var(--gold)"><?= e(mb_substr($course['teacher_name'], 0, 1)) ?></div>
                <div>
                    <div style="font-weight:600"><?= e($course['teacher_name']) ?></div>
                    <div style="font-size:.82rem;color:var(--text-light)"><?= e($course['qualification'] ?: 'Qualified Teacher') ?></div>
                </div>
                <div style="margin-left:auto;display:flex;align-items:center;gap:1rem">
                    <span style="font-size:.85rem;color:var(--text-light)"><i data-lucide="graduation-cap" class="lucide-icon"></i> <?= (int) $studentCount ?> students enrolled</span>
                    <?php if ($isEnrolled): ?>
                        <a href="chat.php?with=<?= (int) $course['teacher_id'] ?>&course=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="message-circle" class="lucide-icon"></i> Message Teacher</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card-footer">
            <?php if (!$user): ?>
                <a href="login.php" class="btn btn-primary btn-full">Login to Enroll</a>
            <?php elseif (($user['role'] ?? '') !== 'student'): ?>
                <div class="alert alert-info">Only students can enroll in courses.</div>
            <?php elseif ($isEnrolled): ?>
                <div class="alert alert-success"><i data-lucide="check" class="lucide-icon"></i> You are enrolled in this course</div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $progressPct ?>%"></div></div>
                <p style="font-size:.85rem;color:var(--text-light);margin-top:.4rem"><?= $progressPct ?>% complete (<?= count($completedLessons) ?>/<?= count($lessons) ?> lessons)</p>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" name="enroll" value="1" class="btn btn-primary btn-full">Enroll Now <?= $course['price'] > 0 ? '— $' . number_format((float) $course['price']) : '— Free' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <h3 style="margin:1.8rem 0 1rem;font-size:1.2rem;color:var(--green-deep)"><i data-lucide="clipboard-list" class="lucide-icon"></i> Lessons (<?= count($lessons) ?>)</h3>
    <div class="card">
        <ul class="lesson-list">
            <?php foreach ($lessons as $i => $l): ?>
                <?php $done = in_array($l['id'], $completedLessons); ?>
                <li class="lesson-item <?= $done ? 'done' : '' ?>">
                    <div class="lesson-num"><?= $done ? '<i data-lucide="check" class="lucide-icon"></i>' : $i + 1 ?></div>
                    <div style="flex:1">
                        <div class="lesson-title"><?= e($l['title']) ?></div>
                    </div>
                    <?php if ($isEnrolled && !$done): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="complete_lesson" value="<?= (int) $l['id'] ?>" class="btn btn-sm btn-outline">Mark Done</button>
                        </form>
                    <?php elseif ($done): ?>
                        <span class="lesson-check"><i data-lucide="check" class="lucide-icon"></i></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
