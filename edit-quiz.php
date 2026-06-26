<?php
require_once __DIR__ . '/db.php';
$user = requireApprovedTeacher();

$quizId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT q.*, c.title AS course_title, c.teacher_id FROM quizzes q JOIN courses c ON c.id = q.course_id WHERE q.id = ?'
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz || (int) $quiz['teacher_id'] !== (int) $user['id']) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Quiz not found or not yours. <a href="dashboard.php">Go back</a></p>');
}
$courseId = (int) $quiz['course_id'];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['delete_quiz'])) {
        $pdo->prepare('DELETE FROM quizzes WHERE id = ?')->execute([$quizId]);
        flash('success', 'Quiz deleted.');
        redirect('manage-quizzes.php?course_id=' . $courseId);
    }

    if (isset($_POST['delete_question'])) {
        $pdo->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?')->execute([(int) $_POST['delete_question'], $quizId]);
        redirect('edit-quiz.php?id=' . $quizId);
    }

    if (isset($_POST['question_text'])) {
        $questionText = trim($_POST['question_text']);
        $options = array_filter(array_map('trim', $_POST['option'] ?? []), fn($o) => $o !== '');
        $correctIndex = (int) ($_POST['correct'] ?? -1);

        if (mb_strlen($questionText) < 3) $errors[] = 'Question text must be at least 3 characters.';
        if (count($options) < 2) $errors[] = 'Please provide at least 2 answer options.';
        if ($correctIndex < 0 || !isset($_POST['option'][$correctIndex]) || trim($_POST['option'][$correctIndex]) === '') {
            $errors[] = 'Please select which option is correct.';
        }

        if (!$errors) {
            $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM quiz_questions WHERE quiz_id = ?');
            $maxOrder->execute([$quizId]);
            $next = (int) $maxOrder->fetch()['m'] + 1;
            $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, sort_order) VALUES (?, ?, ?)')
                ->execute([$quizId, $questionText, $next]);
            $newQuestionId = (int) $pdo->lastInsertId();

            $i = 0;
            foreach ($_POST['option'] as $idx => $optionText) {
                $optionText = trim($optionText);
                if ($optionText === '') continue;
                $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)')
                    ->execute([$newQuestionId, $optionText, $idx == $correctIndex ? 1 : 0, $i]);
                $i++;
            }
            flash('success', 'Question added.');
            redirect('edit-quiz.php?id=' . $quizId);
        }
    }
}

$questions = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order ASC');
$questions->execute([$quizId]);
$questions = $questions->fetchAll();
foreach ($questions as &$q) {
    $opts = $pdo->prepare('SELECT * FROM quiz_options WHERE question_id = ? ORDER BY sort_order ASC');
    $opts->execute([$q['id']]);
    $q['options'] = $opts->fetchAll();
}
unset($q);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Quiz — <?= e($quiz['title']) ?></title>
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
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="manage-quizzes.php?course_id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Quizzes</a></p>
    <div class="dashboard-header"><h2><i data-lucide="pencil" class="lucide-icon"></i> <?= e($quiz['title']) ?></h2><p><?= e($quiz['course_title']) ?> · Pass mark: <?= (int) $quiz['passing_score'] ?>%</p></div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">+ Add Question</h3>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="form-group">
                <label class="form-label">Question</label>
                <textarea name="question_text" class="form-control" placeholder="e.g. What is the first pillar of Islam?" required></textarea>
            </div>
            <div class="form-group">
                <label class="form-label">Answer Options (select the radio button next to the correct one)</label>
                <?php for ($i = 0; $i < 4; $i++): ?>
                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem">
                    <input type="radio" name="correct" value="<?= $i ?>" style="width:auto" <?= $i === 0 ? 'checked' : '' ?> required>
                    <input type="text" name="option[]" class="form-control" placeholder="Option <?= $i + 1 ?><?= $i < 2 ? ' (required)' : ' (optional)' ?>" <?= $i < 2 ? 'required' : '' ?>>
                </div>
                <?php endfor; ?>
            </div>
            <button type="submit" class="btn btn-primary">Add Question</button>
        </form>
    </div></div>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Questions (<?= count($questions) ?>)</h3>
    <div class="card">
        <?php if (!$questions): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="circle-help" class="lucide-icon"></i></div><h3>No questions yet</h3><p>Students can't take this quiz until you add at least one question.</p></div>
        <?php else: ?>
        <ul class="lesson-list">
            <?php foreach ($questions as $i => $q): ?>
            <li class="lesson-item" style="align-items:flex-start">
                <div class="lesson-num"><?= $i + 1 ?></div>
                <div style="flex:1">
                    <div style="font-weight:600;margin-bottom:.4rem"><?= e($q['question_text']) ?></div>
                    <div style="display:flex;flex-direction:column;gap:.2rem">
                        <?php foreach ($q['options'] as $opt): ?>
                            <div style="font-size:.85rem;color:<?= $opt['is_correct'] ? 'var(--green-deep);font-weight:600' : 'var(--text-light)' ?>">
                                <?php if ($opt['is_correct']): ?><i data-lucide="check" class="lucide-icon"></i><?php else: ?><i data-lucide="circle" class="lucide-icon" style="width:.8em;height:.8em"></i><?php endif; ?>
                                <?= e($opt['option_text']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form method="post" onsubmit="return confirm('Delete this question?')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" name="delete_question" value="<?= (int) $q['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete question" aria-label="Delete question"><i data-lucide="trash-2" class="lucide-icon"></i></button>
                </form>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:1.5rem;border-color:#c62828"><div class="card-body">
        <h3 style="font-size:1rem;color:#c62828;margin-bottom:.5rem"><i data-lucide="triangle-alert" class="lucide-icon"></i> Danger Zone</h3>
        <p style="font-size:.85rem;color:var(--text-mid);margin-bottom:.8rem">Deleting this quiz removes all <?= count($questions) ?> question(s) and any student attempts. This cannot be undone.</p>
        <form method="post" onsubmit="return confirm('Permanently delete this quiz? This cannot be undone.')">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <button type="submit" name="delete_quiz" value="1" class="btn btn-outline" style="color:#c62828;border-color:#c62828"><i data-lucide="trash-2" class="lucide-icon"></i> Delete This Quiz</button>
        </form>
    </div></div>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
