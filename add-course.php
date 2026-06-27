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
    if ($language === 'Other') $language = trim($_POST['language_other'] ?? '') ?: 'Other';

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
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
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
                <select name="language" id="languageSelect" class="form-control" onchange="document.getElementById('languageOtherWrap').style.display = this.value === 'Other' ? 'block' : 'none'">
                    <option value="English">English</option>
                    <option value="Urdu">Urdu</option>
                    <option value="Persian">Persian</option>
                    <option value="Arabic">Arabic</option>
                    <option value="Other">Other</option>
                </select>
                <div id="languageOtherWrap" style="display:none;margin-top:.6rem">
                    <input type="text" name="language_other" class="form-control" placeholder="Enter language name">
                </div>
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
