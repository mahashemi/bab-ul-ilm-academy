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
            (SELECT COUNT(*) FROM lessons WHERE course_id = ?) AS lesson_count,
            (SELECT COUNT(*) FROM class_messages cm WHERE cm.course_id = ? AND cm.sender_id = u.id AND cm.is_deleted = 0) AS message_count,
            (SELECT MAX(created_at) FROM class_messages cm WHERE cm.course_id = ? AND cm.sender_id = u.id) AS last_message_at,
            (SELECT COALESCE(score, 0) FROM student_behavior_scores sbs WHERE sbs.course_id = ? AND sbs.student_id = u.id) AS behavior_score,
            (SELECT COUNT(*) FROM message_flags mf JOIN class_messages cm2 ON cm2.id = mf.message_id WHERE cm2.course_id = ? AND cm2.sender_id = u.id) AS flag_count
     FROM enrollments e
     JOIN users u ON u.id = e.student_id
     WHERE e.course_id = ?
     ORDER BY e.enrolled_at DESC"
);
$stmt->execute([$id, $id, $id, $id, $id, $id, $id]);
$students = $stmt->fetchAll();

// Class-wide report: real volume/engagement stats, not a fabricated "AI mood" reading.
$last24h = $pdo->prepare("SELECT COUNT(*) FROM class_messages WHERE course_id = ? AND created_at >= NOW() - INTERVAL 1 DAY AND is_deleted = 0");
$last24h->execute([$id]);
$messagesLast24h = (int) $last24h->fetchColumn();

$activeCount = count(array_filter($students, fn($s) => $s['last_message_at'] && strtotime($s['last_message_at']) >= strtotime('-7 days')));
$inactiveCount = count($students) - $activeCount;
$pendingFlagsCount = $pdo->prepare(
    "SELECT COUNT(*) FROM message_flags WHERE status = 'pending' AND message_id IN (SELECT id FROM class_messages WHERE course_id = ?)"
);
$pendingFlagsCount->execute([$id]);
$pendingFlagsCount = (int) $pendingFlagsCount->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Students — <?= e($course['title']) ?> — <?= e(SITE_NAME) ?></title>
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
    <div class="dashboard-header">
        <h2><i data-lucide="graduation-cap" class="lucide-icon"></i> Enrolled Students</h2>
        <p><?= e($course['title']) ?><?= $course['subject_name'] ? ' · ' . e($course['subject_name']) : '' ?> — <?= count($students) ?> student(s)</p>
        <a href="class-chat.php?course_id=<?= (int) $course['id'] ?>" class="dashboard-header-link" style="display:inline-block;margin-top:.4rem"><i data-lucide="message-circle" class="lucide-icon"></i> Open Class Discussion</a>
    </div>

    <h3 style="font-size:1.05rem;color:var(--green-deep);margin-bottom:1rem"><i data-lucide="bar-chart-3" class="lucide-icon"></i> Class Report</h3>
    <div class="stat-cards" style="margin-bottom:2rem">
        <div class="stat-card"><div class="num"><?= $messagesLast24h ?></div><div class="lbl">Messages (24h)</div></div>
        <div class="stat-card"><div class="num"><?= $activeCount ?></div><div class="lbl">Active (7 days)</div></div>
        <div class="stat-card"><div class="num"><?= $inactiveCount ?></div><div class="lbl">Inactive (7+ days)</div></div>
        <div class="stat-card"><div class="num"><?= $pendingFlagsCount ?></div><div class="lbl">Flags Pending Review</div></div>
    </div>

    <?php if (!$students): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="graduation-cap" class="lucide-icon"></i></div><h3>No students enrolled yet</h3></div>
    <?php else: ?>
    <p style="font-size:.78rem;color:var(--text-light);margin-bottom:1rem"><i data-lucide="lock" class="lucide-icon"></i> Behavior scores below are internal — only you (and admins) can see them. Students never see this.</p>
    <div class="grid-2">
        <?php foreach ($students as $s): ?>
        <?php $pct = $s['lesson_count'] ? (int) round($s['completed_count'] / $s['lesson_count'] * 100) : 0; ?>
        <?php $isActive = $s['last_message_at'] && strtotime($s['last_message_at']) >= strtotime('-7 days'); ?>
        <div class="student-card">
            <div class="student-avatar"><?= e(mb_substr($s['name'], 0, 1)) ?></div>
            <div class="student-info">
                <div class="student-name"><?= e($s['name']) ?></div>
                <div class="student-meta"><?= e($s['country'] ?: 'Country not set') ?> · Enrolled <?= date('M j, Y', strtotime($s['enrolled_at'])) ?></div>
                <div class="student-meta"><?= $pct ?>% complete (<?= (int) $s['completed_count'] ?>/<?= (int) $s['lesson_count'] ?> lessons)</div>
                <div class="student-meta">
                    <span class="badge <?= $isActive ? 'badge-free' : 'badge-paid' ?>" style="font-size:.65rem"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                    <?= (int) $s['message_count'] ?> class message<?= $s['message_count'] == 1 ? '' : 's' ?>
                    <?php if ((int) $s['flag_count'] > 0): ?> · <span style="color:#e65100"><?= (int) $s['flag_count'] ?> flagged</span><?php endif; ?>
                    · Behavior score: <strong style="color:<?= $s['behavior_score'] < 0 ? '#c62828' : 'var(--green-deep)' ?>"><?= (int) $s['behavior_score'] ?></strong>
                </div>
            </div>
            <a href="chat.php?with=<?= (int) $s['id'] ?>&course=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline">Message</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
