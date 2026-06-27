<?php
require_once __DIR__ . '/db.php';
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, u.qualification, u.headline AS teacher_headline, u.bio AS teacher_bio, u.avatar AS teacher_avatar,
            s.id AS subject_id_full, s.name AS subject_name, s.icon AS subject_icon,
            f.id AS field_id, f.name AS field_name,
            e.name AS editor_name, e.role AS editor_role
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     LEFT JOIN fields_of_study f ON f.id = s.field_of_study_id
     LEFT JOIN users e ON e.id = c.updated_by
     WHERE c.id = ?'
);
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isOwnerOrAdmin = $user && ((int) $course['teacher_id'] === (int) $user['id'] || ($user['role'] ?? '') === 'admin');
if ($course['moderation_status'] !== 'approved' && !$isOwnerOrAdmin) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">This course is awaiting admin review and isn\'t public yet. <a href="courses.php">Go back</a></p>');
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC, id ASC');
$lessons->execute([$id]);
$lessons = $lessons->fetchAll();

$totalMinutes = array_sum(array_column($lessons, 'duration_minutes'));
$firstPreviewLesson = null;
foreach ($lessons as $l) { if ((int) $l['is_preview'] === 1) { $firstPreviewLesson = $l; break; } }

// Group lessons into curriculum sections, in the order each section first appears.
$curriculum = [];
foreach ($lessons as $l) {
    $sec = $l['section_title'] ?: 'Course Content';
    $curriculum[$sec][] = $l;
}

$quizzes = $pdo->prepare('SELECT * FROM quizzes WHERE course_id = ? ORDER BY sort_order ASC');
$quizzes->execute([$id]);
$quizzes = $quizzes->fetchAll();
foreach ($quizzes as &$qz) {
    $qz['best_attempt'] = null;
    if ($user) {
        $best = $pdo->prepare('SELECT *, (score / total * 100) AS percent FROM quiz_attempts WHERE quiz_id = ? AND student_id = ? ORDER BY percent DESC LIMIT 1');
        $best->execute([$qz['id'], $user['id']]);
        $qz['best_attempt'] = $best->fetch() ?: null;
    }
}
unset($qz);

$assignmentsList = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY created_at ASC');
$assignmentsList->execute([$id]);
$assignmentsList = $assignmentsList->fetchAll();
foreach ($assignmentsList as &$asn) {
    $asn['my_submission'] = null;
    if ($user) {
        $sub = $pdo->prepare('SELECT * FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?');
        $sub->execute([$asn['id'], $user['id']]);
        $asn['my_submission'] = $sub->fetch() ?: null;
    }
}
unset($asn);

$studentCount = $pdo->prepare('SELECT COUNT(*) c FROM enrollments WHERE course_id = ?');
$studentCount->execute([$id]);
$studentCount = $studentCount->fetch()['c'];

$ratingStmt = $pdo->prepare('SELECT COUNT(*) c, COALESCE(AVG(rating),0) avg_rating FROM course_reviews WHERE course_id = ?');
$ratingStmt->execute([$id]);
$ratingRow = $ratingStmt->fetch();
$reviewCount = (int) $ratingRow['c'];
$avgRating = round((float) $ratingRow['avg_rating'], 1);

// Same "Bestseller" definition used on the homepage/browse cards (top 3
// most-enrolled platform-wide, only if they actually have students) — so
// a course shows the badge consistently whether viewed as a card or here.
$bestsellerIds = $pdo->query(
    "SELECT c.id FROM courses c WHERE c.is_published = 1 AND c.moderation_status = 'approved'
     AND (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) > 0
     ORDER BY (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) DESC LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);

$ratingBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
if ($reviewCount > 0) {
    $bd = $pdo->prepare('SELECT rating, COUNT(*) c FROM course_reviews WHERE course_id = ? GROUP BY rating');
    $bd->execute([$id]);
    foreach ($bd->fetchAll() as $row) { $ratingBreakdown[(int) $row['rating']] = (int) $row['c']; }
}

$reviews = $pdo->prepare(
    "SELECT r.*, COALESCE(u.display_name, u.name) AS student_name FROM course_reviews r JOIN users u ON u.id = r.student_id
     WHERE r.course_id = ? ORDER BY r.created_at DESC LIMIT 10"
);
$reviews->execute([$id]);
$reviews = $reviews->fetchAll();

