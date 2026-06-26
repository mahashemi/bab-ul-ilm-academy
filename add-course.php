<?php
require_once __DIR__ . '/db.php';
$teacherId = requireTeacherOrSupport();
$user = auth();

$fields = $pdo->query(
    "SELECT f.id AS field_id, f.name AS field_name, f.icon AS field_icon, s.id AS subject_id, s.name AS subject_name, s.icon AS subject_icon
     FROM fields_of_study f LEFT JOIN subjects s ON s.field_of_study_id = f.id
     ORDER BY f.id, s.name"
)->fetchAll();
$grouped = [];
foreach ($fields as $row) {
    $grouped[$row['field_id']]['label'] = $row['field_name'];
    if ($row['subject_id']) {
        $grouped[$row['field_id']]['subjects'][] = $row;
    }
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = trim($_POST['title'] ?? '');
    $subjectId   = (int) ($_POST['subject_id'] ?? 0);
    $level       = $_POST['level'] ?? 'beginner';
    $language    = trim($_POST['language'] ?? 'English');

    if (mb_strlen($title) < 5) $errors[] = 'Title must be at least 5 characters.';
    if (!in_array($level, ['beginner','intermediate','advanced'], true)) $errors[] = 'Invalid level.';

    if (!$errors) {
        // Created as a minimal draft the moment Basics is saved -- every later
        // step (Details, Cover, Curriculum, Pricing, Publish) edits this same
        // row instead of needing the whole course described in one go.
        // New courses always start unpublished + 'pending' moderation -- not
        // visible to students until both the teacher publishes AND an admin
        // approves, regardless of how far through the wizard they are.
        $stmt = $pdo->prepare(
            "INSERT INTO courses (teacher_id, subject_id, title, level, language, is_published, moderation_status)
             VALUES (?, ?, ?, ?, ?, 0, 'pending')"
        );
        $stmt->execute([$teacherId, $subjectId ?: null, $title, $level, $language]);
        $newId = (int) $pdo->lastInsertId();
        if ($teacherId !== (int) $user['id']) {
            logActivity($pdo, $user['id'], 'Created course #' . $newId . ' on behalf of teacher #' . $teacherId);
        }
        flash('success', 'Basics saved. Next, add a description and what learners will gain.');
        redirect('edit-course.php?id=' . $newId . '&step=details');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Course — <?= e(SITE_NAME) ?></title>
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
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
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
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> Become an Instructor</a><?php endif; ?>
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
    <div class="dashboard-header"><h2><i data-lucide="book-open" class="lucide-icon"></i> Create a New Course</h2><p>Step 1: the basics. New to this? <a href="tutorial.php" style="color:var(--gold);text-decoration:underline">See the step-by-step tutorial</a>.</p></div>

    <?= renderActingAsBanner($pdo) ?>

    <div class="course-wizard-layout">
    <?= renderCourseWizardSidebar(null, 'basics') ?>
    <div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Course Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Tajweed for Beginners" value="<?= e($_POST['title'] ?? '') ?>" required>
                <div class="form-hint">A working title is fine — you can change it any time.</div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <select name="subject_id" class="form-control">
                        <option value="">Select subject</option>
                        <?php foreach ($grouped as $g): ?>
                            <?php if (!empty($g['subjects'])): ?>
                            <optgroup label="<?= e($g['label']) ?>">
                                <?php foreach ($g['subjects'] as $s): ?>
                                    <option value="<?= (int) $s['subject_id'] ?>"><?= e($s['subject_name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Level</label>
                    <select name="level" class="form-control">
                        <option value="beginner">Beginner</option>
                        <option value="intermediate">Intermediate</option>
                        <option value="advanced">Advanced</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Language</label>
                <input type="text" name="language" class="form-control" value="English" placeholder="English">
            </div>

            <button type="submit" class="btn btn-primary btn-full">Save &amp; Continue</button>
        </form>
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
