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

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $parsed = parseCsvUploadFile('csv_file');

    if ($parsed === null) {
        flash('error', 'Please choose a CSV file to upload.');
        redirect('bulk-lessons.php?course_id=' . $courseId);
    }
    if (isset($parsed['error'])) {
        flash('error', $parsed['error']);
        redirect('bulk-lessons.php?course_id=' . $courseId);
    }

    $requiredCols = ['title', 'content'];
    $missingCols = array_diff($requiredCols, $parsed['header']);
    if ($missingCols) {
        flash('error', 'Your CSV is missing required column(s): ' . implode(', ', $missingCols) . '. Download the template below to see the exact column names.');
        redirect('bulk-lessons.php?course_id=' . $courseId);
    }

    $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM lessons WHERE course_id = ?');
    $maxOrder->execute([$courseId]);
    $nextOrder = (int) $maxOrder->fetch()['m'];

    $created = 0;
    $rowErrors = [];
    foreach ($parsed['rows'] as $i => $row) {
        $errors = [];
        $sectionTitle = trim($row['section_title'] ?? '');
        $title    = trim($row['title'] ?? '');
        $content  = trim($row['content'] ?? '');
        $videoUrl = trim($row['video_url'] ?? '');
        $durationRaw = trim($row['duration_minutes'] ?? '');

        if (mb_strlen($title) < 3) $errors[] = 'title must be at least 3 characters';
        if (mb_strlen($content) < 1) $errors[] = 'content is required (the actual lesson text students will read)';
        if ($videoUrl !== '' && !preg_match('#^https?://#i', $videoUrl)) {
            $errors[] = "video_url must start with http:// or https:// (got '" . $videoUrl . "')";
        }
        $duration = 0;
        if ($durationRaw !== '') {
            if (!is_numeric($durationRaw) || (float) $durationRaw < 0) {
                $errors[] = "duration_minutes must be a non-negative number (got '" . $durationRaw . "')";
            } else {
                $duration = (int) $durationRaw;
            }
        }

        if ($errors) {
            $rowErrors[$i] = $errors;
            continue;
        }

        $nextOrder++;
        $pdo->prepare('INSERT INTO lessons (course_id, section_title, title, content, video_url, duration_minutes, is_preview, sort_order) VALUES (?, ?, ?, ?, ?, ?, 0, ?)')
            ->execute([$courseId, $sectionTitle ?: null, $title, $content, $videoUrl ?: null, $duration, $nextOrder]);
        $created++;
    }

    $result = ['created' => $created, 'errors' => $rowErrors, 'header' => $parsed['header'], 'rows' => $parsed['rows'], 'raw' => $parsed['raw']];

    if ($created > 0 && !$rowErrors) {
        if ($teacherId !== (int) $user['id']) {
            logActivity($pdo, $user['id'], 'Bulk-added ' . $created . ' lesson(s) to course #' . $courseId . ' on behalf of teacher #' . $teacherId);
        }
        flash('success', $created . ' lesson(s) added to "' . $course['title'] . '"!');
        redirect('edit-course.php?id=' . $courseId);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bulk Add Lessons — <?= e($course['title']) ?></title>
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
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="edit-course.php?id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($course['title']) ?></a></p>
    <div class="dashboard-header">
        <h2><i data-lucide="upload" class="lucide-icon"></i> Bulk Add Lessons</h2>
        <p><?= e($course['title']) ?> · Upload a CSV to add many lessons at once instead of typing each one individually.</p>
    </div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="list-checks" class="lucide-icon"></i> Required Columns &amp; Rules</h3>
        <table class="table" style="font-size:.85rem">
            <thead><tr><th>Column</th><th>Required?</th><th>Rules</th></tr></thead>
            <tbody>
                <tr><td><code>section_title</code></td><td>Optional</td><td>Groups lessons, e.g. "Week 1" — use the same text on multiple rows to group them together</td></tr>
                <tr><td><code>title</code></td><td>Required</td><td>At least 3 characters</td></tr>
                <tr><td><code>content</code></td><td>Required</td><td>The actual lesson text students will read</td></tr>
                <tr><td><code>video_url</code></td><td>Optional</td><td>Must be an <strong>embed</strong> link if filled in, e.g. <code>https://www.youtube.com/embed/VIDEO_ID</code> — not a normal "watch" link, or the video won't display</td></tr>
                <tr><td><code>duration_minutes</code></td><td>Optional</td><td>A whole number, defaults to 0</td></tr>
            </tbody>
        </table>
        <p style="font-size:.82rem;color:var(--text-light);margin-top:.6rem"><i data-lucide="info" class="lucide-icon"></i> New lessons are added after any lessons your course already has — existing lessons are never changed or removed by this upload.</p>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="download" class="lucide-icon"></i> Step 1: Get the Template</h3>
        <a href="download-template.php?type=lessons" class="btn btn-outline"><i data-lucide="file-down" class="lucide-icon"></i> Download lessons-template.csv</a>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="sparkles" class="lucide-icon"></i> Step 2 (Optional): Let an AI Fill It In For You</h3>
        <p style="font-size:.88rem;margin-bottom:.8rem">Copy this prompt, tell the AI your topic and how many lessons you want, and it will write the full lesson plan as a CSV.</p>
        <div class="ai-prompt-box">
            <pre id="lessonPrompt"><?= e(renderAiPrompt($pdo, 'course_lessons', [
                'site_name' => SITE_NAME,
                'course_title' => $course['title'],
                'course_description' => $course['description'],
                'textbook' => $course['textbook'] ?: 'None specified — use your general knowledge of the subject.',
            ])) ?></pre>
            <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="lessonPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Prompt</button>
        </div>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem"><i data-lucide="upload-cloud" class="lucide-icon"></i> Step 3: Upload Your CSV</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <div class="form-group">
                <input type="file" name="csv_file" class="form-control" accept=".csv,text/csv" required>
            </div>
            <button type="submit" class="btn btn-primary">Upload &amp; Add Lessons</button>
        </form>
    </div></div>

    <?php if ($result): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.8rem">
            <i data-lucide="<?= $result['errors'] ? 'alert-triangle' : 'check-circle-2' ?>" class="lucide-icon"></i> Results: <?= (int) $result['created'] ?> added, <?= count($result['errors']) ?> row(s) with errors
        </h3>

        <?php if ($result['errors']): ?>
            <table class="table" style="font-size:.85rem;margin-bottom:1rem">
                <thead><tr><th>Row</th><th>Title (as uploaded)</th><th>Problems</th></tr></thead>
                <tbody>
                <?php foreach ($result['errors'] as $i => $errs): ?>
                    <tr><td><?= $i + 2 ?></td><td><?= e($result['rows'][$i]['title'] ?? '') ?></td><td><?= e(implode('; ', $errs)) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $errorLines = [];
            foreach ($result['errors'] as $i => $errs) {
                $errorLines[] = 'Row ' . ($i + 2) . ' ("' . ($result['rows'][$i]['title'] ?? '') . '"): ' . implode('; ', $errs);
            }
            $fixPrompt = "Here is a CSV of lessons I'm trying to upload to an online course platform, but it has validation errors. Please fix ONLY the listed problems and return the corrected CSV with the exact same column headers — output ONLY raw CSV text, no explanation, no markdown code fences.\n\nErrors found:\n- " . implode("\n- ", $errorLines) . "\n\nOriginal CSV:\n" . $result['raw'];
            ?>
            <div class="ai-prompt-box">
                <p style="font-size:.85rem;margin-bottom:.5rem"><strong>Stuck?</strong> Copy this into an AI assistant — it already includes the exact errors and your CSV content.</p>
                <pre id="fixPrompt"><?= e($fixPrompt) ?></pre>
                <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="fixPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Fix-It Prompt</button>
            </div>
            <form method="post" action="download-error-report.php" style="margin-top:.8rem">
                <input type="hidden" name="report_type" value="lessons">
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
