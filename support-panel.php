<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (!in_array($user['role'] ?? '', ['admin', 'customer_service'], true)) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['select_teacher'])) {
        $teacherId = (int) $_POST['select_teacher'];
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND teacher_status = 'approved'");
        $stmt->execute([$teacherId]);
        if ($stmt->fetchColumn()) {
            $_SESSION['acting_as_teacher_id'] = $teacherId;
            logActivity($pdo, $user['id'], 'Started acting on behalf of teacher #' . $teacherId);
        }
    } elseif (isset($_POST['stop_acting'])) {
        if (!empty($_SESSION['acting_as_teacher_id'])) {
            logActivity($pdo, $user['id'], 'Stopped acting on behalf of teacher #' . (int) $_SESSION['acting_as_teacher_id']);
        }
        unset($_SESSION['acting_as_teacher_id']);
    }
    redirect('support-panel.php');
}

$teachers = $pdo->query(
    "SELECT id, name, display_name, email, (SELECT COUNT(*) FROM courses WHERE teacher_id = users.id) AS course_count
     FROM users WHERE teacher_status = 'approved' ORDER BY name"
)->fetchAll();

$actingAsId = (int) ($_SESSION['acting_as_teacher_id'] ?? 0);
$actingTeacher = null;
$teacherCourses = [];
if ($actingAsId) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$actingAsId]);
    $actingTeacher = $stmt->fetch();
    if ($actingTeacher) {
        $stmt = $pdo->prepare(
            "SELECT c.*, (SELECT COUNT(*) FROM lessons WHERE course_id = c.id) AS lesson_count
             FROM courses c WHERE c.teacher_id = ? ORDER BY c.created_at DESC"
        );
        $stmt->execute([$actingAsId]);
        $teacherCourses = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Support Panel — <?= e(SITE_NAME) ?></title>
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
        <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php">Admin Panel</a><?php endif; ?>
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
                <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> Admin Panel</a><?php endif; ?>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:900px">
    <div class="dashboard-header">
        <h2><i data-lucide="headset" class="lucide-icon"></i> Support Panel</h2>
        <p>Helping a teacher over the phone? Select them below, then create or build out their course on their behalf.</p>
    </div>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>

    <?= renderActingAsBanner($pdo) ?>

    <?php if ($actingTeacher): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem">
            <h3 style="font-size:1.05rem;color:var(--green-deep)"><?= e(displayNameOf($actingTeacher)) ?>'s Courses (<?= count($teacherCourses) ?>)</h3>
            <div style="display:flex;gap:.5rem">
                <a href="single-upload.php" class="btn btn-outline btn-sm"><i data-lucide="upload" class="lucide-icon"></i> Single Upload</a>
                <a href="add-course.php" class="btn btn-primary btn-sm"><i data-lucide="plus" class="lucide-icon"></i> New Course</a>
            </div>
        </div>

        <?php if (!$teacherCourses): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="library" class="lucide-icon"></i></div><h3>No courses yet</h3><p>Start with "+ New Course" or "Single Upload" above.</p></div>
        <?php else: ?>
            <?php foreach ($teacherCourses as $c): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;padding:.9rem 0;border-bottom:1px solid var(--border)">
                <div>
                    <strong><?= e($c['title']) ?></strong>
                    <div style="font-size:.78rem;color:var(--text-light)"><?= (int) $c['lesson_count'] ?> lesson<?= $c['lesson_count'] == 1 ? '' : 's' ?> · <span class="badge badge-<?= $c['moderation_status'] === 'approved' ? 'free' : 'pending' ?>" style="font-size:.68rem"><?= e(ucfirst($c['moderation_status'])) ?></span></div>
                </div>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <a href="add-lesson.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Lessons</a>
                    <a href="bulk-lessons.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Bulk Lessons</a>
                    <a href="bulk-assessments.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Quizzes/Assignments</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">
            <?= $actingTeacher ? 'Switch to a Different Teacher' : 'Select a Teacher' ?>
        </h3>
        <input type="text" id="teacherSearch" class="form-control" placeholder="Search by name or email..." style="margin-bottom:1rem" oninput="filterTeacherList()">
        <div id="teacherListItems">
            <?php foreach ($teachers as $t): ?>
            <form method="post" data-name="<?= e(mb_strtolower($t['name'] . ' ' . $t['email'])) ?>" style="display:flex;justify-content:space-between;align-items:center;padding:.7rem 0;border-bottom:1px solid var(--border)">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div>
                    <strong><?= e(displayNameOf($t)) ?></strong>
                    <div style="font-size:.78rem;color:var(--text-light)"><?= e($t['email']) ?> · <?= (int) $t['course_count'] ?> course<?= $t['course_count'] == 1 ? '' : 's' ?></div>
                </div>
                <button type="submit" name="select_teacher" value="<?= (int) $t['id'] ?>" class="btn <?= $actingAsId === (int) $t['id'] ? 'btn-primary' : 'btn-outline' ?> btn-sm"><?= $actingAsId === (int) $t['id'] ? 'Selected' : 'Select' ?></button>
            </form>
            <?php endforeach; ?>
        </div>
    </div></div>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>
function filterTeacherList() {
    var q = document.getElementById('teacherSearch').value.toLowerCase();
    document.querySelectorAll('#teacherListItems > form').forEach(function (f) {
        f.style.display = f.dataset.name.indexOf(q) !== -1 ? 'flex' : 'none';
    });
}
if (window.lucide) lucide.createIcons();
</script>
</body>
</html>
