<?php
require_once __DIR__ . '/db.php';
$teacherId = requireTeacherOrSupport();
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$stmt->execute([$courseId, $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found or not yours. <a href="dashboard.php">Go back</a></p>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $title = trim($_POST['title'] ?? '');
    $passingScore = max(1, min(100, (int) ($_POST['passing_score'] ?? 60)));

    if (mb_strlen($title) < 3) $errors[] = 'Quiz title must be at least 3 characters.';

    if (!$errors) {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM quizzes WHERE course_id = ?');
        $maxOrder->execute([$courseId]);
        $next = (int) $maxOrder->fetch()['m'] + 1;
        $pdo->prepare('INSERT INTO quizzes (course_id, title, passing_score, sort_order) VALUES (?, ?, ?, ?)')
            ->execute([$courseId, $title, $passingScore, $next]);
        $newId = (int) $pdo->lastInsertId();
        flash('success', 'Quiz created! Now add some questions.');
        redirect('edit-quiz.php?id=' . $newId);
    }
}

$quizzes = $pdo->prepare(
    "SELECT q.*, (SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = q.id) AS question_count,
            (SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = q.id) AS attempt_count
     FROM quizzes q WHERE q.course_id = ? ORDER BY q.sort_order ASC"
);
$quizzes->execute([$courseId]);
$quizzes = $quizzes->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quizzes — <?= e($course['title']) ?></title>
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
        <input type="text" name="q" placeholder="<?= e(t('nav_search_placeholder')) ?>">
    </form>
    <div class="nav-links">
        <a href="index.php"><?= t('nav_home') ?></a>
        <a href="courses.php"><?= t('nav_courses') ?></a>
        <a href="about.php"><?= t('nav_about') ?></a>
        <a href="feedback.php"><?= t('nav_feedback') ?></a>
        <div class="nav-account">
            <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="<?= e(t('nav_language')) ?>">
                <i data-lucide="globe" class="lucide-icon"></i>
            </button>
            <div class="nav-account-menu">
                <a href="set-language.php?lang=en&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">English</a>
                <a href="set-language.php?lang=ur&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">اردو</a>
                <a href="set-language.php?lang=fa&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">فارسی</a>
                <a href="set-language.php?lang=ar&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">العربية</a>
            </div>
        </div>

        <a href="chat.php"><?= t('nav_messages') ?></a>
        <a href="add-course.php"><?= t('nav_new_course') ?></a>
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
                <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> <?= t('nav_dashboard') ?></a>
                <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> <?= t('nav_messages') ?></a>
                <a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> <?= t('nav_new_course_plain') ?></a>
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="edit-course.php?id=<?= $courseId ?>&step=curriculum"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Edit Course</a></p>
    <div class="dashboard-header"><h2><i data-lucide="list-checks" class="lucide-icon"></i> Quizzes — <?= e($course['title']) ?></h2><p>Test student understanding after a section or at the end of the course.</p></div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">+ New Quiz</h3>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Quiz Title</label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Week 1 Quiz" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Passing Score (%)</label>
                    <input type="number" name="passing_score" class="form-control" min="1" max="100" value="60">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Create Quiz</button>
        </form>
    </div></div>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Current Quizzes (<?= count($quizzes) ?>)</h3>
    <div class="card">
        <?php if (!$quizzes): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="list-checks" class="lucide-icon"></i></div><h3>No quizzes yet</h3></div>
        <?php else: ?>
        <ul class="lesson-list">
            <?php foreach ($quizzes as $q): ?>
            <li class="lesson-item">
                <div class="lesson-title" style="flex:1"><a href="edit-quiz.php?id=<?= (int) $q['id'] ?>"><?= e($q['title']) ?></a></div>
                <span style="font-size:.78rem;color:var(--text-light)"><?= (int) $q['question_count'] ?> question<?= $q['question_count'] == 1 ? '' : 's' ?></span>
                <span style="font-size:.78rem;color:var(--text-light)"><?= (int) $q['attempt_count'] ?> attempt<?= $q['attempt_count'] == 1 ? '' : 's' ?></span>
                <span style="font-size:.78rem;color:var(--text-light)">Pass: <?= (int) $q['passing_score'] ?>%</span>
                <a href="edit-quiz.php?id=<?= (int) $q['id'] ?>" class="icon-btn" data-tip="Edit quiz" aria-label="Edit quiz"><i data-lucide="pencil" class="lucide-icon"></i></a>
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
