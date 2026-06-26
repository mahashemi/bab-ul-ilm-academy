<?php
require_once __DIR__ . '/db.php';
$teacherId = requireTeacherOrSupport();
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$stmt->execute([$courseId, $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found or not yours. <a href="dashboard.php">Go back</a></p>');
}

$lessonCount = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?');
$lessonCount->execute([$courseId]);
$lessonCount = (int) $lessonCount->fetchColumn();
$lessonSummary = $lessonCount > 0 ? buildLessonSummaryForPrompt($pdo, $courseId) : '';

$quizResult = null;
$assignmentResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $lessonCount > 0) {
    verifyCsrf();

    if (isset($_FILES['quiz_csv_file'])) {
        $parsed = parseCsvUploadFile('quiz_csv_file');
        if ($parsed === null) {
            flash('error', 'Please choose a quiz CSV file to upload.');
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }
        if (isset($parsed['error'])) {
            flash('error', $parsed['error']);
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }
        $requiredCols = ['quiz_title', 'question', 'option_1', 'option_2', 'correct_option'];
        $missingCols = array_diff($requiredCols, $parsed['header']);
        if ($missingCols) {
            flash('error', 'Your quiz CSV is missing required column(s): ' . implode(', ', $missingCols) . '.');
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }

        $rowErrors = [];
        $validRows = [];
        foreach ($parsed['rows'] as $i => $row) {
            $errors = [];
            $quizTitle = trim($row['quiz_title'] ?? '');
            $question  = trim($row['question'] ?? '');
            $optionTexts = [trim($row['option_1'] ?? ''), trim($row['option_2'] ?? ''), trim($row['option_3'] ?? ''), trim($row['option_4'] ?? '')];
            $filledCount = count(array_filter($optionTexts, fn($o) => $o !== ''));
            $correctRaw = trim($row['correct_option'] ?? '');
            $passingScoreRaw = trim($row['passing_score'] ?? '');

            if (mb_strlen($quizTitle) < 3) $errors[] = 'quiz_title must be at least 3 characters';
            if (mb_strlen($question) < 3) $errors[] = 'question must be at least 3 characters';
            if ($filledCount < 2) $errors[] = 'at least option_1 and option_2 must be filled in';
            $correctIdx = ctype_digit($correctRaw) ? ((int) $correctRaw - 1) : -1;
            if ($correctIdx < 0 || $correctIdx > 3 || $optionTexts[$correctIdx] === '') {
                $errors[] = "correct_option must be 1, 2, 3, or 4, and point to a filled-in option (got '" . $correctRaw . "')";
            }
            if ($passingScoreRaw !== '' && (!ctype_digit($passingScoreRaw) || (int) $passingScoreRaw < 1 || (int) $passingScoreRaw > 100)) {
                $errors[] = "passing_score must be a number from 1-100 (got '" . $passingScoreRaw . "')";
            }

            if ($errors) {
                $rowErrors[$i] = $errors;
            } else {
                $validRows[] = ['quiz_title' => $quizTitle, 'question' => $question, 'options' => $optionTexts, 'correct_idx' => $correctIdx, 'passing_score' => $passingScoreRaw];
            }
        }

        $groups = [];
        foreach ($validRows as $r) {
            $groups[$r['quiz_title']][] = $r;
        }

        $quizzesCreated = 0;
        $questionsCreated = 0;
        foreach ($groups as $quizTitle => $groupRows) {
            $passingScore = 60;
            foreach ($groupRows as $r) {
                if ($r['passing_score'] !== '') { $passingScore = (int) $r['passing_score']; break; }
            }
            $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM quizzes WHERE course_id = ?');
            $maxOrder->execute([$courseId]);
            $nextQuizOrder = (int) $maxOrder->fetch()['m'] + 1;
            $pdo->prepare('INSERT INTO quizzes (course_id, title, passing_score, sort_order) VALUES (?, ?, ?, ?)')
                ->execute([$courseId, $quizTitle, $passingScore, $nextQuizOrder]);
            $quizId = (int) $pdo->lastInsertId();
            $quizzesCreated++;

            $qOrder = 0;
            foreach ($groupRows as $r) {
                $qOrder++;
                $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question_text, sort_order) VALUES (?, ?, ?)')
                    ->execute([$quizId, $r['question'], $qOrder]);
                $questionId = (int) $pdo->lastInsertId();

                $optOrder = 0;
                foreach ($r['options'] as $idx => $text) {
                    if ($text === '') continue;
                    $pdo->prepare('INSERT INTO quiz_options (question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?)')
                        ->execute([$questionId, $text, $idx === $r['correct_idx'] ? 1 : 0, $optOrder]);
                    $optOrder++;
                }
                $questionsCreated++;
            }
        }

        $quizResult = ['quizzes' => $quizzesCreated, 'questions' => $questionsCreated, 'errors' => $rowErrors, 'header' => $parsed['header'], 'rows' => $parsed['rows'], 'raw' => $parsed['raw']];
        if ($quizzesCreated > 0 && !$rowErrors) {
            if ($teacherId !== (int) $user['id']) {
                logActivity($pdo, $user['id'], 'Bulk-added ' . $quizzesCreated . ' quiz(zes) to course #' . $courseId . ' on behalf of teacher #' . $teacherId);
            }
            flash('success', $quizzesCreated . ' quiz(zes) with ' . $questionsCreated . ' question(s) created for "' . $course['title'] . '"!');
            redirect('manage-quizzes.php?course_id=' . $courseId);
        }
    }

    if (isset($_FILES['assignment_csv_file'])) {
        $parsed = parseCsvUploadFile('assignment_csv_file');
        if ($parsed === null) {
            flash('error', 'Please choose an assignment CSV file to upload.');
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }
        if (isset($parsed['error'])) {
            flash('error', $parsed['error']);
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }
        $missingCols = array_diff(['title'], $parsed['header']);
        if ($missingCols) {
            flash('error', 'Your assignment CSV is missing required column(s): ' . implode(', ', $missingCols) . '.');
            redirect('bulk-assessments.php?course_id=' . $courseId);
        }

        $created = 0;
        $rowErrors = [];
        foreach ($parsed['rows'] as $i => $row) {
            $errors = [];
            $title = trim($row['title'] ?? '');
            $description = trim($row['description'] ?? '');
            $dueDateRaw = trim($row['due_date'] ?? '');

            if (mb_strlen($title) < 3) $errors[] = 'title must be at least 3 characters';
            $dueDate = null;
            if ($dueDateRaw !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $dueDateRaw);
                if (!$d || $d->format('Y-m-d') !== $dueDateRaw) {
                    $errors[] = "due_date must be in YYYY-MM-DD format (got '" . $dueDateRaw . "')";
                } else {
                    $dueDate = $dueDateRaw;
                }
            }

            if ($errors) {
                $rowErrors[$i] = $errors;
                continue;
            }

            $pdo->prepare('INSERT INTO assignments (course_id, title, description, due_date) VALUES (?, ?, ?, ?)')
                ->execute([$courseId, $title, $description ?: null, $dueDate]);
            $created++;
        }

        $assignmentResult = ['created' => $created, 'errors' => $rowErrors, 'header' => $parsed['header'], 'rows' => $parsed['rows'], 'raw' => $parsed['raw']];
        if ($created > 0 && !$rowErrors) {
            if ($teacherId !== (int) $user['id']) {
                logActivity($pdo, $user['id'], 'Bulk-added ' . $created . ' assignment(s) to course #' . $courseId . ' on behalf of teacher #' . $teacherId);
            }
            flash('success', $created . ' assignment(s) created for "' . $course['title'] . '"!');
            redirect('manage-assignments.php?course_id=' . $courseId);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulk Add Quizzes &amp; Assignments — <?= e($course['title']) ?></title>
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
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:900px">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="edit-course.php?id=<?= $courseId ?>&step=curriculum"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($course['title']) ?></a></p>
    <div class="dashboard-header">
        <h2><i data-lucide="upload" class="lucide-icon"></i> Bulk Add Quizzes &amp; Assignments</h2>
        <p><?= e($course['title']) ?></p>
    </div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <?php if ($lessonCount === 0): ?>
        <div class="card"><div class="empty-state">
            <div class="icon"><i data-lucide="lock" class="lucide-icon"></i></div>
            <h3>Add lessons first</h3>
            <p>Bulk quiz/assignment upload unlocks once this course has at least one lesson — a course needs real content before it needs an assessment.</p>
            <a href="bulk-lessons.php?course_id=<?= $courseId ?>" class="btn btn-primary" style="margin-top:1rem">Bulk Add Lessons</a>
            <a href="add-lesson.php?course_id=<?= $courseId ?>" class="btn btn-outline" style="margin-top:1rem">Add a Lesson Manually</a>
        </div></div>
    <?php else: ?>

    <h3 style="font-size:1.2rem;color:var(--green-deep);margin:2rem 0 1rem"><i data-lucide="list-checks" class="lucide-icon"></i> Quizzes</h3>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h4 style="font-size:.95rem;margin-bottom:.8rem">Required Columns &amp; Rules</h4>
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Column</th><th>Required?</th><th>Rules</th></tr></thead>
            <tbody>
                <tr><td><code>quiz_title</code></td><td>Required</td><td>Same exact text on every row of the same quiz — this groups questions together</td></tr>
                <tr><td><code>passing_score</code></td><td>Optional</td><td>Number 1-100, defaults to 60. Only the first value per quiz is used.</td></tr>
                <tr><td><code>question</code></td><td>Required</td><td>At least 3 characters</td></tr>
                <tr><td><code>option_1</code>, <code>option_2</code></td><td>Required</td><td>The first two answer choices</td></tr>
                <tr><td><code>option_3</code>, <code>option_4</code></td><td>Optional</td><td>Extra choices, leave blank if not needed (2-4 options total)</td></tr>
                <tr><td><code>correct_option</code></td><td>Required</td><td>The number (1-4) of the correct option</td></tr>
            </tbody>
        </table>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <a href="download-template.php?type=quizzes" class="btn btn-outline" style="margin-bottom:1rem"><i data-lucide="file-down" class="lucide-icon"></i> Download quiz-template.csv</a>
        <div class="ai-prompt-box">
            <p style="font-size:.85rem;margin-bottom:.5rem"><strong>Let an AI write it</strong> — grounded in this course's actual lessons, so questions test what was really taught:</p>
            <pre id="quizPrompt"><?= e(renderAiPrompt($pdo, 'quiz_questions', [
                'site_name' => SITE_NAME,
                'course_title' => $course['title'],
                'lesson_summary' => $lessonSummary,
            ])) ?></pre>
            <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="quizPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Prompt</button>
        </div>
        <form method="post" enctype="multipart/form-data" style="margin-top:1rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div class="form-group"><input type="file" name="quiz_csv_file" class="form-control" accept=".csv,text/csv" required></div>
            <button type="submit" class="btn btn-primary">Upload Quiz CSV</button>
        </form>
    </div></div>

    <?php if ($quizResult): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h4 style="font-size:.95rem;margin-bottom:.8rem">
            <i data-lucide="<?= $quizResult['errors'] ? 'alert-triangle' : 'check-circle-2' ?>" class="lucide-icon"></i>
            Results: <?= (int) $quizResult['quizzes'] ?> quiz(zes), <?= (int) $quizResult['questions'] ?> question(s) created, <?= count($quizResult['errors']) ?> row(s) with errors
        </h4>
        <?php if ($quizResult['errors']): ?>
            <table class="table" style="font-size:.85rem;margin-bottom:1rem">
                <thead><tr><th>Row</th><th>Question (as uploaded)</th><th>Problems</th></tr></thead>
                <tbody>
                <?php foreach ($quizResult['errors'] as $i => $errs): ?>
                    <tr><td><?= $i + 2 ?></td><td><?= e($quizResult['rows'][$i]['question'] ?? '') ?></td><td><?= e(implode('; ', $errs)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $errorLines = [];
            foreach ($quizResult['errors'] as $i => $errs) {
                $errorLines[] = 'Row ' . ($i + 2) . ': ' . implode('; ', $errs);
            }
            $fixPrompt = "Here is a quiz CSV I'm trying to upload to an online course platform, but it has validation errors. Please fix ONLY the listed problems and return the corrected CSV with the exact same column headers — output ONLY raw CSV text, no explanation, no markdown code fences.\n\nErrors found:\n- " . implode("\n- ", $errorLines) . "\n\nOriginal CSV:\n" . $quizResult['raw'];
            ?>
            <div class="ai-prompt-box">
                <pre id="quizFixPrompt"><?= e($fixPrompt) ?></pre>
                <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="quizFixPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Fix-It Prompt</button>
            </div>
            <form method="post" action="download-error-report.php" style="margin-top:.8rem">
                <input type="hidden" name="report_type" value="quiz">
                <input type="hidden" name="header" value="<?= e(json_encode($quizResult['header'])) ?>">
                <input type="hidden" name="rows" value="<?= e(json_encode($quizResult['rows'])) ?>">
                <input type="hidden" name="errors" value="<?= e(json_encode($quizResult['errors'])) ?>">
                <button type="submit" class="btn btn-outline btn-sm"><i data-lucide="file-down" class="lucide-icon"></i> Download Annotated CSV</button>
            </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <h3 style="font-size:1.2rem;color:var(--green-deep);margin:2rem 0 1rem"><i data-lucide="file-edit" class="lucide-icon"></i> Assignments</h3>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h4 style="font-size:.95rem;margin-bottom:.8rem">Required Columns &amp; Rules</h4>
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Column</th><th>Required?</th><th>Rules</th></tr></thead>
            <tbody>
                <tr><td><code>title</code></td><td>Required</td><td>At least 3 characters</td></tr>
                <tr><td><code>description</code></td><td>Optional</td><td>Instructions for the student</td></tr>
                <tr><td><code>due_date</code></td><td>Optional</td><td>Format YYYY-MM-DD, leave blank for no deadline</td></tr>
            </tbody>
        </table>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <a href="download-template.php?type=assignments" class="btn btn-outline" style="margin-bottom:1rem"><i data-lucide="file-down" class="lucide-icon"></i> Download assignments-template.csv</a>
        <div class="ai-prompt-box">
            <p style="font-size:.85rem;margin-bottom:.5rem"><strong>Let an AI write it</strong> — grounded in this course's actual lessons:</p>
            <pre id="assignmentPrompt"><?= e(renderAiPrompt($pdo, 'assignments', [
                'site_name' => SITE_NAME,
                'course_title' => $course['title'],
                'lesson_summary' => $lessonSummary,
            ])) ?></pre>
            <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="assignmentPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Prompt</button>
        </div>
        <form method="post" enctype="multipart/form-data" style="margin-top:1rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div class="form-group"><input type="file" name="assignment_csv_file" class="form-control" accept=".csv,text/csv" required></div>
            <button type="submit" class="btn btn-primary">Upload Assignment CSV</button>
        </form>
    </div></div>

    <?php if ($assignmentResult): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h4 style="font-size:.95rem;margin-bottom:.8rem">
            <i data-lucide="<?= $assignmentResult['errors'] ? 'alert-triangle' : 'check-circle-2' ?>" class="lucide-icon"></i>
            Results: <?= (int) $assignmentResult['created'] ?> created, <?= count($assignmentResult['errors']) ?> row(s) with errors
        </h4>
        <?php if ($assignmentResult['errors']): ?>
            <table class="table" style="font-size:.85rem;margin-bottom:1rem">
                <thead><tr><th>Row</th><th>Title (as uploaded)</th><th>Problems</th></tr></thead>
                <tbody>
                <?php foreach ($assignmentResult['errors'] as $i => $errs): ?>
                    <tr><td><?= $i + 2 ?></td><td><?= e($assignmentResult['rows'][$i]['title'] ?? '') ?></td><td><?= e(implode('; ', $errs)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
            $errorLines = [];
            foreach ($assignmentResult['errors'] as $i => $errs) {
                $errorLines[] = 'Row ' . ($i + 2) . ': ' . implode('; ', $errs);
            }
            $fixPrompt = "Here is an assignment CSV I'm trying to upload to an online course platform, but it has validation errors. Please fix ONLY the listed problems and return the corrected CSV with the exact same column headers — output ONLY raw CSV text, no explanation, no markdown code fences.\n\nErrors found:\n- " . implode("\n- ", $errorLines) . "\n\nOriginal CSV:\n" . $assignmentResult['raw'];
            ?>
            <div class="ai-prompt-box">
                <pre id="assignmentFixPrompt"><?= e($fixPrompt) ?></pre>
                <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="assignmentFixPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Fix-It Prompt</button>
            </div>
            <form method="post" action="download-error-report.php" style="margin-top:.8rem">
                <input type="hidden" name="report_type" value="assignment">
                <input type="hidden" name="header" value="<?= e(json_encode($assignmentResult['header'])) ?>">
                <input type="hidden" name="rows" value="<?= e(json_encode($assignmentResult['rows'])) ?>">
                <input type="hidden" name="errors" value="<?= e(json_encode($assignmentResult['errors'])) ?>">
                <button type="submit" class="btn btn-outline btn-sm"><i data-lucide="file-down" class="lucide-icon"></i> Download Annotated CSV</button>
            </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>

    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
