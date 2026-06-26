<?php
require_once __DIR__ . '/db.php';
requireRole('teacher');
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT l.*, c.title AS course_title, c.teacher_id FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.id = ?'
);
$stmt->execute([$id]);
$lesson = $stmt->fetch();

if (!$lesson || (int) $lesson['teacher_id'] !== (int) $user['id']) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Lesson not found or not yours. <a href="dashboard.php">Go back</a></p>');
}
$courseId = (int) $lesson['course_id'];

$existingSections = $pdo->prepare('SELECT DISTINCT section_title FROM lessons WHERE course_id = ? AND section_title IS NOT NULL AND section_title != \'\' ORDER BY sort_order ASC');
$existingSections->execute([$courseId]);
$existingSections = $existingSections->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete_lesson'])) {
        $pdo->prepare('DELETE FROM lessons WHERE id = ?')->execute([$id]);
        flash('success', 'Lesson deleted.');
        redirect('add-lesson.php?course_id=' . $courseId);
    }

    $sectionTitle = trim($_POST['section_title'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $content  = trim($_POST['content'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $duration = (int) ($_POST['duration_minutes'] ?? 0);
    $isPreview = isset($_POST['is_preview']) ? 1 : 0;

    if (mb_strlen($title) < 3) $errors[] = 'Lesson title must be at least 3 characters.';

    if (!$errors) {
        $pdo->prepare(
            'UPDATE lessons SET section_title=?, title=?, content=?, video_url=?, duration_minutes=?, is_preview=? WHERE id=?'
        )->execute([$sectionTitle ?: null, $title, $content, $videoUrl, $duration, $isPreview, $id]);
        flash('success', 'Lesson updated.');
        redirect('add-lesson.php?course_id=' . $courseId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Lesson — <?= e($lesson['course_title']) ?></title>
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

<div class="dashboard-wrap">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="add-lesson.php?course_id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Lessons</a></p>
    <div class="dashboard-header"><h2><i data-lucide="pencil" class="lucide-icon"></i> Edit Lesson</h2><p><?= e($lesson['course_title']) ?></p></div>

    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Section <span style="font-weight:400;font-size:.78rem;color:var(--text-light)">(groups lessons into a curriculum section — Week 1, Week 2... works well)</span></label>
                <input type="text" name="section_title" class="form-control" list="sectionSuggestions" placeholder="e.g. Week 1: Introduction" value="<?= e($lesson['section_title'] ?? '') ?>">
                <datalist id="sectionSuggestions">
                    <?php foreach ($existingSections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label class="form-label">Lesson Title</label>
                <input type="text" name="title" class="form-control" value="<?= e($lesson['title']) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Lesson Content</label>
                <textarea name="content" class="form-control" placeholder="Write the lesson text/notes here..."><?= e($lesson['content'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Video URL (optional, YouTube/Vimeo embed link)</label>
                    <input type="text" name="video_url" class="form-control" value="<?= e($lesson['video_url'] ?? '') ?>" placeholder="https://www.youtube.com/embed/...">
                </div>
                <div class="form-group">
                    <label class="form-label">Duration (minutes)</label>
                    <input type="number" name="duration_minutes" class="form-control" min="0" value="<?= (int) $lesson['duration_minutes'] ?>">
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="is_preview" value="1" style="width:auto" <?= $lesson['is_preview'] ? 'checked' : '' ?>>
                    Free preview — visible to everyone, even without enrolling
                </label>
            </div>
            <div style="display:flex;justify-content:space-between;gap:.6rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
        <form method="post" onsubmit="return confirm('Delete this lesson? This cannot be undone.')" style="margin-top:.8rem;border-top:1px solid var(--border);padding-top:.8rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <button type="submit" name="delete_lesson" value="1" class="btn btn-outline" style="color:#c62828;border-color:#c62828"><i data-lucide="trash-2" class="lucide-icon"></i> Delete Lesson</button>
        </form>
    </div></div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
