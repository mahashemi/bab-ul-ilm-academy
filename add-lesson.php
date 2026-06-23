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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_lesson'])) {
    verifyCsrf();
    $lid = (int) $_POST['move_lesson'];
    $dir = $_POST['direction'] ?? '';
    $current = $pdo->prepare('SELECT id, sort_order FROM lessons WHERE id = ? AND course_id = ?');
    $current->execute([$lid, $courseId]);
    $current = $current->fetch();
    if ($current) {
        $neighbor = $pdo->prepare(
            $dir === 'up'
                ? 'SELECT id, sort_order FROM lessons WHERE course_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1'
                : 'SELECT id, sort_order FROM lessons WHERE course_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1'
        );
        $neighbor->execute([$courseId, $current['sort_order']]);
        $neighbor = $neighbor->fetch();
        if ($neighbor) {
            $pdo->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$neighbor['sort_order'], $current['id']]);
            $pdo->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$current['sort_order'], $neighbor['id']]);
        }
    }
    redirect('add-lesson.php?course_id=' . $courseId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    verifyCsrf();
    $sectionTitle = trim($_POST['section_title'] ?? '');
    $title   = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $duration = (int) ($_POST['duration_minutes'] ?? 0);
    $isPreview = isset($_POST['is_preview']) ? 1 : 0;

    if (mb_strlen($title) < 3) $errors[] = 'Lesson title must be at least 3 characters.';

    if (!$errors) {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM lessons WHERE course_id = ?');
        $maxOrder->execute([$courseId]);
        $next = (int) $maxOrder->fetch()['m'] + 1;

        $stmt = $pdo->prepare('INSERT INTO lessons (course_id, section_title, title, content, video_url, duration_minutes, is_preview, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$courseId, $sectionTitle ?: null, $title, $content, $videoUrl, $duration, $isPreview, $next]);
        flash('success', 'Lesson added!');
        redirect('add-lesson.php?course_id=' . $courseId);
    }
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC');
$lessons->execute([$courseId]);
$lessons = $lessons->fetchAll();

$existingSections = $pdo->prepare('SELECT DISTINCT section_title FROM lessons WHERE course_id = ? AND section_title IS NOT NULL AND section_title != \'\' ORDER BY sort_order ASC');
$existingSections->execute([$courseId]);
$existingSections = $existingSections->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Add Lesson — <?= e($course['title']) ?></title>
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
    <div class="dashboard-header"><h2><i data-lucide="clipboard-list" class="lucide-icon"></i> Lessons — <?= e($course['title']) ?></h2><p>Add lessons in the order students should learn them.</p></div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">

            <div class="form-group">
                <label class="form-label">Section (optional — groups lessons into a curriculum section)</label>
                <input type="text" name="section_title" class="form-control" list="sectionSuggestions" placeholder="e.g. Getting Started">
                <datalist id="sectionSuggestions">
                    <?php foreach ($existingSections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label class="form-label">Lesson Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Introduction to Makharij" required>
            </div>
            <div class="form-group">
                <label class="form-label">Lesson Content</label>
                <textarea name="content" class="form-control" placeholder="Write the lesson text/notes here..."></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Video URL (optional, YouTube/Vimeo embed link)</label>
                    <input type="text" name="video_url" class="form-control" placeholder="https://www.youtube.com/embed/...">
                </div>
                <div class="form-group">
                    <label class="form-label">Duration (minutes)</label>
                    <input type="number" name="duration_minutes" class="form-control" min="0" placeholder="e.g. 12">
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="is_preview" value="1" style="width:auto">
                    Free preview — visible to everyone, even without enrolling
                </label>
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
            <?php $lastSection = null; ?>
            <?php foreach ($lessons as $i => $l): ?>
                <?php if ($l['section_title'] && $l['section_title'] !== $lastSection): $lastSection = $l['section_title']; ?>
                    <li style="padding:.6rem 1rem;font-weight:700;font-size:.85rem;color:var(--green-deep);background:var(--cream)"><?= e($l['section_title']) ?></li>
                <?php endif; ?>
            <li class="lesson-item">
                <div class="lesson-num"><?= $i + 1 ?></div>
                <div class="lesson-title" style="flex:1"><a href="edit-lesson.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a></div>
                <?php if ((int) $l['duration_minutes'] > 0): ?><span style="font-size:.78rem;color:var(--text-light)"><?= (int) $l['duration_minutes'] ?> min</span><?php endif; ?>
                <?php if ($l['is_preview']): ?><span class="badge badge-free" style="margin-left:.5rem">Preview</span><?php endif; ?>
                <div class="action-row" style="margin-left:.6rem">
                    <?php if ($i > 0): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="direction" value="up"><button type="submit" name="move_lesson" value="<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Move up" aria-label="Move up"><i data-lucide="chevron-up" class="lucide-icon"></i></button></form>
                    <?php endif; ?>
                    <?php if ($i < count($lessons) - 1): ?>
                    <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="direction" value="down"><button type="submit" name="move_lesson" value="<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Move down" aria-label="Move down"><i data-lucide="chevron-down" class="lucide-icon"></i></button></form>
                    <?php endif; ?>
                    <a href="edit-lesson.php?id=<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Edit lesson" aria-label="Edit lesson"><i data-lucide="pencil" class="lucide-icon"></i></a>
                </div>
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
