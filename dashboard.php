<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (($user['role'] ?? '') === 'admin') redirect('admin.php');
if (($user['role'] ?? '') === 'customer_service') redirect('support-panel.php');

$meStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$meStmt->execute([$user['id']]);
$me = $meStmt->fetch();

$myFieldsStmt = $pdo->prepare(
    "SELECT f.id, f.name FROM user_learning_fields ulf JOIN fields_of_study f ON f.id = ulf.field_of_study_id
     WHERE ulf.user_id = ? ORDER BY f.name"
);
$myFieldsStmt->execute([$user['id']]);
$myFields = $myFieldsStmt->fetchAll();
$myFieldIds = array_column($myFields, 'id');
$myFieldNames = array_column($myFields, 'name');

$profileCompletion = profileCompletionPercent($pdo, $me);
$myPoints = getUserPoints($pdo, $user['id']);
$myBadges = $pdo->prepare(
    'SELECT b.code, b.name, b.description, b.icon, ub.earned_at FROM user_badges ub
     JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = ? ORDER BY ub.earned_at DESC'
);
$myBadges->execute([$user['id']]);
$myBadges = $myBadges->fetchAll();

$myCertificates = $pdo->prepare(
    'SELECT cert.certificate_code, cert.issued_at, c.title AS course_title
     FROM certificates cert JOIN courses c ON c.id = cert.course_id
     WHERE cert.student_id = ? ORDER BY cert.issued_at DESC'
);
$myCertificates->execute([$user['id']]);
$myCertificates = $myCertificates->fetchAll();

$recommended = [];
if ($myFieldIds) {
    $placeholders = implode(',', array_fill(0, count($myFieldIds), '?'));
    $recStmt = $pdo->prepare(
        "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon
         FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
         WHERE c.is_published = 1 AND c.moderation_status = 'approved' AND s.field_of_study_id IN ($placeholders)
           AND c.id NOT IN (SELECT course_id FROM enrollments WHERE student_id = ?)
         ORDER BY c.created_at DESC LIMIT 4"
    );
    $recStmt->execute([...$myFieldIds, $user['id']]);
    $recommended = $recStmt->fetchAll();
}

$dashBg = siteSetting($pdo, 'dashboard_banner_bg');
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — <?= e(SITE_NAME) ?></title>
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