$teacherStats = $pdo->prepare(
    "SELECT COUNT(DISTINCT c.id) AS course_count, COUNT(DISTINCT e.student_id) AS student_count
     FROM courses c LEFT JOIN enrollments e ON e.course_id = c.id
     WHERE c.teacher_id = ? AND c.is_published = 1 AND c.moderation_status = 'approved'"
);
$teacherStats->execute([$course['teacher_id']]);
$teacherStats = $teacherStats->fetch();

$courseCardSelect = "c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
            (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
            (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
            (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating";

$moreByInstructor = $pdo->prepare(
    "SELECT $courseCardSelect FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE c.teacher_id = ? AND c.id != ? AND c.is_published = 1 AND c.moderation_status = 'approved'
     ORDER BY c.created_at DESC LIMIT 4"
);
$moreByInstructor->execute([$course['teacher_id'], $id]);
$moreByInstructor = $moreByInstructor->fetchAll();

$relatedCourses = [];
if ($course['subject_id_full']) {
    $relatedStmt = $pdo->prepare(
        "SELECT $courseCardSelect FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
         WHERE c.subject_id = ? AND c.id != ? AND c.is_published = 1 AND c.moderation_status = 'approved'
         ORDER BY (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) DESC LIMIT 4"
    );
    $relatedStmt->execute([$course['subject_id_full'], $id]);
    $relatedCourses = $relatedStmt->fetchAll();
}

$isEnrolled = false;
$inCart = false;
$completedLessons = [];
$myReview = null;
if ($user && canEnroll($user['role'] ?? null)) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $id]);
    $isEnrolled = (bool) $e->fetch();

    $ci = $pdo->prepare('SELECT 1 FROM cart_items WHERE student_id = ? AND course_id = ?');
    $ci->execute([$user['id'], $id]);
    $inCart = (bool) $ci->fetch();

    if ($isEnrolled) {
        $cp = $pdo->prepare('SELECT lesson_id FROM lesson_progress WHERE student_id = ?');
        $cp->execute([$user['id']]);
        $completedLessons = array_column($cp->fetchAll(), 'lesson_id');

        $mr = $pdo->prepare('SELECT * FROM course_reviews WHERE course_id = ? AND student_id = ?');
        $mr->execute([$id, $user['id']]);
        $myReview = $mr->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll'])) {
    requireAuth();
    verifyCsrf();
    // Free courses only -- paid courses must go through cart/checkout
    // (add_to_cart/buy_now below). This used to have no price check at
    // all, meaning a paid course could be "enrolled" for free; fixed here.
    if (canEnroll($user['role'] ?? null) && !$isEnrolled && (float) $course['price'] <= 0) {
        $pdo->prepare('INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)')->execute([$user['id'], $id]);
        awardPoints($pdo, $user['id'], 10, 'Enrolled in "' . $course['title'] . '"');

        // Only email the teacher at milestones, not for every single
        // enrollment — a popular course could otherwise flood their inbox.
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE course_id = ?');
        $countStmt->execute([$id]);
        $newCount = (int) $countStmt->fetchColumn();
        if (in_array($newCount, [1, 10, 50, 100, 500], true)) {
            notifyUser($pdo, (int) $course['teacher_id'], 'enrollment_milestone', $id, 60, function ($u) use ($course, $newCount) {
                $titleSafe = e($course['title']);
                $plural = $newCount === 1 ? 'student has' : 'students have';
                return [
                    $newCount . ' students now enrolled in "' . $course['title'] . '"',
                    '<p style="margin:0 0 16px">' . $newCount . ' ' . $plural . ' now enrolled in "' . $titleSafe . '". Keep up the great teaching!</p>',
                    'View Your Course',
                    siteBaseUrl() . '/course.php?id=' . (int) $course['id'],
                ];
            });
        }

        redirect('enrollment-success.php?course_id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_to_cart']) || isset($_POST['buy_now']))) {
    requireAuth();
    verifyCsrf();
    if (!canEnroll($user['role'] ?? null)) {
        flash('error', 'Teacher and admin accounts cannot enroll in courses.');
        redirect('course.php?id=' . $id);
    }
    if ($isEnrolled) {
        flash('error', 'You are already enrolled in this course.');
        redirect('course.php?id=' . $id);
    }
    $result = addToCart($pdo, $user['id'], $id);
    if (!$result['ok']) {
        flash('error', $result['error']);
        redirect('course.php?id=' . $id);
    }
    redirect(isset($_POST['buy_now']) ? 'checkout.php' : 'cart.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_lesson'])) {
    requireAuth();
    verifyCsrf();
    $lid = (int) $_POST['complete_lesson'];
    if ($isEnrolled && in_array($lid, array_column($lessons, 'id'), true)) {
        $ins = $pdo->prepare('INSERT IGNORE INTO lesson_progress (student_id, lesson_id) VALUES (?, ?)');
        $ins->execute([$user['id'], $lid]);
        if ($ins->rowCount() > 0) {
            awardPoints($pdo, $user['id'], 15, 'Completed a lesson in "' . $course['title'] . '"');
            $totalLessons = count($lessons);
            $doneCount = count($completedLessons) + 1;
            if ($totalLessons > 0 && $doneCount >= $totalLessons) {
                awardPoints($pdo, $user['id'], 50, 'Completed the course "' . $course['title'] . '"');
                issueCertificateIfEligible($pdo, $user['id'], $id);
            }
        }
        redirect('course.php?id=' . $id);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    requireAuth();
    verifyCsrf();
    $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0)));
    $comment = trim($_POST['comment'] ?? '');
    if ($isEnrolled && $rating > 0) {
        $isFirstReview = !$myReview;
        $pdo->prepare(
            'INSERT INTO course_reviews (course_id, student_id, rating, comment) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = ?, comment = ?'
        )->execute([$id, $user['id'], $rating, $comment, $rating, $comment]);
        if ($isFirstReview) {
            awardPoints($pdo, $user['id'], 10, 'Reviewed "' . $course['title'] . '"');
            awardPoints($pdo, $course['teacher_id'], $rating === 5 ? 15 : 5, 'Received a review on "' . $course['title'] . '"');
        }
        flash('success', 'Thanks for your review!');
        redirect('course.php?id=' . $id);
    }
}

