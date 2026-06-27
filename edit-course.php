<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isOwner = $course['teacher_id'] == $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$isActingForThisCourse = effectiveTeacherId($user) === (int) $course['teacher_id'];
if (!$isOwner && !$isAdmin && !$isActingForThisCourse) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You do not have permission to edit this course. <a href="course.php?id=' . $id . '">Go back</a></p>');
}

$step = $_GET['step'] ?? 'basics';
if (!in_array($step, ['basics', 'details', 'cover', 'curriculum', 'pricing', 'publish'], true)) $step = 'basics';

function fetchPreviewCard(PDO $pdo, int $courseId): ?array {
    $stmt = $pdo->prepare(
        "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
                (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
                (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
                (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating
         FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
         WHERE c.id = ?"
    );
    $stmt->execute([$courseId]);
    return $stmt->fetch() ?: null;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course'])) {
    verifyCsrf();
    $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([$id]);
    flash('success', 'Course deleted.');
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'basics') {
    verifyCsrf();
    $title       = trim($_POST['title'] ?? '');
    $subjectId   = (int) ($_POST['subject_id'] ?? 0);
    $level       = $_POST['level'] ?? 'beginner';
    $language    = trim($_POST['language'] ?? 'English');
    if ($language === 'Other') $language = trim($_POST['language_other'] ?? '') ?: 'Other';

    if (mb_strlen($title) < 5) $errors[] = 'Title must be at least 5 characters.';
    if (!in_array($level, ['beginner','intermediate','advanced'], true)) $errors[] = 'Invalid level.';

    if (!$errors) {
        $pdo->prepare(
            'UPDATE courses SET title=?, subject_id=?, level=?, language=?, updated_by=?, updated_at=NOW() WHERE id=?'
        )->execute([$title, $subjectId ?: null, $level, $language, $user['id'], $id]);
        flash('success', 'Basics saved. Next, add a description and what learners will gain.');
        redirect('edit-course.php?id=' . $id . '&step=details');
    }
    // Validation failed -- redisplay what the teacher just typed instead of stale DB values.
    $course['title'] = $title;
    $course['level'] = $level;
    $course['language'] = $language;
    $course['subject_id'] = $subjectId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'details') {
    verifyCsrf();
    $description = trim($_POST['description'] ?? '');
    $objectives  = trim($_POST['learning_objectives'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $textbook    = trim($_POST['textbook'] ?? '');

    if (mb_strlen($description) < 20) $errors[] = 'Description must be at least 20 characters.';

    if (!$errors) {
        $pdo->prepare(
            'UPDATE courses SET description=?, learning_objectives=?, requirements=?, textbook=?, updated_by=?, updated_at=NOW() WHERE id=?'
        )->execute([$description, $objectives ?: null, $requirements ?: null, $textbook ?: null, $user['id'], $id]);
        flash('success', 'Details saved. Next, add a cover image.');
        redirect('edit-course.php?id=' . $id . '&step=cover');
    }
    $course['description'] = $description;
    $course['learning_objectives'] = $objectives;
    $course['requirements'] = $requirements;
    $course['textbook'] = $textbook;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'cover') {
    verifyCsrf();
    $imagePath = handleImageUpload('cover', 'courses') ?? $course['cover_url'];
    $pdo->prepare('UPDATE courses SET cover_url=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute([$imagePath, $user['id'], $id]);
    flash('success', 'Cover image saved. Next, build your curriculum.');
    redirect('add-lesson.php?course_id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'pricing') {
    verifyCsrf();
    $price = (float) ($_POST['price'] ?? 0);
    if ($price < 0) $errors[] = 'Price cannot be negative.';
    if (!$errors) {
        $pdo->prepare('UPDATE courses SET price=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute([$price, $user['id'], $id]);
        flash('success', 'Pricing saved. Last step: publish.');
        redirect('edit-course.php?id=' . $id . '&step=publish');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'publish') {
    verifyCsrf();
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $pdo->prepare('UPDATE courses SET is_published=?, updated_by=?, updated_at=NOW() WHERE id=?')->execute([$isPublished, $user['id'], $id]);
    flash('success', 'Course settings saved.');
    redirect('edit-course.php?id=' . $id . '&step=publish');
}

// Re-fetch so every panel reflects current DB state -- skipped only when basics
// validation just failed, so the teacher's just-typed (invalid) input stays on screen.
if (!$errors) {
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$id]);
    $course = $stmt->fetch();
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC');
$lessons->execute([$id]);
$lessons = $lessons->fetchAll();

$quizCount = $pdo->prepare('SELECT COUNT(*) FROM quizzes WHERE course_id = ?');
$quizCount->execute([$id]);
$quizCount = (int) $quizCount->fetchColumn();

$assignmentCount = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE course_id = ?');
$assignmentCount->execute([$id]);
$assignmentCount = (int) $assignmentCount->fetchColumn();

$enrollmentCount = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = ?');
$enrollmentCount->execute([$id]);
$enrollmentCount = (int) $enrollmentCount->fetchColumn();

$previewCard = fetchPreviewCard($pdo, $id);
$completionPercent = courseCompletionPercent($course, count($lessons));

$stepTitles = [
    'basics' => 'Basics',
    'details' => 'Details',
    'cover' => 'Cover Image',
    'curriculum' => 'Curriculum',
    'pricing' => 'Pricing',
    'publish' => 'Publish',
];
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($stepTitles[$step]) ?> — Edit Course — <?= e(SITE_NAME) ?></title>
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

        <?php if ($user): ?>
            <a href="chat.php"><?= t('nav_messages') ?></a>
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><?= t('nav_new_course') ?></a><?php endif; ?>
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
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> <?= t('nav_new_course_plain') ?></a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> <?= t('nav_become_instructor') ?></a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                    <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_admin_panel') ?></a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn"><?= t('nav_login') ?></a>
        <?php endif; ?>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:1300px">
    <div class="dashboard-header">
        <h2><i data-lucide="pencil" class="lucide-icon"></i> <?= e($course['title']) ?></h2>
        <p><?= $isAdmin && !$isOwner ? 'You are editing this course as an admin.' : 'Update your course details below.' ?> <a href="tutorial.php" style="color:var(--gold);text-decoration:underline">View tutorial</a></p>
    </div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <div class="course-wizard-layout">
    <?= renderCourseWizardSidebar($id, $step) ?>
    <div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-bottom:.5rem">
            <strong style="font-size:.88rem"><i data-lucide="bar-chart-3" class="lucide-icon"></i> Course is <?= $completionPercent ?>% complete</strong>
        </div>
        <div class="profile-progress-track"><div class="profile-progress-fill" style="width:<?= $completionPercent ?>%"></div></div>
    </div></div>

    <?php if ($step === 'basics'): ?>
        <div class="card"><div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

                <div class="form-group">
                    <label class="form-label">Course Title</label>
                    <input type="text" name="title" class="form-control" value="<?= e($course['title']) ?>" required>
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
                                        <option value="<?= (int) $s['subject_id'] ?>" <?= $course['subject_id'] == $s['subject_id'] ? 'selected' : '' ?>><?= e($s['subject_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Level</label>
                        <select name="level" class="form-control">
                            <?php foreach (['beginner'=>'Beginner','intermediate'=>'Intermediate','advanced'=>'Advanced'] as $val=>$label): ?>
                                <option value="<?= $val ?>" <?= $course['level'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php $isStandardLang = in_array($course['language'], ['English', 'Urdu', 'Persian', 'Arabic'], true); ?>
                <div class="form-group">
                    <label class="form-label">Language</label>
                    <select name="language" id="languageSelect" class="form-control" onchange="document.getElementById('languageOtherWrap').style.display = this.value === 'Other' ? 'block' : 'none'">
                        <?php foreach (['English', 'Urdu', 'Persian', 'Arabic', 'Other'] as $opt): ?>
                            <option value="<?= e($opt) ?>" <?= ($isStandardLang && $course['language'] === $opt) || (!$isStandardLang && $opt === 'Other') ? 'selected' : '' ?>><?= e($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="languageOtherWrap" style="margin-top:.6rem;<?= $isStandardLang ? 'display:none' : '' ?>">
                        <input type="text" name="language_other" class="form-control" placeholder="Enter language name" value="<?= $isStandardLang ? '' : e($course['language']) ?>">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Save &amp; Continue</button>
            </form>
        </div></div>

    <?php elseif ($step === 'details'): ?>
        <div class="card"><div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" placeholder="Introduce the course in a paragraph or two — the topic, who it's for, and why it matters." required><?= e($course['description']) ?></textarea>
                    <div class="form-hint">The general pitch shown at the top of the course page.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">What Will Learners Gain? (one per line)</label>
                    <textarea name="learning_objectives" class="form-control" placeholder="e.g.&#10;Read Quran with correct Tajweed rules&#10;Identify the 28 Arabic letters and their articulation points"><?= e($course['learning_objectives'] ?? '') ?></textarea>
                    <div class="form-hint">Different from the description above: this becomes a bullet-point checklist of specific, concrete outcomes (skills/knowledge learners walk away with) — the description sells the course, this list proves it.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Requirements (one per line)</label>
                    <textarea name="requirements" class="form-control" placeholder="e.g.&#10;No prior knowledge needed&#10;A Quran copy (any edition)"><?= e($course['requirements'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label">Textbook / Reference Material (optional)</label>
                    <input type="text" name="textbook" class="form-control" placeholder="e.g. Nurani Qaida, 1st Edition" value="<?= e($course['textbook'] ?? '') ?>">
                    <div class="form-hint">Used to ground the AI lesson-writing helper in the right material.</div>
                </div>

                <button type="submit" class="btn btn-primary">Save &amp; Continue</button>
            </form>
        </div></div>

    <?php elseif ($step === 'cover'): ?>
        <div class="card"><div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Cover Image</label>
                    <?php if ($course['cover_url']): ?>
                        <img src="<?= e($course['cover_url']) ?>" style="max-width:280px;border-radius:8px;margin-bottom:.6rem;display:block">
                    <?php endif; ?>
                    <input type="file" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <div class="form-hint">JPG, PNG, or WEBP. Max 5MB. Leave blank to keep the current cover (or a subject icon if none set).<br>Recommended size: 1280×720 (16:9) — cropped to fill the catalog tile.</div>
                </div>
                <button type="submit" class="btn btn-primary">Save &amp; Continue</button>
            </form>
        </div></div>

        <?php if ($previewCard): ?>
        <div class="card" style="margin-top:1.5rem"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.4rem;color:var(--green-deep)"><i data-lucide="eye" class="lucide-icon"></i> Tile Preview</h3>
            <p style="font-size:.85rem;color:var(--text-light);margin-bottom:1rem">This is exactly how your course looks in the catalog.</p>
            <div style="max-width:300px"><?= renderCourseCard($previewCard) ?></div>
        </div></div>
        <?php endif; ?>

    <?php elseif ($step === 'curriculum'): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:<?= $lessons ? '1rem' : '0' ?>">
                <h3 style="font-size:1.05rem;color:var(--green-deep)"><i data-lucide="clipboard-list" class="lucide-icon"></i> Lessons (<?= count($lessons) ?>)</h3>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <a href="bulk-lessons.php?course_id=<?= $id ?>" class="btn btn-outline btn-sm"><i data-lucide="upload" class="lucide-icon"></i> Bulk Upload</a>
                    <a href="add-lesson.php?course_id=<?= $id ?>" class="btn btn-primary btn-sm"><i data-lucide="plus" class="lucide-icon"></i> Add / Manage Lessons</a>
                </div>
            </div>
            <?php if ($lessons): ?>
            <ul class="lesson-list" style="margin:0 -1.2rem">
                <?php foreach ($lessons as $i => $l): ?>
                <li class="lesson-item">
                    <div class="lesson-num"><?= $i + 1 ?></div>
                    <div class="lesson-title" style="flex:1"><a href="edit-lesson.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a></div>
                    <?php if ((int) $l['duration_minutes'] > 0): ?><span style="font-size:.78rem;color:var(--text-light)"><?= (int) $l['duration_minutes'] ?> min</span><?php endif; ?>
                    <a href="edit-lesson.php?id=<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Edit lesson" aria-label="Edit lesson" style="margin-left:.6rem"><i data-lucide="pencil" class="lucide-icon"></i></a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
                <p style="font-size:.88rem;color:var(--text-light)">No lessons yet — students can't take this course until you add at least one.</p>
            <?php endif; ?>
        </div></div>

        <div class="card"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.8rem;color:var(--green-deep)">Quizzes &amp; Assignments</h3>
            <div style="display:flex;gap:1rem;flex-wrap:wrap">
                <a href="manage-quizzes.php?course_id=<?= $id ?>" class="btn btn-outline btn-sm"><i data-lucide="list-checks" class="lucide-icon"></i> Quizzes (<?= $quizCount ?>)</a>
                <a href="manage-assignments.php?course_id=<?= $id ?>" class="btn btn-outline btn-sm"><i data-lucide="file-edit" class="lucide-icon"></i> Assignments (<?= $assignmentCount ?>)</a>
                <a href="bulk-assessments.php?course_id=<?= $id ?>" class="btn btn-outline btn-sm"><i data-lucide="upload" class="lucide-icon"></i> Bulk Upload Quizzes/Assignments</a>
            </div>
        </div></div>

    <?php elseif ($step === 'pricing'): ?>
        <div class="card"><div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Price ($) — 0 for free</label>
                    <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= e($course['price']) ?>">
                    <div class="form-hint">Students checkout through the platform's own Stripe/PayPal integration once configured — see course.php's buy box.</div>
                </div>
                <button type="submit" class="btn btn-primary">Save &amp; Continue</button>
            </form>
        </div></div>

    <?php elseif ($step === 'publish'): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label" style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="is_published" value="1" style="width:auto" <?= $course['is_published'] ? 'checked' : '' ?>>
                        Published (visible in course catalog)
                    </label>
                    <div class="form-hint">New courses still require admin review before they appear publicly, regardless of this toggle.</div>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
        </div></div>

        <?php if ($previewCard): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1.05rem;margin-bottom:.4rem;color:var(--green-deep)"><i data-lucide="eye" class="lucide-icon"></i> Tile Preview</h3>
            <div style="max-width:300px"><?= renderCourseCard($previewCard) ?></div>
        </div></div>
        <?php endif; ?>

        <div class="danger-zone-section">
            <div class="card" style="border-color:#c62828;background:#fff6f6"><div class="card-body">
                <h3 style="font-size:1.05rem;color:#c62828;margin-bottom:.6rem"><i data-lucide="triangle-alert" class="lucide-icon"></i> Danger Zone</h3>
                <p style="font-size:.85rem;color:var(--text-mid);margin-bottom:.9rem">
                    Deleting this course permanently removes it along with all <?= count($lessons) ?> lesson(s)<?= $enrollmentCount > 0 ? ', and unenrolls ' . $enrollmentCount . ' student(s)' : '' ?>. This cannot be undone.
                </p>
                <form method="post" onsubmit="return confirm('Permanently delete this course<?= $enrollmentCount > 0 ? ' and unenroll ' . $enrollmentCount . ' student(s)' : '' ?>? This cannot be undone.')">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <button type="submit" name="delete_course" value="1" class="btn" style="background:#c62828;color:#fff;border-color:#c62828"><i data-lucide="trash-2" class="lucide-icon"></i> Delete This Course</button>
                </form>
            </div></div>
        </div>
    <?php endif; ?>

    </div>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