<div class="dashboard-wrap">
    <div class="dashboard-header" <?php if ($dashBg): ?>style="background-image:linear-gradient(90deg, rgba(10,61,31,.95) 0%, rgba(10,61,31,.65) 60%, rgba(10,61,31,.35) 100%), url('<?= e($dashBg) ?>');background-size:cover;background-position:center"<?php endif; ?>>
        <h2><?= e(t('dash_welcome', ['name' => displayNameOf($user)])) ?></h2>
        <?php if ($me['occupation'] || $myFieldNames): ?>
            <p style="margin-bottom:.3rem">
                <?= e(trim(($me['occupation'] ? $me['occupation'] : '') . ($me['occupation'] && $myFieldNames ? ', ' : '') . implode(', ', $myFieldNames))) ?>
                &nbsp;·&nbsp; <a href="personalize.php" class="dashboard-header-link">Edit occupation and interests</a>
            </p>
        <?php else: ?>
            <p style="margin-bottom:.3rem"><a href="personalize.php" class="dashboard-header-link"><i data-lucide="sparkles" class="lucide-icon"></i> <?= e(t('dash_add_interests')) ?></a></p>
        <?php endif; ?>
        <p><?= isApprovedTeacher($user) ? e(t('dash_teacher_tagline')) : e(t('dash_student_tagline')) ?></p>
        <span class="dashboard-role"><?= e(isApprovedTeacher($user) ? 'Teacher' : roleLabel($user['role'] ?? 'student')) ?></span>
    </div>

    <?php if ($profileCompletion < 100): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;margin-bottom:.5rem;flex-wrap:wrap">
            <strong style="font-size:.92rem"><i data-lucide="user-check" class="lucide-icon"></i> Your profile is <?= $profileCompletion ?>% complete</strong>
            <a href="edit-profile.php" class="btn btn-outline btn-sm">Complete Your Profile</a>
        </div>
        <div class="profile-progress-track"><div class="profile-progress-fill" style="width:<?= $profileCompletion ?>%"></div></div>
        <p style="font-size:.8rem;color:var(--text-light);margin-top:.5rem">A complete profile helps us recommend better courses and helps teachers/classmates know who you are.</p>
    </div></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem;margin-bottom:<?= $myBadges ? '1rem' : '0' ?>">
            <strong style="font-size:.92rem"><i data-lucide="zap" class="lucide-icon"></i> <?= e(t('dash_points', ['points' => number_format($myPoints)])) ?></strong>
            <span style="font-size:.78rem;color:var(--text-light)"><?= e(t('dash_badges_earned', ['count' => count($myBadges)])) ?></span>
        </div>
        <?php if ($myBadges): ?>
        <div style="display:flex;flex-wrap:wrap;gap:.8rem">
            <?php foreach ($myBadges as $b): ?>
            <div style="text-align:center;width:90px" data-tip="<?= e($b['description']) ?>">
                <div style="width:52px;height:52px;border-radius:50%;background:var(--cream);border:1.5px solid var(--gold);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--green-deep);margin:0 auto .4rem"><?= catIcon($b['icon']) ?></div>
                <div style="font-size:.72rem;font-weight:600;line-height:1.25"><?= e($b['name']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p style="font-size:.85rem;color:var(--text-light)">No badges yet — enroll in a course, complete lessons, or join a class discussion to start earning points and badges.</p>
        <?php endif; ?>
    </div></div>

    <?php if ($myCertificates): ?>
    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <strong style="font-size:.92rem;display:block;margin-bottom:1rem"><i data-lucide="award" class="lucide-icon"></i> My Certificates</strong>
        <div style="display:flex;flex-direction:column;gap:.6rem">
            <?php foreach ($myCertificates as $cert): ?>
            <a href="certificate.php?code=<?= e($cert['certificate_code']) ?>" class="lesson-item" style="text-decoration:none;color:inherit">
                <div class="step-num"><i data-lucide="award" class="lucide-icon"></i></div>
                <div style="flex:1">
                    <div style="font-weight:600;font-size:.9rem"><?= e($cert['course_title']) ?></div>
                    <div style="font-size:.78rem;color:var(--text-light)">Issued <?= e(date('M j, Y', strtotime($cert['issued_at']))) ?></div>
                </div>
                <i data-lucide="arrow-right" class="lucide-icon"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div></div>
    <?php endif; ?>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <?php if ($recommended): ?>
    <h3 style="font-size:1.1rem;color:var(--green-deep);margin-bottom:1rem"><i data-lucide="sparkles" class="lucide-icon"></i> Recommended for <?= e(implode(', ', $myFieldNames)) ?></h3>
    <div class="grid-2" style="margin-bottom:2rem">
        <?php foreach ($recommended as $c): ?>
        <a href="course.php?id=<?= (int) $c['id'] ?>" class="card" style="text-decoration:none;color:inherit">
            <div class="card-body">
                <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                <div class="card-title"><?= e($c['title']) ?></div>
                <div style="font-size:.85rem;color:var(--text-light)"><i data-lucide="user" class="lucide-icon"></i> <?= e($c['teacher_name']) ?></div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (isApprovedTeacher($user)): ?>
        <?php
        $stmt = $pdo->prepare(
            "SELECT c.*, s.name AS subject_name, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
             FROM courses c LEFT JOIN subjects s ON s.id = c.subject_id
             WHERE c.teacher_id = ? ORDER BY c.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $myTeachingCourses = $stmt->fetchAll();
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem">
            <h3 style="font-size:1.1rem;color:var(--green-deep)"><?= e(t('dash_my_courses', ['count' => count($myTeachingCourses)])) ?></h3>
            <div style="display:flex;gap:.5rem">
                <a href="single-upload.php" class="btn btn-outline btn-sm"><i data-lucide="upload" class="lucide-icon"></i> Single Upload</a>
                <a href="add-course.php" class="btn btn-primary btn-sm">+ New Course</a>
            </div>
        </div>

        <?php if (!$myTeachingCourses): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="library" class="lucide-icon"></i></div><h3>You haven't created any courses yet</h3></div>
        <?php else: ?>
        <table class="table table-cards">
            <thead><tr><th>Title</th><th>Subject</th><th>Level</th><th>Price</th><th>Students</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($myTeachingCourses as $c): ?>
                <tr>
                    <td data-label="Title"><a href="course.php?id=<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a></td>
                    <td data-label="Subject"><?= e($c['subject_name'] ?? '—') ?></td>
                    <td data-label="Level"><span class="badge badge-<?= e($c['level']) ?>"><?= e(ucfirst($c['level'])) ?></span></td>
                    <td data-label="Price"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></td>
                    <td data-label="Students"><?= (int) $c['student_count'] ?></td>
                    <td data-label="Status">
                        <?php if ($c['moderation_status'] === 'pending'): ?>
                            <span class="badge badge-pending"><i data-lucide="clock" class="lucide-icon"></i> Pending Review</span>
                        <?php elseif ($c['moderation_status'] === 'rejected'): ?>
                            <span class="badge badge-paid"><i data-lucide="ban" class="lucide-icon"></i> Rejected</span>
                        <?php else: ?>
                            <span class="badge <?= $c['is_published'] ? 'badge-free' : 'badge-paid' ?>"><?= $c['is_published'] ? 'Published' : 'Draft' ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions" class="action-row">
                        <a href="edit-course.php?id=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Edit course" aria-label="Edit course"><i data-lucide="pencil" class="lucide-icon"></i></a>
                        <a href="add-lesson.php?course_id=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Add lesson" aria-label="Add lesson"><i data-lucide="plus" class="lucide-icon"></i></a>
                        <a href="course-students.php?id=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="View students" aria-label="View students">
                            <i data-lucide="users" class="lucide-icon"></i><?php if ((int) $c['student_count'] > 0): ?><span class="count-badge"><?= (int) $c['student_count'] ?></span><?php endif; ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div style="margin-top:2.5rem"></div>
    <?php endif; ?>

    <?php
    // Shown to everyone, including approved teachers -- teaching no longer
    // excludes someone from also being a learner.
    $stmt = $pdo->prepare(
        "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name,
                (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l2 ON l2.id = lp.lesson_id WHERE l2.course_id = c.id AND lp.student_id = ?) AS completed_count
         FROM enrollments e
         JOIN courses c ON c.id = e.course_id
         JOIN users u ON u.id = c.teacher_id
         LEFT JOIN subjects s ON s.id = c.subject_id
         WHERE e.student_id = ?
         ORDER BY e.enrolled_at DESC"
    );
    $stmt->execute([$user['id'], $user['id']]);
    $myEnrolledCourses = $stmt->fetchAll();
    ?>
    <h3 style="font-size:1.1rem;color:var(--green-deep);margin-bottom:1rem"><?= e(t('dash_my_enrolled', ['count' => count($myEnrolledCourses)])) ?></h3>

    <?php if (!$myEnrolledCourses): ?>
        <div class="empty-state">
            <div class="icon"><i data-lucide="graduation-cap" class="lucide-icon"></i></div>
            <h3>You haven't enrolled in any courses yet</h3>
            <p><a href="courses.php" class="btn btn-primary" style="margin-top:1rem">Browse Courses</a></p>
        </div>
    <?php else: ?>
    <div class="grid-2">
        <?php foreach ($myEnrolledCourses as $c): ?>
        <?php $pct = $c['lesson_count'] ? (int) round($c['completed_count'] / $c['lesson_count'] * 100) : 0; ?>
        <a href="course.php?id=<?= (int) $c['id'] ?>" class="card" style="text-decoration:none;color:inherit">
            <div class="card-body">
                <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                <div class="card-title"><?= e($c['title']) ?></div>
                <div style="font-size:.85rem;color:var(--text-light);margin-bottom:.6rem"><i data-lucide="user" class="lucide-icon"></i> <?= e($c['teacher_name']) ?></div>
                <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                <p style="font-size:.8rem;color:var(--text-light);margin-top:.4rem"><?= $pct ?>% complete (<?= (int) $c['completed_count'] ?>/<?= (int) $c['lesson_count'] ?> lessons)</p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
