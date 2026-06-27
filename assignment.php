<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$assignmentId = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT a.*, c.title AS course_title, c.id AS course_id, c.teacher_id FROM assignments a JOIN courses c ON c.id = a.course_id WHERE a.id = ?'
);
$stmt->execute([$assignmentId]);
$assignment = $stmt->fetch();

if (!$assignment) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Assignment not found. <a href="courses.php">Go back</a></p>');
}

$isTeacher = (int) $assignment['teacher_id'] === (int) $user['id'];
$isEnrolled = false;
if (!$isTeacher && canEnroll($user['role'] ?? null)) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $assignment['course_id']]);
    $isEnrolled = (bool) $e->fetch();
}
if (!$isTeacher && !$isEnrolled) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You need to be enrolled in this course to view this assignment. <a href="course.php?id=' . (int) $assignment['course_id'] . '">Go to course page</a></p>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($isTeacher && isset($_POST['grade_submission'])) {
        $submissionId = (int) $_POST['grade_submission'];
        $grade = max(0, min(100, (int) ($_POST['grade'] ?? 0)));
        $feedback = trim($_POST['feedback'] ?? '');
        $pdo->prepare('UPDATE assignment_submissions SET grade = ?, feedback = ?, graded_at = NOW() WHERE id = ? AND assignment_id = ?')
            ->execute([$grade, $feedback ?: null, $submissionId, $assignmentId]);

        $studentRow = $pdo->prepare('SELECT student_id FROM assignment_submissions WHERE id = ?');
        $studentRow->execute([$submissionId]);
        $studentId = (int) $studentRow->fetchColumn();
        if ($studentId) {
            notifyUser($pdo, $studentId, 'assignment_graded', $assignmentId, 1, function ($u) use ($assignment, $grade) {
                return [
                    'Your assignment was graded',
                    '<p style="margin:0 0 16px">"' . e($assignment['title']) . '" has been graded: <strong>' . $grade . '/100</strong>.</p>',
                    'View Feedback',
                    siteBaseUrl() . '/assignment.php?id=' . (int) $assignment['id'],
                ];
            });
        }
        flash('success', 'Grade saved.');
        redirect('assignment.php?id=' . $assignmentId);
    }

    if (!$isTeacher && $isEnrolled && isset($_POST['submit_assignment'])) {
        $content = trim($_POST['content'] ?? '');
        $upload = handleAttachmentUpload('submission_file', 'assignment-submissions');

        $existing = $pdo->prepare('SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?');
        $existing->execute([$assignmentId, $user['id']]);
        $isFirstSubmission = !$existing->fetch();

        if ($content === '' && !$upload) {
            $errors[] = 'Please write something or attach a file before submitting.';
        } else {
            $pdo->prepare(
                'INSERT INTO assignment_submissions (assignment_id, student_id, content, file_path, file_name, submitted_at)
                 VALUES (?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE content = ?, file_path = COALESCE(?, file_path), file_name = COALESCE(?, file_name), submitted_at = NOW(), grade = NULL, feedback = NULL, graded_at = NULL'
            )->execute([
                $assignmentId, $user['id'], $content, $upload['path'] ?? null, $upload['name'] ?? null,
                $content, $upload['path'] ?? null, $upload['name'] ?? null,
            ]);
            if ($isFirstSubmission) {
                awardPoints($pdo, $user['id'], 15, 'Submitted assignment "' . $assignment['title'] . '"');
            }
            flash('success', 'Assignment submitted!');
            redirect('assignment.php?id=' . $assignmentId);
        }
    }
}

$mySubmission = null;
if (!$isTeacher) {
    $ms = $pdo->prepare('SELECT * FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?');
    $ms->execute([$assignmentId, $user['id']]);
    $mySubmission = $ms->fetch() ?: null;
}

