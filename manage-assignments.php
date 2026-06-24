<?php
require_once __DIR__ . '/db.php';
requireRole('teacher');
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$stmt->execute([$courseId, $user['id']]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found or not yours. <a href="dashboard.php">Go back</a></p>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');

    if (mb_strlen($title) < 3) $errors[] = 'Assignment title must be at least 3 characters.';

    if (!$errors) {
        $pdo->prepare('INSERT INTO assignments (course_id, title, description, due_date) VALUES (?, ?, ?, ?)')
            ->execute([$courseId, $title, $description ?: null, $dueDate ?: null]);
        flash('success', 'Assignment created.');
        redirect('manage-assignments.php?course_id=' . $courseId);
    }
}

$assignments = $pdo->prepare(
    "SELECT a.*, (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id) AS submission_count,
            (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = a.id AND grade IS NOT NULL) AS graded_count
     FROM assignments a WHERE a.course_id = ? ORDER BY a.created_at DESC"
);
$assignments->execute([$courseId]);
$assignments = $assignments->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assignments — <?= e($course['title']) ?></title>
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
        <a href="chat.php">Messages</a>
        <a href="add-course.php">+ New Course</a>
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
                <a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a>
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="edit-course.php?id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Edit Course</a></p>
    <div class="dashboard-header"><h2><i data-lucide="file-edit" class="lucide-icon"></i> Assignments — <?= e($course['title']) ?></h2><p>Give students practical work to apply what they've learned.</p></div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">+ New Assignment</h3>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="form-group">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Write a 500-word reflection on Surah Al-Fatiha" required>
            </div>
            <div class="form-group">
                <label class="form-label">Instructions</label>
                <textarea name="description" class="form-control" placeholder="Describe what students need to submit..."></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Due Date (optional)</label>
                <input type="date" name="due_date" class="form-control" min="<?= date('Y-m-d') ?>">
            </div>
            <button type="submit" class="btn btn-primary">Create Assignment</button>
        </form>
    </div></div>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Current Assignments (<?= count($assignments) ?>)</h3>
    <div class="card">
        <?php if (!$assignments): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="file-edit" class="lucide-icon"></i></div><h3>No assignments yet</h3></div>
        <?php else: ?>
        <ul class="lesson-list">
            <?php foreach ($assignments as $a): ?>
            <li class="lesson-item">
                <div class="lesson-title" style="flex:1"><a href="assignment.php?id=<?= (int) $a['id'] ?>"><?= e($a['title']) ?></a></div>
                <?php if ($a['due_date']): ?><span style="font-size:.78rem;color:var(--text-light)">Due <?= e(date('M j', strtotime($a['due_date']))) ?></span><?php endif; ?>
                <span style="font-size:.78rem;color:var(--text-light)"><?= (int) $a['graded_count'] ?>/<?= (int) $a['submission_count'] ?> graded</span>
                <a href="assignment.php?id=<?= (int) $a['id'] ?>" class="icon-btn" data-tip="View submissions" aria-label="View submissions"><i data-lucide="arrow-right" class="lucide-icon"></i></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