$progressPct = $lessons ? (int) round(count($completedLessons) / count($lessons) * 100) : 0;
$objectives = $course['learning_objectives'] ? array_filter(array_map('trim', explode("\n", $course['learning_objectives']))) : [];
$requirements = $course['requirements'] ? array_filter(array_map('trim', explode("\n", $course['requirements']))) : [];

function starString(float $rating): string {
    $full = (int) floor($rating);
    $half = ($rating - $full) >= 0.5;
    return str_repeat('★', $full) . ($half ? '½' : '') . str_repeat('☆', 5 - $full - ($half ? 1 : 0));
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($course['title']) ?> — <?= e(SITE_NAME) ?></title>
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

<?php if (flash('success')): ?>
<div class="dashboard-wrap" style="max-width:1180px;margin-bottom:0;padding-bottom:0">
    <div class="alert alert-success"><?= e(flash('success')) ?></div>
</div>
<?php endif; ?>

<section class="course-hero-band">
    <div class="course-hero-inner">
        <?php if ($course['field_name'] || $course['subject_name']): ?>
        <p class="course-hero-breadcrumb">
            <?php if ($course['field_name']): ?><a href="courses.php?field=<?= (int) $course['field_id'] ?>"><?= e($course['field_name']) ?></a><?php endif; ?>
            <?php if ($course['field_name'] && $course['subject_name']): ?> <i data-lucide="chevron-right" class="lucide-icon" style="width:.8em;height:.8em"></i> <?php endif; ?>
            <?php if ($course['subject_name']): ?><a href="courses.php?subject=<?= (int) $course['subject_id_full'] ?>"><?= e($course['subject_name']) ?></a><?php endif; ?>
        </p>
        <?php endif; ?>

        <div style="display:flex;gap:.6rem;margin-bottom:.7rem;flex-wrap:wrap">
            <?php if (in_array($course['id'], $bestsellerIds ?? [], true)): ?><span class="course-bestseller" style="position:static">Bestseller</span><?php endif; ?>
            <span class="badge badge-<?= e($course['level']) ?>"><?= e(ucfirst($course['level'])) ?></span>
            <span class="badge" style="background:#f5f5f5;color:#555"><?= e($course['language']) ?></span>
            <?php if ($course['subject_name']): ?><span class="badge" style="background:#f5f5f5;color:#555"><?= e($course['subject_name']) ?></span><?php endif; ?>
        </div>
        <div style="display:flex;align-items:center;gap:.7rem;flex-wrap:wrap">
            <h1 class="course-hero-title"><?= e($course['title']) ?></h1>
            <?php if ($isOwnerOrAdmin): ?>
                <a href="edit-course.php?id=<?= $id ?>" class="btn btn-sm btn-outline" style="border-color:rgba(255,255,255,.4);color:var(--white)"><i data-lucide="pencil" class="lucide-icon"></i> Edit</a>
            <?php endif; ?>
        </div>
        <?php if ($course['description']): ?><p class="course-hero-desc"><?= e($course['description']) ?></p><?php endif; ?>
        <?php if ($isOwnerOrAdmin && $course['moderation_status'] !== 'approved'): ?>
            <div class="alert <?= $course['moderation_status'] === 'rejected' ? 'alert-error' : 'alert-info' ?>" style="margin-bottom:1rem">
                <?= $course['moderation_status'] === 'rejected' ? '<i data-lucide="ban" class="lucide-icon"></i> This course was rejected by an admin and is not visible to students.' : '<i data-lucide="clock" class="lucide-icon"></i> This course is awaiting admin review and is not yet visible to students.' ?>
            </div>
        <?php endif; ?>

        <div class="course-rating-row">
            <?php if ($reviewCount > 0): ?>
                <span class="score"><?= number_format($avgRating, 1) ?></span>
                <span class="stars"><?= starString($avgRating) ?></span>
                <span>(<?= $reviewCount ?> rating<?= $reviewCount === 1 ? '' : 's' ?>)</span>
            <?php else: ?>
                <span>No ratings yet</span>
            <?php endif; ?>
            <span>·</span>
            <span><i data-lucide="users" class="lucide-icon"></i> <?= (int) $studentCount ?> student<?= $studentCount == 1 ? '' : 's' ?></span>
            <?php if ($totalMinutes > 0): ?><span>·</span><span><i data-lucide="clock" class="lucide-icon"></i> <?= round($totalMinutes / 60, 1) ?> hours total</span><?php endif; ?>
            <span>·</span>
            <span><?= count($lessons) ?> lesson<?= count($lessons) == 1 ? '' : 's' ?></span>
        </div>
        <p class="course-hero-meta">Created by <?= e($course['teacher_name']) ?> · Last updated <?= date('M Y', strtotime($course['updated_at'] ?: $course['created_at'])) ?></p>
    </div>
</section>

<div class="dashboard-wrap" style="max-width:1180px">
    <div class="course-layout">
        <div class="course-main-col">

            <?php if ($objectives): ?>
            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:.8rem;color:var(--green-deep)"><?= e(t('course_what_learn')) ?></h3>
                <div class="objectives-grid">
                    <?php foreach ($objectives as $obj): ?>
                        <div class="objective-item"><i data-lucide="check" class="lucide-icon"></i><span><?= e($obj) ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div></div>
            <?php endif; ?>

            <?php if ($course['field_name'] || $course['subject_name']): ?>
            <div style="margin-bottom:1.5rem">
                <h3 style="font-size:.95rem;margin-bottom:.6rem;color:var(--green-deep)">Explore related topics</h3>
                <div class="chip-row" style="margin-bottom:0">
                    <?php if ($course['subject_name']): ?><a href="courses.php?subject=<?= (int) $course['subject_id_full'] ?>" class="cat-chip"><?= catIcon($course['subject_icon']) ?> <?= e($course['subject_name']) ?></a><?php endif; ?>
                    <?php if ($course['field_name']): ?><a href="courses.php?field=<?= (int) $course['field_id'] ?>" class="cat-chip"><?= e($course['field_name']) ?></a><?php endif; ?>
                    <a href="courses.php?q=<?= urlencode($course['level']) ?>" class="cat-chip"><?= e(ucfirst($course['level'])) ?> Level</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:baseline;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
                    <h3 style="font-size:1.1rem;color:var(--green-deep)"><i data-lucide="clipboard-list" class="lucide-icon"></i> <?= e(t('course_curriculum')) ?> (<?= count($lessons) ?> lessons<?= $totalMinutes > 0 ? ', ' . round($totalMinutes / 60, 1) . ' hours' : '' ?>)</h3>
                    <button type="button" onclick="toggleAllSections()" id="expandAllBtn" class="btn btn-sm btn-outline">Collapse all sections</button>
                </div>
                <?php foreach ($curriculum as $sectionTitle => $sectionLessons): ?>
                <?php $sectionMinutes = array_sum(array_column($sectionLessons, 'duration_minutes')); ?>
                <details class="curriculum-section" open>
                    <summary>
                        <span><?= e($sectionTitle) ?></span>
                        <span class="count"><?= count($sectionLessons) ?> lesson<?= count($sectionLessons) == 1 ? '' : 's' ?><?= $sectionMinutes > 0 ? ' · ' . $sectionMinutes . ' min' : '' ?></span>
                    </summary>
                    <?php foreach ($sectionLessons as $i => $l): ?>
                        <?php
                        $done = in_array($l['id'], $completedLessons);
                        $canAccess = $isEnrolled || $l['is_preview'] || $isOwnerOrAdmin;
                        ?>
                        <div class="curriculum-lesson-row">
                            <?php if ($done): ?><i data-lucide="check-circle-2" class="lucide-icon" style="color:#2e7d32"></i>
                            <?php elseif ($canAccess): ?><i data-lucide="play-circle" class="lucide-icon"></i>
                            <?php else: ?><i data-lucide="lock" class="lucide-icon"></i><?php endif; ?>
                            <?php if ($canAccess): ?>
                                <a href="lesson.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a>
                            <?php else: ?>
                                <span><?= e($l['title']) ?></span>
                            <?php endif; ?>
                            <?php if ($l['is_preview'] && !$isEnrolled): ?><span class="badge badge-free">Preview</span><?php endif; ?>
                            <?php if ((int) $l['duration_minutes'] > 0): ?><span class="dur"><?= (int) $l['duration_minutes'] ?> min</span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </details>
                <?php endforeach; ?>
            </div></div>

            <?php if ($quizzes): ?>
            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:1rem;color:var(--green-deep)"><i data-lucide="list-checks" class="lucide-icon"></i> Quizzes</h3>
                <ul class="lesson-list" style="margin:0 -1.2rem">
                    <?php foreach ($quizzes as $qz): ?>
                    <li class="lesson-item">
                        <i data-lucide="list-checks" class="lucide-icon"></i>
                        <div style="flex:1"><?= e($qz['title']) ?></div>
                        <?php if ($qz['best_attempt']): ?>
                            <span class="badge <?= $qz['best_attempt']['percent'] >= $qz['passing_score'] ? 'badge-free' : 'badge-pending' ?>">
                                <?= round((float) $qz['best_attempt']['percent']) ?>% <?= $qz['best_attempt']['percent'] >= $qz['passing_score'] ? '· Passed' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($isEnrolled): ?>
                            <a href="take-quiz.php?id=<?= (int) $qz['id'] ?>" class="btn btn-outline btn-sm"><?= $qz['best_attempt'] ? 'Retake' : 'Take Quiz' ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div></div>
            <?php endif; ?>

            <?php if ($assignmentsList): ?>
            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:1rem;color:var(--green-deep)"><i data-lucide="file-edit" class="lucide-icon"></i> Assignments</h3>
                <ul class="lesson-list" style="margin:0 -1.2rem">
                    <?php foreach ($assignmentsList as $asn): ?>
                    <li class="lesson-item">
                        <i data-lucide="file-edit" class="lucide-icon"></i>
                        <div style="flex:1">
                            <?= e($asn['title']) ?>
                            <?php if ($asn['due_date']): ?><span style="font-size:.78rem;color:var(--text-light)"> · Due <?= e(date('M j', strtotime($asn['due_date']))) ?></span><?php endif; ?>
                        </div>
                        <?php if ($asn['my_submission']): ?>
                            <?php if ($asn['my_submission']['grade'] !== null): ?>
                                <span class="badge badge-free">Grade: <?= (int) $asn['my_submission']['grade'] ?>/100</span>
                            <?php else: ?>
                                <span class="badge badge-pending">Submitted</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isEnrolled): ?>
                            <a href="assignment.php?id=<?= (int) $asn['id'] ?>" class="btn btn-outline btn-sm"><?= $asn['my_submission'] ? 'View' : 'Submit' ?></a>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div></div>
            <?php endif; ?>

            <?php if ($requirements): ?>
            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:.8rem;color:var(--green-deep)"><?= e(t('course_requirements')) ?></h3>
                <ul class="requirements-list">
                    <?php foreach ($requirements as $req): ?><li><?= e($req) ?></li><?php endforeach; ?>
                </ul>
            </div></div>
            <?php endif; ?>

            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:.8rem;color:var(--green-deep)"><?= e(t('course_description')) ?></h3>
                <p style="color:var(--text-mid);white-space:pre-line"><?= e($course['description']) ?></p>
                <?php if ($course['editor_name']): ?>
                    <div style="font-size:.78rem;color:var(--text-light);margin-top:1rem">
                        Last edited by <?= e($course['editor_name']) ?><?= $course['editor_role'] === 'admin' ? ' (Admin)' : '' ?>
                        on <?= date('M j, Y', strtotime($course['updated_at'])) ?>
                    </div>
                <?php endif; ?>
            </div></div>

            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:1rem;color:var(--green-deep)"><?= e(t('course_instructor')) ?></h3>
                <div class="instructor-card">
                    <div class="profile-avatar"><?= e(mb_substr($course['teacher_name'], 0, 1)) ?></div>
                    <div>
                        <a href="profile.php?id=<?= (int) $course['teacher_id'] ?>" style="font-weight:700;font-size:1.05rem"><?= e($course['teacher_name']) ?></a>
                        <?php if ($course['teacher_headline']): ?>
                            <div style="font-size:.88rem;color:var(--text-mid);margin-top:.1rem"><?= e($course['teacher_headline']) ?></div>
                        <?php endif; ?>
                        <div style="font-size:.85rem;color:var(--text-light);margin-top:.2rem"><?= e($course['qualification'] ?: 'Qualified Teacher') ?></div>
                        <div class="instructor-stats">
                            <span><i data-lucide="book-open" class="lucide-icon"></i> <?= (int) $teacherStats['course_count'] ?> course<?= $teacherStats['course_count'] == 1 ? '' : 's' ?></span>
                            <span><i data-lucide="users" class="lucide-icon"></i> <?= (int) $teacherStats['student_count'] ?> student<?= $teacherStats['student_count'] == 1 ? '' : 's' ?></span>
                        </div>
                        <?php if ($course['teacher_bio']): ?><p style="font-size:.86rem;color:var(--text-mid);margin-top:.4rem"><?= e($course['teacher_bio']) ?></p><?php endif; ?>
                        <?php if ($isEnrolled): ?>
                            <div style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap">
                                <a href="chat.php?with=<?= (int) $course['teacher_id'] ?>&course=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="message-circle" class="lucide-icon"></i> Message Teacher</a>
                                <a href="class-chat.php?course_id=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="users" class="lucide-icon"></i> Class Discussion</a>
                                <a href="course-qa.php?course_id=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="circle-help" class="lucide-icon"></i> Q&amp;A</a>
                            </div>
                        <?php elseif ($isOwnerOrAdmin): ?>
                            <div style="display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap">
                                <a href="class-chat.php?course_id=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="users" class="lucide-icon"></i> Class Discussion</a>
                                <a href="course-qa.php?course_id=<?= (int) $course['id'] ?>" class="btn btn-sm btn-outline"><i data-lucide="circle-help" class="lucide-icon"></i> Q&amp;A</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div></div>

            <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
                <h3 style="font-size:1.1rem;margin-bottom:1rem;color:var(--green-deep)"><?= e(t('course_reviews')) ?></h3>

                <?php if ($reviewCount > 0): ?>
                <div class="rating-summary">
                    <div style="text-align:center">
                        <div class="big-score"><?= number_format($avgRating, 1) ?></div>
                        <div class="course-rating-row" style="justify-content:center"><span class="stars"><?= starString($avgRating) ?></span></div>
                        <div style="font-size:.78rem;color:var(--text-light)">Course Rating</div>
                    </div>
                    <div style="flex:1;min-width:200px">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                            <?php $pct = $reviewCount > 0 ? round($ratingBreakdown[$star] / $reviewCount * 100) : 0; ?>
                            <div class="rating-bar-row">
                                <span><?= $star ?> ★</span>
                                <div class="rating-bar-track"><div class="rating-bar-fill" style="width:<?= $pct ?>%"></div></div>
                                <span><?= $pct ?>%</span>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($isEnrolled): ?>
                <form method="post" style="margin-bottom:1.5rem;padding:1rem;background:var(--cream);border-radius:var(--radius-sm)">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <label class="form-label"><?= $myReview ? 'Update your review' : 'Leave a review' ?></label>
                    <div style="display:flex;gap:.3rem;margin-bottom:.6rem" id="starPicker">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                            <label style="cursor:pointer;font-size:1.4rem;color:<?= ($myReview['rating'] ?? 0) >= $s ? 'var(--gold-dark)' : '#ccc' ?>" data-star="<?= $s ?>">
                                <input type="radio" name="rating" value="<?= $s ?>" style="display:none" <?= ($myReview['rating'] ?? 0) == $s ? 'checked' : '' ?>>★
                            </label>
                        <?php endfor; ?>
                    </div>
                    <textarea name="comment" class="form-control" placeholder="Share your experience with this course (optional)" style="margin-bottom:.6rem"><?= e($myReview['comment'] ?? '') ?></textarea>
                    <button type="submit" name="submit_review" value="1" class="btn btn-primary btn-sm"><?= $myReview ? 'Update Review' : 'Submit Review' ?></button>
                </form>
                <?php endif; ?>

                <?php if (!$reviews): ?>
                    <p style="color:var(--text-light);font-size:.9rem"><?= e(t('course_no_reviews')) ?></p>
                <?php else: ?>
                    <?php foreach ($reviews as $r): ?>
                    <div class="review-item">
                        <div class="review-item-head">
                            <div class="profile-avatar"><?= e(mb_substr($r['student_name'], 0, 1)) ?></div>
                            <div>
                                <div style="font-weight:600;font-size:.88rem"><?= e($r['student_name']) ?></div>
                                <div class="course-rating-row" style="margin-bottom:0"><span class="stars"><?= starString((float) $r['rating']) ?></span><span style="font-size:.78rem"><?= date('M j, Y', strtotime($r['created_at'])) ?></span></div>
                            </div>
                        </div>
                        <?php if ($r['comment']): ?><p style="font-size:.88rem;color:var(--text-mid)"><?= e($r['comment']) ?></p><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div></div>

            <?php if ($moreByInstructor): ?>
            <div style="margin-bottom:1.5rem">
                <h3 style="font-size:1.05rem;margin-bottom:1rem;color:var(--green-deep)">More courses by <?= e($course['teacher_name']) ?></h3>
                <div class="carousel-row">
                    <?php foreach ($moreByInstructor as $c): ?><?= renderCourseCard($c, $bestsellerIds) ?><?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($relatedCourses): ?>
            <div style="margin-bottom:1.5rem">
                <h3 style="font-size:1.05rem;margin-bottom:1rem;color:var(--green-deep)">More <?= e($course['subject_name']) ?> Courses</h3>
                <div class="carousel-row">
                    <?php foreach ($relatedCourses as $c): ?><?= renderCourseCard($c, $bestsellerIds) ?><?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="enroll-card">
            <div class="card">
                <div class="course-cover" style="position:relative">
                    <?php if ($course['cover_url']): ?><img src="<?= e($course['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($course['subject_icon']) ?><?php endif; ?>
                    <?php if ($firstPreviewLesson): ?>
                    <a href="lesson.php?id=<?= (int) $firstPreviewLesson['id'] ?>" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem;background:rgba(10,61,31,.35);color:var(--white);text-decoration:none">
                        <span style="width:48px;height:48px;border-radius:50%;background:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:center"><i data-lucide="play" class="lucide-icon" style="color:var(--green-deep);width:1.3em;height:1.3em"></i></span>
                        <span style="font-size:.8rem;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,.5)">Preview this course</span>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div style="font-size:1.6rem;font-weight:800;color:var(--green-deep);margin-bottom:.8rem"><?= $course['price'] > 0 ? '$' . number_format((float) $course['price']) : 'Free' ?></div>
                    <div style="font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:.5rem">This course includes:</div>
                    <div style="font-size:.85rem;color:var(--text-mid);display:flex;flex-direction:column;gap:.45rem">
                        <span><i data-lucide="clipboard-list" class="lucide-icon"></i> <?= count($lessons) ?> lessons</span>
                        <?php if ($totalMinutes > 0): ?><span><i data-lucide="clock" class="lucide-icon"></i> <?= round($totalMinutes / 60, 1) ?> hours of content</span><?php endif; ?>
                        <span><i data-lucide="signal" class="lucide-icon"></i> <?= e(ucfirst($course['level'])) ?> level</span>
                        <span><i data-lucide="languages" class="lucide-icon"></i> <?= e($course['language']) ?></span>
                        <span><i data-lucide="infinity" class="lucide-icon"></i> Lifetime access</span>
                        <span><i data-lucide="message-circle" class="lucide-icon"></i> Class discussion access</span>
                    </div>
                </div>
                <div class="card-footer">
                    <?php if (!$user): ?>
                        <a href="login.php" class="btn btn-primary btn-full">Login to Enroll</a>
                    <?php elseif (!canEnroll($user['role'] ?? null)): ?>
                        <div class="alert alert-info">Teacher and admin accounts can't enroll in courses.</div>
                    <?php elseif ($isEnrolled): ?>
                        <div class="alert alert-success"><i data-lucide="check" class="lucide-icon"></i> You are enrolled</div>
                        <div class="progress-bar"><div class="progress-fill" style="width:<?= $progressPct ?>%"></div></div>
                        <p style="font-size:.85rem;color:var(--text-light);margin-top:.4rem"><?= $progressPct ?>% complete (<?= count($completedLessons) ?>/<?= count($lessons) ?> lessons)</p>
                    <?php elseif ((float) $course['price'] <= 0): ?>
                        <form method="post">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="enroll" value="1" class="btn btn-primary btn-full"><?= e(t('course_enroll_now')) ?> — Free</button>
                        </form>
                    <?php elseif ($inCart): ?>
                        <a href="cart.php" class="btn btn-primary btn-full"><i data-lucide="shopping-cart" class="lucide-icon"></i> Go to Cart</a>
                    <?php elseif (!paymentGatewaysConfigured()): ?>
                        <div class="alert alert-info" style="font-size:.82rem">Online checkout isn't set up yet. <a href="feedback.php">Contact us</a> to arrange payment for this course.</div>
                    <?php else: ?>
                        <form method="post" style="display:flex;flex-direction:column;gap:.6rem">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="buy_now" value="1" class="btn btn-primary btn-full"><?= e(t('course_buy_now')) ?></button>
                            <button type="submit" name="add_to_cart" value="1" class="btn btn-outline btn-full"><i data-lucide="shopping-cart" class="lucide-icon"></i> <?= e(t('course_add_to_cart')) ?></button>
                        </form>
                        <p style="font-size:.78rem;color:var(--text-light);text-align:center;margin-top:.6rem"><i data-lucide="shield-check" class="lucide-icon"></i> Full lifetime access</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
function toggleAllSections() {
    const sections = document.querySelectorAll('.curriculum-section');
    const btn = document.getElementById('expandAllBtn');
    const shouldOpen = btn.textContent.trim() === 'Expand all sections';
    sections.forEach(function (s) { s.open = shouldOpen; });
    btn.textContent = shouldOpen ? 'Collapse all sections' : 'Expand all sections';
}
document.querySelectorAll('#starPicker label').forEach(function (label) {
    label.addEventListener('click', function () {
        var star = parseInt(label.getAttribute('data-star'), 10);
        document.querySelectorAll('#starPicker label').forEach(function (l, i) {
            l.style.color = (i + 1) <= star ? 'var(--gold-dark)' : '#ccc';
        });
    });
});
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