$allSubmissions = [];
if ($isTeacher) {
    $as = $pdo->prepare(
        "SELECT sub.*, COALESCE(s.display_name, s.name) AS student_name FROM assignment_submissions sub
         JOIN users s ON s.id = sub.student_id WHERE sub.assignment_id = ? ORDER BY sub.submitted_at DESC"
    );
    $as->execute([$assignmentId]);
    $allSubmissions = $as->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($assignment['title']) ?> — <?= e($assignment['course_title']) ?></title>
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
    <p style="font-size:.85rem;margin-bottom:.6rem">
        <?php if ($isTeacher): ?><a href="manage-assignments.php?course_id=<?= (int) $assignment['course_id'] ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Assignments</a>
        <?php else: ?><a href="course.php?id=<?= (int) $assignment['course_id'] ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($assignment['course_title']) ?></a><?php endif; ?>
    </p>
    <div class="dashboard-header">
        <h2><i data-lucide="file-edit" class="lucide-icon"></i> <?= e($assignment['title']) ?></h2>
        <p><?= e($assignment['course_title']) ?><?= $assignment['due_date'] ? ' · Due ' . e(date('M j, Y', strtotime($assignment['due_date']))) : '' ?></p>
    </div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <?php if ($assignment['description']): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.6rem">Instructions</h3>
        <p style="white-space:pre-wrap"><?= e($assignment['description']) ?></p>
    </div></div>
    <?php endif; ?>

    <?php if (!$isTeacher): ?>
        <div class="card"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:.8rem">
                <?= $mySubmission ? 'Your Submission' : 'Submit Your Work' ?>
                <?php if ($mySubmission && $mySubmission['grade'] !== null): ?>
                    <span class="badge badge-free" style="margin-left:.5rem">Grade: <?= (int) $mySubmission['grade'] ?>/100</span>
                <?php elseif ($mySubmission): ?>
                    <span class="badge badge-pending" style="margin-left:.5rem">Awaiting Grade</span>
                <?php endif; ?>
            </h3>
            <?php if ($mySubmission && $mySubmission['feedback']): ?>
                <div class="alert alert-info" style="margin-bottom:1rem"><strong>Teacher feedback:</strong> <?= e($mySubmission['feedback']) ?></div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Your Answer</label>
                    <textarea name="content" class="form-control" rows="6" placeholder="Write your submission here..."><?= e($mySubmission['content'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Attach a File (optional)</label>
                    <?php if ($mySubmission && $mySubmission['file_path']): ?>
                        <p style="font-size:.85rem;margin-bottom:.4rem"><i data-lucide="paperclip" class="lucide-icon"></i> Current file: <a href="<?= e($mySubmission['file_path']) ?>" target="_blank"><?= e($mySubmission['file_name']) ?></a></p>
                    <?php endif; ?>
                    <input type="file" name="submission_file" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
                </div>
                <button type="submit" name="submit_assignment" value="1" class="btn btn-primary"><?= $mySubmission ? 'Resubmit' : 'Submit Assignment' ?></button>
            </form>
        </div></div>
    <?php else: ?>
        <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Submissions (<?= count($allSubmissions) ?>)</h3>
        <?php if (!$allSubmissions): ?>
            <div class="card"><div class="empty-state"><div class="icon"><i data-lucide="inbox" class="lucide-icon"></i></div><h3>No submissions yet</h3></div></div>
        <?php else: ?>
            <?php foreach ($allSubmissions as $sub): ?>
            <div class="card" style="margin-bottom:1.2rem"><div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.6rem;flex-wrap:wrap;gap:.5rem">
                    <strong><?= e($sub['student_name']) ?></strong>
                    <span style="font-size:.78rem;color:var(--text-light)">Submitted <?= e(date('M j, Y g:i A', strtotime($sub['submitted_at']))) ?></span>
                </div>
                <?php if ($sub['content']): ?><p style="white-space:pre-wrap;margin-bottom:.6rem"><?= e($sub['content']) ?></p><?php endif; ?>
                <?php if ($sub['file_path']): ?><p style="margin-bottom:.8rem"><a href="<?= e($sub['file_path']) ?>" target="_blank" class="msg-attachment-file"><i data-lucide="file-text" class="lucide-icon"></i> <?= e($sub['file_name']) ?></a></p><?php endif; ?>
                <form method="post" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:.8rem">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Grade (0-100)</label>
                        <input type="number" name="grade" class="form-control" min="0" max="100" style="width:100px" value="<?= e($sub['grade'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="margin:0;flex:1;min-width:200px">
                        <label class="form-label">Feedback</label>
                        <input type="text" name="feedback" class="form-control" value="<?= e($sub['feedback'] ?? '') ?>" placeholder="Optional feedback for the student">
                    </div>
                    <button type="submit" name="grade_submission" value="<?= (int) $sub['id'] ?>" class="btn btn-primary btn-sm">Save Grade</button>
                </form>
            </div></div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
