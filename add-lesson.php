<?php
require_once __DIR__ . '/db.php';
requireRole('teacher');
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$stmt->execute([$courseId, $user['id']]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found or not yours. <a href="dashboard.php">Go back</a></p>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    verifyCsrf();
    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');

    if (mb_strlen($title) < 3) $errors[] = 'Lesson title must be at least 3 characters.';

    if (!$errors) {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM lessons WHERE course_id = ?');
        $maxOrder->execute([$courseId]);
        $next = (int) $maxOrder->fetch()['m'] + 1;

        $stmt = $pdo->prepare('INSERT INTO lessons (course_id, title, content, video_url, sort_order) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$courseId, $title, $content, $videoUrl, $next]);
        flash('success', 'Lesson added!');
        redirect('add-lesson.php?course_id=' . $courseId);
    }
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC');
$lessons->execute([$courseId]);
$lessons = $lessons->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Lesson — <?= e($course['title']) ?></title>
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

<div class="dashboard-wrap">
    <div class="dashboard-header"><h2><i data-lucide="clipboard-list" class="lucide-icon"></i> Lessons — <?= e($course['title']) ?></h2><p>Add lessons in the order students should learn them.</p></div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">

            <div class="form-group">
                <label class="form-label">Lesson Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Introduction to Makharij" required>
            </div>
            <div class="form-group">
                <label class="form-label">Lesson Content</label>
                <textarea name="content" class="form-control" placeholder="Write the lesson text/notes here..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Video URL (optional, YouTube/Vimeo embed link)</label>
                <input type="text" name="video_url" class="form-control" placeholder="https://www.youtube.com/embed/...">
            </div>
            <button type="submit" class="btn btn-primary">+ Add Lesson</button>
        </form>
    </div></div>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Current Lessons (<?= count($lessons) ?>)</h3>
    <div class="card">
        <?php if (!$lessons): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="notebook-pen" class="lucide-icon"></i></div><h3>No lessons yet</h3></div>
        <?php else: ?>
        <ul class="lesson-list">
            <?php foreach ($lessons as $i => $l): ?>
            <li class="lesson-item">
                <div class="lesson-num"><?= $i + 1 ?></div>
                <div class="lesson-title"><?= e($l['title']) ?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <p style="margin-top:1.5rem"><a href="course.php?id=<?= (int) $courseId ?>" class="btn btn-outline">View Course Page <i data-lucide="arrow-right" class="lucide-icon"></i></a></p>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
