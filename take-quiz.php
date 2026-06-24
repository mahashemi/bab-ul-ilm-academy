<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$quizId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT q.*, c.title AS course_title FROM quizzes q JOIN courses c ON c.id = q.course_id WHERE q.id = ?'
);
$stmt->execute([$quizId]);
$quiz = $stmt->fetch();

if (!$quiz) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Quiz not found. <a href="courses.php">Go back</a></p>');
}
$courseId = (int) $quiz['course_id'];

$isEnrolled = false;
if (canEnroll($user['role'] ?? null)) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $courseId]);
    $isEnrolled = (bool) $e->fetch();
}
if (!$isEnrolled) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You need to be enrolled in this course to take this quiz. <a href="course.php?id=' . $courseId . '">Go to course page</a></p>');
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

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz']) && $questions) {
    verifyCsrf();
    $score = 0;
    $answers = [];
    foreach ($questions as $q) {
        $selectedOptionId = (int) ($_POST['q' . $q['id']] ?? 0);
        $correctOption = null;
        foreach ($q['options'] as $opt) { if ($opt['is_correct']) { $correctOption = $opt; break; } }
        $isCorrect = $correctOption && $selectedOptionId === (int) $correctOption['id'];
        if ($isCorrect) $score++;
        $answers[] = ['question_id' => $q['id'], 'option_id' => $selectedOptionId ?: null, 'is_correct' => $isCorrect ? 1 : 0];
    }
    $total = count($questions);
    $pdo->prepare('INSERT INTO quiz_attempts (quiz_id, student_id, score, total) VALUES (?, ?, ?, ?)')
        ->execute([$quizId, $user['id'], $score, $total]);
    $attemptId = (int) $pdo->lastInsertId();
    foreach ($answers as $a) {
        $pdo->prepare('INSERT INTO quiz_attempt_answers (attempt_id, question_id, option_id, is_correct) VALUES (?, ?, ?, ?)')
            ->execute([$attemptId, $a['question_id'], $a['option_id'], $a['is_correct']]);
    }

    $percent = $total > 0 ? round($score / $total * 100) : 0;
    $passed = $percent >= (int) $quiz['passing_score'];

    if ($passed) {
        // Only the first PASS of this quiz awards points, so retaking an
        // already-passed quiz for fun doesn't farm points repeatedly.
        $priorPass = $pdo->prepare(
            'SELECT 1 FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? AND (score / total * 100) >= ? AND id != ? LIMIT 1'
        );
        $priorPass->execute([$quizId, $user['id'], (int) $quiz['passing_score'], $attemptId]);
        if (!$priorPass->fetch()) {
            awardPoints($pdo, $user['id'], 30, 'Passed quiz "' . $quiz['title'] . '"');
        }
    }

    $result = ['score' => $score, 'total' => $total, 'percent' => $percent, 'passed' => $passed];
}

$bestAttempt = $pdo->prepare(
    'SELECT *, (score / total * 100) AS percent FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? ORDER BY percent DESC, created_at DESC LIMIT 1'
);
$bestAttempt->execute([$quizId, $user['id']]);
$bestAttempt = $bestAttempt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($quiz['title']) ?> — <?= e($quiz['course_title']) ?></title>
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
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="courses.php">Courses</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <a href="chat.php">Messages</a>
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
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:760px">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="course.php?id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($quiz['course_title']) ?></a></p>
    <div class="dashboard-header"><h2><i data-lucide="list-checks" class="lucide-icon"></i> <?= e($quiz['title']) ?></h2><p>Pass mark: <?= (int) $quiz['passing_score'] ?>% · <?= count($questions) ?> question<?= count($questions) == 1 ? '' : 's' ?></p></div>

    <?php if ($result): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body" style="text-align:center">
            <div style="font-size:2.2rem;font-weight:800;color:<?= $result['passed'] ? 'var(--green-deep)' : '#c62828' ?>"><?= $result['percent'] ?>%</div>
            <p style="margin-bottom:1rem"><?= $result['score'] ?> out of <?= $result['total'] ?> correct</p>
            <?php if ($result['passed']): ?>
                <div class="alert alert-success" style="display:inline-block"><i data-lucide="check-circle-2" class="lucide-icon"></i> You passed!</div>
            <?php else: ?>
                <div class="alert alert-error" style="display:inline-block"><i data-lucide="x-circle" class="lucide-icon"></i> You need <?= (int) $quiz['passing_score'] ?>% to pass. Try again!</div>
            <?php endif; ?>
        </div></div>
    <?php elseif ($bestAttempt): ?>
        <div class="alert alert-info" style="margin-bottom:1.5rem">
            Your best score so far: <strong><?= round((float) $bestAttempt['percent']) ?>%</strong>
            (<?= $bestAttempt['percent'] >= $quiz['passing_score'] ? 'Passed' : 'Not yet passed' ?>). You can retake the quiz below.
        </div>
    <?php endif; ?>

    <?php if (!$questions): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="circle-help" class="lucide-icon"></i></div><h3>This quiz has no questions yet</h3></div>
    <?php else: ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
        <?php foreach ($questions as $i => $q): ?>
        <div class="card" style="margin-bottom:1.2rem"><div class="card-body">
            <p style="font-weight:600;margin-bottom:.8rem"><?= $i + 1 ?>. <?= e($q['question_text']) ?></p>
            <?php foreach ($q['options'] as $opt): ?>
            <label style="display:flex;align-items:center;gap:.6rem;padding:.5rem 0;cursor:pointer">
                <input type="radio" name="q<?= (int) $q['id'] ?>" value="<?= (int) $opt['id'] ?>" style="width:auto" required>
                <?= e($opt['option_text']) ?>
            </label>
            <?php endforeach; ?>
        </div></div>
        <?php endforeach; ?>
        <button type="submit" name="submit_quiz" value="1" class="btn btn-primary btn-full"><?= $bestAttempt ? 'Retake Quiz' : 'Submit Quiz' ?></button>
    </form>
    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
