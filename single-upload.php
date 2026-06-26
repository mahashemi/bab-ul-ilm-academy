<?php
require_once __DIR__ . '/db.php';
$teacherId = requireTeacherOrSupport();
$user = auth();

$subjects = $pdo->query('SELECT name FROM subjects ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$subjectsLower = array_map('mb_strtolower', $subjects);

$result = null; // ['created' => int, 'errors' => [rowIndex => [msgs]], 'header' => [], 'rows' => [], 'raw' => '']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $parsed = parseCsvUploadFile('csv_file');

    if ($parsed === null) {
        flash('error', 'Please choose a CSV file to upload.');
        redirect('single-upload.php');
    }
    if (isset($parsed['error'])) {
        flash('error', $parsed['error']);
        redirect('single-upload.php');
    }

    $requiredCols = ['title', 'description', 'level'];
    $missingCols = array_diff($requiredCols, $parsed['header']);
    if ($missingCols) {
        flash('error', 'Your CSV is missing required column(s): ' . implode(', ', $missingCols) . '. Download the template below to see the exact column names.');
        redirect('single-upload.php');
    }
    if (count($parsed['rows']) > 1) {
        flash('error', 'This uploads one course at a time, but your CSV has ' . count($parsed['rows']) . ' data rows. Remove all but one row, or upload each course separately.');
        redirect('single-upload.php');
    }
    if (count($parsed['rows']) === 0) {
        flash('error', 'Your CSV has no data rows — add one row with your course details below the header.');
        redirect('single-upload.php');
    }

    $newCourseId = null;
    $rowErrors = [];
    foreach ($parsed['rows'] as $i => $row) {
        $errors = [];
        $title       = trim($row['title'] ?? '');
        $description = trim($row['description'] ?? '');
        $subjectName = trim($row['subject'] ?? '');
        $level       = strtolower(trim($row['level'] ?? ''));
        $language    = trim($row['language'] ?? '') ?: 'English';
        $priceRaw    = trim($row['price'] ?? '');
        $objectives  = trim($row['learning_objectives'] ?? '');
        $requirements = trim($row['requirements'] ?? '');
        $textbook    = trim($row['textbook'] ?? '');

        if (mb_strlen($title) < 5) $errors[] = 'title must be at least 5 characters';
        if (mb_strlen($description) < 20) $errors[] = 'description must be at least 20 characters';
        if (!in_array($level, ['beginner', 'intermediate', 'advanced'], true)) {
            $errors[] = "level must be exactly 'beginner', 'intermediate', or 'advanced' (got '" . $row['level'] . "')";
        }
        $subjectId = null;
        if ($subjectName !== '') {
            $idx = array_search(mb_strtolower($subjectName), $subjectsLower, true);
            if ($idx === false) {
                $errors[] = "subject '" . $subjectName . "' was not found. See the valid subject list above.";
            }
        }
        $price = 0;
        if ($priceRaw !== '') {
            if (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                $errors[] = "price must be a non-negative number (got '" . $priceRaw . "')";
            } else {
                $price = (float) $priceRaw;
            }
        }

        if ($errors) {
            $rowErrors[$i] = $errors;
            continue;
        }

        $realSubjectId = null;
        if ($subjectName !== '') {
            $stmt = $pdo->prepare('SELECT id FROM subjects WHERE LOWER(name) = LOWER(?) LIMIT 1');
            $stmt->execute([$subjectName]);
            $realSubjectId = $stmt->fetchColumn() ?: null;
        }

        $pdo->prepare(
            "INSERT INTO courses (teacher_id, subject_id, title, description, learning_objectives, requirements, textbook, level, language, price, is_published, moderation_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending')"
        )->execute([$teacherId, $realSubjectId, $title, $description, $objectives ?: null, $requirements ?: null, $textbook ?: null, $level, $language, $price]);
        $newCourseId = (int) $pdo->lastInsertId();
    }

    $result = ['created' => $newCourseId ? 1 : 0, 'errors' => $rowErrors, 'header' => $parsed['header'], 'rows' => $parsed['rows'], 'raw' => $parsed['raw']];

    if ($newCourseId && !$rowErrors) {
        if ($teacherId !== (int) $user['id']) {
            logActivity($pdo, $user['id'], 'Created course #' . $newCourseId . ' via single upload on behalf of teacher #' . $teacherId);
        }
        flash('success', 'Course created! It will be reviewed by an admin before appearing publicly. Now add some lessons.');
        redirect('add-lesson.php?course_id=' . $newCourseId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Single Upload — <?= e(SITE_NAME) ?></title>
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
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="dashboard.php"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Dashboard</a></p>
    <div class="dashboard-header">
        <h2><i data-lucide="upload" class="lucide-icon"></i> Single Upload</h2>
        <p>Create one course from a CSV — handy when you've already got the details written out (or an AI assistant wrote them for you).</p>
    </div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="list-checks" class="lucide-icon"></i> Required Columns &amp; Rules</h3>
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Column</th><th>Required?</th><th>Rules</th></tr></thead>
            <tbody>
                <tr><td><code>title</code></td><td>Required</td><td>At least 5 characters</td></tr>
                <tr><td><code>description</code></td><td>Required</td><td>At least 20 characters</td></tr>
                <tr><td><code>subject</code></td><td>Optional</td><td>Must exactly match an existing subject name (see list below)</td></tr>
                <tr><td><code>level</code></td><td>Required</td><td>Exactly <code>beginner</code>, <code>intermediate</code>, or <code>advanced</code></td></tr>
                <tr><td><code>language</code></td><td>Optional</td><td>Any text, defaults to "English"</td></tr>
                <tr><td><code>price</code></td><td>Optional</td><td>A number, 0 or higher (0 = free), defaults to 0</td></tr>
                <tr><td><code>learning_objectives</code></td><td>Optional</td><td>Free text, separate points with semicolons</td></tr>
                <tr><td><code>requirements</code></td><td>Optional</td><td>Free text</td></tr>
                <tr><td><code>textbook</code></td><td>Optional</td><td>Reference textbook/material this course follows, if any</td></tr>
            </tbody>
        </table>
        <p style="font-size:.82rem;color:var(--text-light);margin-top:.6rem"><i data-lucide="info" class="lucide-icon"></i> This creates exactly one course — your CSV should have exactly one data row below the header. Need more than one? Upload them one at a time. A cover photo can't be set via CSV — add one afterward from the course's Edit page. New courses are always submitted for admin review before they go live, same as creating one manually.</p>
        <details style="margin-top:.8rem">
            <summary style="cursor:pointer;font-size:.85rem;font-weight:600;color:var(--green-deep)">Valid subject names (<?= count($subjects) ?>)</summary>
            <p style="font-size:.82rem;margin-top:.5rem;color:var(--text-mid)"><?= e(implode(', ', $subjects)) ?></p>
        </details>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="download" class="lucide-icon"></i> Step 1: Get the Template</h3>
        <p style="font-size:.88rem;margin-bottom:.8rem">Download a starter CSV with the correct headers and one example row.</p>
        <a href="download-template.php?type=courses" class="btn btn-outline"><i data-lucide="file-down" class="lucide-icon"></i> Download courses-template.csv</a>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="sparkles" class="lucide-icon"></i> Step 2 (Optional): Let an AI Fill It In For You</h3>
        <p style="font-size:.88rem;margin-bottom:.8rem">Copy this prompt into ChatGPT, Claude, or any AI assistant, describe the course you want, and it will write the CSV for you.</p>
        <div class="ai-prompt-box">
            <pre id="coursePrompt"><?= e(renderAiPrompt($pdo, 'course_creation', ['site_name' => SITE_NAME, 'subject_list' => implode(', ', $subjects)])) ?></pre>
            <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="coursePrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Prompt</button>
        </div>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="upload-cloud" class="lucide-icon"></i> Step 3: Upload Your CSV</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="form-group">
                <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload &amp; Create Course</button>
        </form>
    </div></div>

    <?php if ($result): ?>
    <div class="card" style="margin-bottom:1.5rem;<?= $result['errors'] ? 'border-color:var(--danger,#c0392b)' : '' ?>"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">
            <i data-lucide="<?= $result['errors'] ? 'alert-triangle' : 'check-circle-2' ?>" class="lucide-icon"></i> Results: <?= (int) $result['created'] ?> created, <?= count($result['errors']) ?> row(s) with errors
        </h3>

        <?php if ($result['errors']): ?>
            <table class="table" style="font-size:.85rem;margin-bottom:1rem">
                <thead><tr><th>Row</th><th>Title (as uploaded)</th><th>Problems</th></tr></thead>
                <tbody>
                <?php foreach ($result['errors'] as $i => $errs): ?>
                    <tr><td><?= $i + 2 /* +1 for header row, +1 for 1-indexing */ ?></td><td><?= e($result['rows'][$i]['title'] ?? '') ?></td><td><?= e(implode('; ', $errs)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $errorLines = [];
            foreach ($result['errors'] as $i => $errs) {
                $errorLines[] = 'Row ' . ($i + 2) . ' ("' . ($result['rows'][$i]['title'] ?? '') . '"): ' . implode('; ', $errs);
            }
            $fixPrompt = "Here is a CSV I'm trying to upload to an online course platform, but it has validation errors. Please fix ONLY the listed problems and return the corrected CSV with the exact same column headers — output ONLY raw CSV text, no explanation, no markdown code fences.\n\nErrors found:\n- " . implode("\n- ", $errorLines) . "\n\nOriginal CSV:\n" . $result['raw'];
            ?>
            <div class="ai-prompt-box">
                <p style="font-size:.85rem;margin-bottom:.5rem"><strong>Stuck?</strong> Copy this into an AI assistant along with your original file — it already includes the exact errors and your CSV content, so the AI can return a corrected version.</p>
                <pre id="fixPrompt"><?= e($fixPrompt) ?></pre>
                <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="fixPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Fix-It Prompt</button>
            </div>
            <form method="post" action="download-error-report.php" style="margin-top:.8rem">
                <input type="hidden" name="report_type" value="courses">
                <input type="hidden" name="header" value="<?= e(json_encode($result['header'])) ?>">
                <input type="hidden" name="rows" value="<?= e(json_encode($result['rows'])) ?>">
                <input type="hidden" name="errors" value="<?= e(json_encode($result['errors'])) ?>">
                <button type="submit" class="btn btn-outline btn-sm"><i data-lucide="file-down" class="lucide-icon"></i> Download Annotated CSV</button>
            </form>
        <?php endif; ?>
    </div></div>
    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
