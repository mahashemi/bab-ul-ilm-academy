<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name FROM courses c JOIN users u ON u.id = c.teacher_id WHERE c.id = ?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isTeacher = (int) $course['teacher_id'] === (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$canModerate = $isTeacher || $isAdmin;
$isEnrolled = false;
if (!$canModerate) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $courseId]);
    $isEnrolled = (bool) $e->fetch();
}
if (!$canModerate && !$isEnrolled) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You need to be enrolled in this course to view its Q&amp;A. <a href="course.php?id=' . $courseId . '">Go to course page</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['ask_question']) && ($isEnrolled || $canModerate)) {
        $question = trim($_POST['question'] ?? '');
        if (mb_strlen($question) >= 5) {
            $pdo->prepare('INSERT INTO course_questions (course_id, student_id, question) VALUES (?, ?, ?)')
                ->execute([$courseId, $user['id'], $question]);
            $newQId = (int) $pdo->lastInsertId();
            notifyUser($pdo, (int) $course['teacher_id'], 'new_question', $courseId, 15, function ($u) use ($course, $question) {
                return [
                    'New question on "' . $course['title'] . '"',
                    '<p style="margin:0 0 16px">A student asked a question on "' . e($course['title']) . '":</p>'
                        . '<p style="margin:0 0 16px;padding:12px 16px;background:#faf8f4;border-radius:8px;color:#1a1a1a">' . nl2br(e($question)) . '</p>',
                    'Answer Question',
                    siteBaseUrl() . '/course-qa.php?course_id=' . (int) $course['id'],
                ];
            });
            flash('success', 'Your question has been posted.');
        }
        redirect('course-qa.php?course_id=' . $courseId);
    }

    if (isset($_POST['answer_question']) && $canModerate) {
        $qId = (int) $_POST['answer_question'];
        $answer = trim($_POST['answer'] ?? '');
        if ($answer !== '') {
            $pdo->prepare('UPDATE course_questions SET answer = ?, answered_by = ?, answered_at = NOW() WHERE id = ? AND course_id = ?')
                ->execute([$answer, $user['id'], $qId, $courseId]);

            $studentRow = $pdo->prepare('SELECT student_id FROM course_questions WHERE id = ?');
            $studentRow->execute([$qId]);
            $studentId = (int) $studentRow->fetchColumn();
            if ($studentId) {
                notifyUser($pdo, $studentId, 'question_answered', $qId, 1, function ($u) use ($course, $answer) {
                    return [
                        'Your question was answered',
                        '<p style="margin:0 0 16px">Your question on "' . e($course['title']) . '" has an answer:</p>'
                            . '<p style="margin:0 0 16px;padding:12px 16px;background:#faf8f4;border-radius:8px;color:#1a1a1a">' . nl2br(e($answer)) . '</p>',
                        'View Answer',
                        siteBaseUrl() . '/course-qa.php?course_id=' . (int) $course['id'],
                    ];
                });
            }
        }
        redirect('course-qa.php?course_id=' . $courseId);
    }

    if (isset($_POST['delete_question']) && $canModerate) {
        $pdo->prepare('DELETE FROM course_questions WHERE id = ? AND course_id = ?')->execute([(int) $_POST['delete_question'], $courseId]);
        redirect('course-qa.php?course_id=' . $courseId);
    }
}

$questions = $pdo->prepare(
    "SELECT q.*, COALESCE(s.display_name, s.name) AS student_name, COALESCE(a.display_name, a.name) AS answerer_name
     FROM course_questions q JOIN users s ON s.id = q.student_id LEFT JOIN users a ON a.id = q.answered_by
     WHERE q.course_id = ? ORDER BY q.created_at DESC"
);
$questions->execute([$courseId]);
$questions = $questions->fetchAll();
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Q&amp;A — <?= e($course['title']) ?></title>
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
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:800px">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="course.php?id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($course['title']) ?></a></p>
    <div class="dashboard-header"><h2><i data-lucide="circle-help" class="lucide-icon"></i> Questions &amp; Answers</h2><p><?= e($course['title']) ?> · Ask the instructor anything about this course</p></div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <?php if ($isEnrolled): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.6rem">Ask a Question</h3>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <textarea name="question" class="form-control" placeholder="What would you like to ask about this course?" required style="margin-bottom:.6rem"></textarea>
            <button type="submit" name="ask_question" value="1" class="btn btn-primary">Post Question</button>
        </form>
    </div></div>
    <?php endif; ?>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">All Questions (<?= count($questions) ?>)</h3>
    <?php if (!$questions): ?>
        <div class="card"><div class="empty-state"><div class="icon"><i data-lucide="circle-help" class="lucide-icon"></i></div><h3>No questions yet</h3><p>Be the first to ask something about this course.</p></div></div>
    <?php else: ?>
        <?php foreach ($questions as $q): ?>
        <div class="card" style="margin-bottom:1.2rem"><div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;margin-bottom:.6rem">
                <div>
                    <strong><?= e($q['student_name']) ?></strong>
                    <span style="font-size:.78rem;color:var(--text-light)"> · <?= e(date('M j, Y', strtotime($q['created_at']))) ?></span>
                </div>
                <?php if ($canModerate): ?>
                <form method="post" onsubmit="return confirm('Delete this question?')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" name="delete_question" value="<?= (int) $q['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete question" aria-label="Delete question"><i data-lucide="trash-2" class="lucide-icon"></i></button>
                </form>
                <?php endif; ?>
            </div>
            <p style="margin-bottom:.8rem"><?= nl2br(e($q['question'])) ?></p>

            <?php if ($q['answer']): ?>
                <div style="background:var(--cream);border-radius:var(--radius-sm);padding:.8rem 1rem;border-left:3px solid var(--gold)">
                    <strong style="font-size:.85rem;color:var(--green-deep)"><i data-lucide="check-circle-2" class="lucide-icon"></i> <?= e($q['answerer_name']) ?> answered:</strong>
                    <p style="margin-top:.4rem"><?= nl2br(e($q['answer'])) ?></p>
                </div>
            <?php elseif ($canModerate): ?>
                <form method="post" style="display:flex;gap:.6rem;margin-top:.6rem">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <input type="text" name="answer" class="form-control" placeholder="Write an answer..." required>
                    <button type="submit" name="answer_question" value="<?= (int) $q['id'] ?>" class="btn btn-primary btn-sm">Answer</button>
                </form>
            <?php else: ?>
                <p style="font-size:.82rem;color:var(--text-light)"><i data-lucide="clock" class="lucide-icon"></i> Awaiting an answer from the instructor.</p>
            <?php endif; ?>
        </div></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
