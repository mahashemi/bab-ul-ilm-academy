<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Access denied. Admins only. <a href="index.php">Go back</a></p>');
}

// ── CSV Export ─────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    $map = [
        'users'   => ['sql' => 'SELECT id, name, email, role, phone, country, qualification, is_approved, created_at FROM users ORDER BY id', 'file' => 'babulilm_users.csv'],
        'courses' => ['sql' => "SELECT c.id, c.title, u.name AS teacher, s.name AS subject, c.level, c.language, c.price, c.is_published,
                                        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students, c.created_at
                                 FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id ORDER BY c.id", 'file' => 'babulilm_courses.csv'],
        'feedback' => ['sql' => 'SELECT id, name, email, message, is_read, created_at FROM feedback ORDER BY id DESC', 'file' => 'babulilm_feedback.csv'],
    ];
    if (isset($map[$type])) {
        $rows = $pdo->query($map[$type]['sql'])->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $map[$type]['file'] . '"');
        $out = fopen('php://output', 'w');
        if ($rows) fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }
}

// ── Actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['toggle_approved'])) {
        $pdo->prepare('UPDATE users SET is_approved = 1 - is_approved WHERE id = ?')->execute([(int) $_POST['toggle_approved']]);
    } elseif (isset($_POST['toggle_verified'])) {
        $pdo->prepare('UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?')->execute([(int) $_POST['toggle_verified']]);
    } elseif (isset($_POST['toggle_published'])) {
        $pdo->prepare('UPDATE courses SET is_published = 1 - is_published WHERE id = ?')->execute([(int) $_POST['toggle_published']]);
    } elseif (isset($_POST['approve_course'])) {
        $cid = (int) $_POST['approve_course'];
        $courseRow = $pdo->prepare('SELECT teacher_id, title, moderation_status FROM courses WHERE id = ?');
        $courseRow->execute([$cid]);
        $courseRow = $courseRow->fetch();
        $pdo->prepare("UPDATE courses SET moderation_status = 'approved', is_published = 1 WHERE id = ?")->execute([$cid]);
        if ($courseRow && $courseRow['moderation_status'] !== 'approved') {
            awardPoints($pdo, (int) $courseRow['teacher_id'], 25, 'Course "' . $courseRow['title'] . '" was approved');
            notifyUser($pdo, (int) $courseRow['teacher_id'], 'course_approved', $cid, 1, function ($u) use ($courseRow, $cid) {
                $titleSafe = e($courseRow['title']);
                return [
                    'Your course was approved!',
                    '<p style="margin:0 0 16px">Great news — "' . $titleSafe . '" has been reviewed and approved. It\'s now live in the course catalog and students can enroll.</p>',
                    'View Your Course',
                    siteBaseUrl() . '/course.php?id=' . $cid,
                ];
            });
        }
    } elseif (isset($_POST['reject_course'])) {
        $cid = (int) $_POST['reject_course'];
        $courseRow = $pdo->prepare('SELECT teacher_id, title, moderation_status FROM courses WHERE id = ?');
        $courseRow->execute([$cid]);
        $courseRow = $courseRow->fetch();
        $pdo->prepare("UPDATE courses SET moderation_status = 'rejected', is_published = 0 WHERE id = ?")->execute([$cid]);
        if ($courseRow && $courseRow['moderation_status'] !== 'rejected') {
            notifyUser($pdo, (int) $courseRow['teacher_id'], 'course_rejected', $cid, 1, function ($u) use ($courseRow, $cid) {
                $titleSafe = e($courseRow['title']);
                return [
                    'Your course needs changes',
                    '<p style="margin:0 0 16px">Your course "' . $titleSafe . '" was reviewed but isn\'t approved yet. Please check it for any guideline issues and update it for re-review.</p>',
                    'Edit Your Course',
                    siteBaseUrl() . '/edit-course.php?id=' . $cid,
                ];
            });
        }
    } elseif (isset($_POST['remind_teacher'])) {
        $cid = (int) $_POST['remind_teacher'];
        $courseRow = $pdo->prepare(
            'SELECT c.title, u.id AS teacher_id, u.name AS teacher_name, u.email AS teacher_email FROM courses c JOIN users u ON u.id = c.teacher_id WHERE c.id = ?'
        );
        $courseRow->execute([$cid]);
        $courseRow = $courseRow->fetch();
        if ($courseRow) {
            sendCourseReminderEmail($pdo, $courseRow['teacher_email'], $courseRow['teacher_name'], $courseRow['title'], $cid, (int) $user['id']);
            flash('success', 'Reminder email sent to ' . $courseRow['teacher_name'] . '.');
        }
    } elseif (isset($_POST['set_role']) && $_POST['set_role'] !== '') {
        $targetId = (int) $_POST['user_id'];
        $newRole = $_POST['set_role'];
        if ($targetId !== (int) $user['id'] && in_array($newRole, ['student','teacher','parent','institution','admin','customer_service'], true)) {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
        }
    } elseif (isset($_POST['add_field'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO fields_of_study (name, icon) VALUES (?, ?)')->execute([$name, $icon]);
        }
    } elseif (isset($_POST['edit_field'])) {
        $pdo->prepare('UPDATE fields_of_study SET name=?, icon=? WHERE id=?')
            ->execute([trim($_POST['name']), trim($_POST['icon']), (int) $_POST['edit_field']]);
    } elseif (isset($_POST['delete_field'])) {
        $pdo->prepare('DELETE FROM fields_of_study WHERE id = ?')->execute([(int) $_POST['delete_field']]);
    } elseif (isset($_POST['add_subject'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $fieldId = (int) ($_POST['field_of_study_id'] ?? 0) ?: null;
        if ($name !== '') {
            $pdo->prepare('INSERT INTO subjects (field_of_study_id, name, icon) VALUES (?, ?, ?)')->execute([$fieldId, $name, $icon]);
        }
    } elseif (isset($_POST['edit_subject'])) {
        $fieldId = (int) ($_POST['field_of_study_id'] ?? 0) ?: null;
        $pdo->prepare('UPDATE subjects SET name=?, icon=?, field_of_study_id=? WHERE id=?')
            ->execute([trim($_POST['name']), trim($_POST['icon']), $fieldId, (int) $_POST['edit_subject']]);
    } elseif (isset($_POST['delete_subject'])) {
        $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([(int) $_POST['delete_subject']]);
    } elseif (isset($_POST['save_settings'])) {
        foreach (['SITE_NAME', 'SITE_TAGLINE', 'SITE_AFFILIATION', 'HOME_HERO_HEADLINE'] as $key) {
            $val = trim($_POST[$key] ?? '');
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?')
                ->execute([$key, $val, $val]);
        }
        flash('success', 'Settings updated.');
    } elseif (isset($_POST['upload_site_image'])) {
        $slot = $_POST['upload_site_image'];
        if (in_array($slot, ['home_hero_bg', 'dashboard_banner_bg'], true)) {
            $path = handleImageUpload('image', 'site');
            if ($path) {
                $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?')
                    ->execute([$slot, $path, $path]);
                flash('success', 'Image updated.');
            } else {
                flash('success', 'Upload failed — please use a JPG, PNG, or WEBP under 5MB.');
            }
        }
    } elseif (isset($_POST['remove_site_image'])) {
        $slot = $_POST['remove_site_image'];
        if (in_array($slot, ['home_hero_bg', 'dashboard_banner_bg'], true)) {
            $pdo->prepare('DELETE FROM settings WHERE setting_key = ?')->execute([$slot]);
        }
    } elseif (isset($_POST['toggle_feedback_read'])) {
        $pdo->prepare('UPDATE feedback SET is_read = 1 - is_read WHERE id = ?')->execute([(int) $_POST['toggle_feedback_read']]);
    } elseif (isset($_POST['delete_feedback'])) {
        $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int) $_POST['delete_feedback']]);
    } elseif (isset($_POST['admin_dismiss_flag'])) {
        $pdo->prepare("UPDATE message_flags SET status='dismissed', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$user['id'], (int) $_POST['admin_dismiss_flag']]);
    } elseif (isset($_POST['admin_delete_flagged'])) {
        $fid = (int) $_POST['admin_delete_flagged'];
        $msgId = (int) $_POST['admin_delete_flagged_message_id'];
        $pdo->prepare('UPDATE class_messages SET is_deleted=1 WHERE id=?')->execute([$msgId]);
        $pdo->prepare("UPDATE message_flags SET status='deleted', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$user['id'], $fid]);
    } elseif (isset($_POST['save_ai_prompt'])) {
        $key = $_POST['save_ai_prompt'];
        $text = trim($_POST['template_text'] ?? '');
        if (array_key_exists($key, aiPromptDefaults()) && $text !== '') {
            $pdo->prepare('UPDATE ai_prompt_templates SET template_text = ?, updated_by = ?, updated_at = NOW() WHERE template_key = ?')
                ->execute([$text, $user['id'], $key]);
            flash('success', 'Prompt updated.');
        }
    } elseif (isset($_POST['reset_ai_prompt'])) {
        $key = $_POST['reset_ai_prompt'];
        $defaults = aiPromptDefaults();
        if (isset($defaults[$key])) {
            $pdo->prepare('UPDATE ai_prompt_templates SET template_text = ?, updated_by = ?, updated_at = NOW() WHERE template_key = ?')
                ->execute([$defaults[$key]['template_text'], $user['id'], $key]);
            flash('success', 'Prompt reset to default.');
        }
    }
    redirect('admin.php?tab=' . ($_GET['tab'] ?? 'users'));
}

$tab = $_GET['tab'] ?? 'users';

$stats = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM users WHERE role='teacher') AS teachers,
            (SELECT COUNT(*) FROM users WHERE role='student') AS students,
            (SELECT COUNT(*) FROM courses) AS total_courses,
            (SELECT COUNT(*) FROM courses WHERE is_published=1) AS published_courses,
            (SELECT COUNT(*) FROM enrollments) AS total_enrollments"
)->fetch();

$users = $pdo->query("SELECT * FROM users WHERE role != 'admin' ORDER BY created_at DESC")->fetchAll();
$courses = $pdo->query(
    "SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name, u.name AS teacher_legal_name, u.email AS teacher_email, s.name AS subject_name,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     ORDER BY c.created_at DESC"
)->fetchAll();
$pendingCourses = array_values(array_filter($courses, fn($c) => $c['moderation_status'] === 'pending'));

// Filters for the Courses tab only -- applied in-memory rather than a
// separate SQL query, since $courses is already fully loaded for the
// Pending tab/tab-count-badges and admin course lists are small enough
// that this is simpler than threading filter params through the query.
$courseLevelFilter  = $_GET['course_level'] ?? '';
$courseStatusFilter = $_GET['course_status'] ?? '';
$courseLessonFilter = $_GET['course_lessons'] ?? '';
$coursePriceFilter  = $_GET['course_price'] ?? '';
$filteredCourses = array_values(array_filter($courses, function ($c) use ($courseLevelFilter, $courseStatusFilter, $courseLessonFilter, $coursePriceFilter) {
    if ($courseLevelFilter !== '' && $c['level'] !== $courseLevelFilter) return false;
    if ($courseStatusFilter !== '' && $c['moderation_status'] !== $courseStatusFilter) return false;
    if ($courseLessonFilter === 'none' && (int) $c['lesson_count'] > 0) return false;
    if ($courseLessonFilter === 'has' && (int) $c['lesson_count'] === 0) return false;
    if ($coursePriceFilter === 'free' && (float) $c['price'] > 0) return false;
    if ($coursePriceFilter === 'paid' && (float) $c['price'] == 0) return false;
    return true;
}));
$fieldsOfStudy = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();
$subjects = $pdo->query(
    'SELECT s.*, f.name AS field_name FROM subjects s LEFT JOIN fields_of_study f ON f.id = s.field_of_study_id ORDER BY f.name, s.name'
)->fetchAll();
$currentSettings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$feedback = $pdo->query('SELECT * FROM feedback ORDER BY created_at DESC')->fetchAll();
// All distinct conversations platform-wide, for admin oversight — pairs are normalized
// (lower id, higher id) so each conversation appears once regardless of who sent last.
$allConvos = $pdo->query(
    "SELECT pair.u1, pair.u2, pair.message_count, pair.last_at, usr1.name AS u1_name, usr2.name AS u2_name,
            (SELECT body FROM messages m2
             WHERE (m2.sender_id = pair.u1 AND m2.receiver_id = pair.u2) OR (m2.sender_id = pair.u2 AND m2.receiver_id = pair.u1)
             ORDER BY m2.created_at DESC LIMIT 1) AS last_msg
     FROM (
        SELECT LEAST(sender_id, receiver_id) AS u1, GREATEST(sender_id, receiver_id) AS u2,
               COUNT(*) AS message_count, MAX(created_at) AS last_at
        FROM messages GROUP BY u1, u2
     ) pair
     JOIN users usr1 ON usr1.id = pair.u1
     JOIN users usr2 ON usr2.id = pair.u2
     ORDER BY pair.last_at DESC"
)->fetchAll();

$flaggedMessages = $pdo->query(
    "SELECT mf.*, cm.body, cm.course_id, cm.is_deleted, COALESCE(u.display_name, u.name) AS sender_name, c.title AS course_title
     FROM message_flags mf
     JOIN class_messages cm ON cm.id = mf.message_id
     JOIN users u ON u.id = cm.sender_id
     JOIN courses c ON c.id = cm.course_id
     WHERE mf.status = 'pending'
     ORDER BY mf.created_at DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"> <small style="color:var(--gold)">ADMIN</small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Site</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
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
                <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                <a href="support-panel.php"><i data-lucide="headset" class="lucide-icon"></i> Support Panel</a>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<section class="course-hero-band">
    <div class="course-hero-inner">
        <h1 class="course-hero-title" style="font-size:1.6rem;margin-bottom:.3rem"><i data-lucide="wrench" class="lucide-icon"></i> Admin Panel</h1>
        <p class="course-hero-meta" style="font-size:.95rem">Manage teachers, students, and courses.</p>
    </div>
</section>

<div class="dashboard-wrap" style="max-width:1100px">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['teachers'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Teachers</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['students'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Students</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['published_courses'] ?> / <?= (int) $stats['total_courses'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Published / Total Courses</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['total_enrollments'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Total Enrollments</div>
        </div>
    </div>

    <div class="tabs">
        <a href="?tab=users" class="tab-btn <?= $tab === 'users' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="users" class="lucide-icon"></i> Users (<?= count($users) ?>)</a>
        <a href="?tab=pending" class="tab-btn <?= $tab === 'pending' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="clock" class="lucide-icon"></i> Pending Review (<?= count($pendingCourses) ?>)</a>
        <a href="?tab=courses" class="tab-btn <?= $tab === 'courses' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="library" class="lucide-icon"></i> Courses (<?= count($courses) ?>)</a>
        <a href="?tab=subjects" class="tab-btn <?= $tab === 'subjects' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="tag" class="lucide-icon"></i> Subjects (<?= count($subjects) ?>)</a>
        <a href="?tab=settings" class="tab-btn <?= $tab === 'settings' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="settings" class="lucide-icon"></i> Settings</a>
        <a href="?tab=feedback" class="tab-btn <?= $tab === 'feedback' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="message-circle" class="lucide-icon"></i> Feedback (<?= count($feedback) ?>)</a>
        <a href="?tab=messages" class="tab-btn <?= $tab === 'messages' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="eye" class="lucide-icon"></i> All Chats (<?= count($allConvos) ?>)</a>
        <a href="?tab=flags" class="tab-btn <?= $tab === 'flags' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="triangle-alert" class="lucide-icon"></i> Flagged Messages (<?= count($flaggedMessages) ?>)</a>
        <a href="?tab=ai_prompts" class="tab-btn <?= $tab === 'ai_prompts' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center"><i data-lucide="sparkles" class="lucide-icon"></i> AI Prompts</a>
    </div>

    <?php if ($tab === 'pending'): ?>
        <?php if (!$pendingCourses): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="check-circle-2" class="lucide-icon"></i></div><h3>No courses awaiting review</h3></div>
        <?php else: ?>
        <div class="grid-2">
            <?php foreach ($pendingCourses as $c): ?>
            <div class="card"><div class="card-body">
                <h3 style="font-size:1.05rem;margin-bottom:.3rem"><a href="course.php?id=<?= (int) $c['id'] ?>" target="_blank"><?= e($c['title']) ?></a></h3>
                <p style="font-size:.85rem;color:var(--text-mid);margin-bottom:.6rem">By <?= e($c['teacher_name']) ?> · <?= e($c['subject_name'] ?? 'No subject') ?></p>
                <div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
                    <span class="badge badge-<?= e($c['level']) ?>"><?= e(ucfirst($c['level'])) ?></span>
                    <span class="badge <?= $c['price'] == 0 ? 'badge-free' : 'badge-paid' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
                    <span class="badge <?= (int) $c['lesson_count'] === 0 ? 'badge-paid' : 'badge-free' ?>"><i data-lucide="clipboard-list" class="lucide-icon"></i> <?= (int) $c['lesson_count'] ?> lesson<?= $c['lesson_count'] == 1 ? '' : 's' ?></span>
                </div>
                <?php if ((int) $c['lesson_count'] === 0): ?>
                <form method="post" style="margin-bottom:.6rem"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="remind_teacher" value="<?= (int) $c['id'] ?>" class="btn btn-outline btn-full btn-sm"><i data-lucide="mail" class="lucide-icon"></i> Remind Teacher to Add Lessons</button></form>
                <?php endif; ?>
                <div style="display:flex;gap:.5rem">
                    <form method="post" style="flex:1"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="approve_course" value="<?= (int) $c['id'] ?>" class="btn btn-green btn-full btn-sm"><i data-lucide="check-circle-2" class="lucide-icon"></i> Approve</button></form>
                    <form method="post" style="flex:1" onsubmit="return confirm('Reject this course? It will not be visible to students.')"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="reject_course" value="<?= (int) $c['id'] ?>" class="btn btn-outline btn-full btn-sm" style="color:#c00;border-color:#c00"><i data-lucide="x" class="lucide-icon"></i> Reject</button></form>
                    <a href="chat.php?with=<?= (int) $c['teacher_id'] ?>&course=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Message teacher" aria-label="Message teacher"><i data-lucide="message-circle" class="lucide-icon"></i></a>
                </div>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php elseif ($tab === 'courses'): ?>
        <form method="get" class="filter-bar">
            <input type="hidden" name="tab" value="courses">
            <select name="course_level" class="form-control" style="flex:1;min-width:130px" onchange="this.form.submit()">
                <option value="">All Levels</option>
                <?php foreach (['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $courseLevelFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="course_status" class="form-control" style="flex:1;min-width:130px" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $courseStatusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <select name="course_lessons" class="form-control" style="flex:1;min-width:140px" onchange="this.form.submit()">
                <option value="">Any Lesson Count</option>
                <option value="none" <?= $courseLessonFilter === 'none' ? 'selected' : '' ?>>No Lessons</option>
                <option value="has" <?= $courseLessonFilter === 'has' ? 'selected' : '' ?>>Has Lessons</option>
            </select>
            <select name="course_price" class="form-control" style="flex:1;min-width:110px" onchange="this.form.submit()">
                <option value="">Any Price</option>
                <option value="free" <?= $coursePriceFilter === 'free' ? 'selected' : '' ?>>Free</option>
                <option value="paid" <?= $coursePriceFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            </select>
            <noscript><button type="submit" class="btn btn-primary btn-sm">Apply</button></noscript>
            <?php if ($courseLevelFilter || $courseStatusFilter || $courseLessonFilter || $coursePriceFilter): ?>
                <a href="?tab=courses" class="btn btn-outline btn-sm">Clear</a>
            <?php endif; ?>
            <a href="?export=courses" class="btn btn-outline btn-sm" style="margin-left:auto"><i data-lucide="download" class="lucide-icon"></i> Download CSV</a>
        </form>
        <p class="section-sub"><?= count($filteredCourses) ?> of <?= count($courses) ?> course(s) shown</p>
        <table class="table">
            <thead><tr><th>Title</th><th>Teacher</th><th>Subject</th><th>Level</th><th>Price</th><th>Lessons</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($filteredCourses as $c): ?>
                <tr>
                    <td><a href="course.php?id=<?= (int) $c['id'] ?>" target="_blank"><?= e($c['title']) ?></a></td>
                    <td><?= e($c['teacher_name']) ?></td>
                    <td><?= e($c['subject_name'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= e($c['level']) ?>"><?= e(ucfirst($c['level'])) ?></span></td>
                    <td><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></td>
                    <td><span class="badge <?= (int) $c['lesson_count'] === 0 ? 'badge-paid' : 'badge-free' ?>"><?= (int) $c['lesson_count'] ?></span></td>
                    <td><?= (int) $c['student_count'] ?></td>
                    <td>
                        <span class="badge <?= $c['moderation_status'] === 'approved' ? 'badge-free' : ($c['moderation_status'] === 'rejected' ? 'badge-paid' : 'badge-pending') ?>"><?= e(ucfirst($c['moderation_status'])) ?></span>
                        <?php if ($c['moderation_status'] === 'approved'): ?><span class="badge <?= $c['is_published'] ? 'badge-free' : 'badge-paid' ?>"><?= $c['is_published'] ? 'Published' : 'Draft' ?></span><?php endif; ?>
                    </td>
                    <td class="action-row">
                        <a href="edit-course.php?id=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Edit course" aria-label="Edit course"><i data-lucide="pencil" class="lucide-icon"></i></a>
                        <a href="course-students.php?id=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="View students" aria-label="View students">
                            <i data-lucide="users" class="lucide-icon"></i><?php if ((int) $c['student_count'] > 0): ?><span class="count-badge"><?= (int) $c['student_count'] ?></span><?php endif; ?>
                        </a>
                        <a href="chat.php?with=<?= (int) $c['teacher_id'] ?>&course=<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Message teacher" aria-label="Message teacher"><i data-lucide="message-circle" class="lucide-icon"></i></a>
                        <?php if ((int) $c['lesson_count'] === 0): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Send a setup reminder email to this teacher?')"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="remind_teacher" value="<?= (int) $c['id'] ?>" class="icon-btn" data-tip="Remind teacher to add lessons" aria-label="Remind teacher to add lessons"><i data-lucide="mail" class="lucide-icon"></i></button></form>
                        <?php endif; ?>
                        <?php if ($c['moderation_status'] === 'approved'): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_published" value="<?= (int) $c['id'] ?>" class="icon-btn" data-tip="<?= $c['is_published'] ? 'Unpublish' : 'Publish' ?>" aria-label="<?= $c['is_published'] ? 'Unpublish' : 'Publish' ?>"><?= $c['is_published'] ? '<i data-lucide="ban" class="lucide-icon"></i>' : '<i data-lucide="check-circle-2" class="lucide-icon"></i>' ?></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'subjects'): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">Fields of Study (top-level grouping)</h3>
            <p style="font-size:.78rem;color:var(--text-light);margin-bottom:.8rem">Icon must be a name from <a href="https://lucide.dev/icons" target="_blank" rel="noopener">lucide.dev/icons</a> (e.g. "moon-star", "graduation-cap").</p>
            <form method="post" style="display:grid;grid-template-columns:1fr 100px auto;gap:.6rem;align-items:end;margin-bottom:1.2rem">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group" style="margin:0"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group" style="margin:0"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" placeholder="e.g. moon-star"></div>
                <button type="submit" name="add_field" value="1" class="btn btn-primary">+ Add Field</button>
            </form>
            <table class="table" style="margin:0">
                <thead><tr><th>Icon</th><th>Name</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($fieldsOfStudy as $f): $ffid = 'field-' . (int) $f['id']; ?>
                    <tr>
                        <td><input type="text" name="icon" form="<?= $ffid ?>" value="<?= e($f['icon']) ?>" class="form-control" style="width:70px;padding:.4rem"></td>
                        <td><input type="text" name="name" form="<?= $ffid ?>" value="<?= e($f['name']) ?>" class="form-control" style="padding:.4rem"></td>
                        <td class="action-row">
                            <form method="post" id="<?= $ffid ?>" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                                <button type="submit" name="edit_field" value="<?= (int) $f['id'] ?>" class="icon-btn" data-tip="Save" aria-label="Save"><i data-lucide="save" class="lucide-icon"></i></button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this field of study? Subjects in it will become ungrouped.')" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                                <button type="submit" name="delete_field" value="<?= (int) $f['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete" aria-label="Delete"><i data-lucide="trash-2" class="lucide-icon"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>

        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">+ Add New Subject</h3>
            <p style="font-size:.78rem;color:var(--text-light);margin-bottom:.8rem">Icon must be a name from <a href="https://lucide.dev/icons" target="_blank" rel="noopener">lucide.dev/icons</a> (e.g. "book-open", "calculator").</p>
            <form method="post" style="display:grid;grid-template-columns:1fr 1fr 100px auto;gap:.6rem;align-items:end">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Field of Study</label>
                    <select name="field_of_study_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($fieldsOfStudy as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group" style="margin:0"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" placeholder="e.g. book-open"></div>
                <button type="submit" name="add_subject" value="1" class="btn btn-primary">+ Add</button>
            </form>
        </div></div>

        <table class="table">
            <thead><tr><th>Icon</th><th>Name</th><th>Field of Study</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($subjects as $s): $fid = 'subj-' . (int) $s['id']; ?>
                <tr>
                    <td><input type="text" name="icon" form="<?= $fid ?>" value="<?= e($s['icon']) ?>" class="form-control" style="width:70px;padding:.4rem"></td>
                    <td><input type="text" name="name" form="<?= $fid ?>" value="<?= e($s['name']) ?>" class="form-control" style="padding:.4rem"></td>
                    <td>
                        <select name="field_of_study_id" form="<?= $fid ?>" class="form-control" style="padding:.4rem">
                            <option value="">— None —</option>
                            <?php foreach ($fieldsOfStudy as $f): ?>
                                <option value="<?= (int) $f['id'] ?>" <?= (int) $s['field_of_study_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="action-row">
                        <form method="post" id="<?= $fid ?>" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="edit_subject" value="<?= (int) $s['id'] ?>" class="icon-btn" data-tip="Save" aria-label="Save"><i data-lucide="save" class="lucide-icon"></i></button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this subject? Courses using it will become uncategorized.')" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="delete_subject" value="<?= (int) $s['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete" aria-label="Delete"><i data-lucide="trash-2" class="lucide-icon"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'settings'): ?>
        <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
        <div class="card"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">Site Branding</h3>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Site Name</label>
                    <input type="text" name="SITE_NAME" class="form-control" value="<?= e($currentSettings['SITE_NAME'] ?? SITE_NAME) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tagline</label>
                    <input type="text" name="SITE_TAGLINE" class="form-control" value="<?= e($currentSettings['SITE_TAGLINE'] ?? SITE_TAGLINE) ?>">
                    <div class="form-hint">Plain text only — used in the browser tab title and search-engine/social share previews. Not shown on the homepage itself (see Homepage Headline below for that).</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Affiliation (shown below the site name in the header)</label>
                    <input type="text" name="SITE_AFFILIATION" class="form-control" value="<?= e($currentSettings['SITE_AFFILIATION'] ?? SITE_AFFILIATION) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Homepage Headline</label>
                    <textarea name="HOME_HERO_HEADLINE" class="form-control" rows="3"><?= e($currentSettings['HOME_HERO_HEADLINE'] ?? HOME_HERO_HEADLINE) ?></textarea>
                    <div class="form-hint">The large headline on the homepage. Press Enter for a line break. Basic HTML is allowed — e.g. wrap part of the text in <code>&lt;span&gt;...&lt;/span&gt;</code> to make it gold, matching the original style.</div>
                </div>
                <button type="submit" name="save_settings" value="1" class="btn btn-primary">Save Settings</button>
            </form>
        </div></div>

        <?php $heroBg = siteSetting($pdo, 'home_hero_bg'); ?>
        <div class="card" style="margin-top:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:.4rem">Homepage Hero Background</h3>
            <p style="font-size:.8rem;color:var(--text-light);margin-bottom:1rem">Shown behind the headline on the main homepage. Recommended: wide image, at least 1600x500.</p>
            <?php if ($heroBg): ?>
                <img src="<?= e($heroBg) ?>" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:var(--radius);margin-bottom:1rem">
            <?php else: ?>
                <div style="width:100%;height:140px;border-radius:var(--radius);margin-bottom:1rem;background:var(--cream);display:flex;align-items:center;justify-content:center;color:var(--text-light);font-size:.85rem">No image set — using default</div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:center">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required style="flex:1;font-size:.82rem">
                <button type="submit" name="upload_site_image" value="home_hero_bg" class="btn btn-primary btn-sm">Upload</button>
            </form>
            <?php if ($heroBg): ?>
            <form method="post" onsubmit="return confirm('Remove this image and revert to the default?')" style="margin-top:.5rem">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <button type="submit" name="remove_site_image" value="home_hero_bg" class="btn btn-outline btn-sm">Remove</button>
            </form>
            <?php endif; ?>
        </div></div>

        <?php $dashBg = siteSetting($pdo, 'dashboard_banner_bg'); ?>
        <div class="card" style="margin-top:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:.4rem">Dashboard Welcome Banner</h3>
            <p style="font-size:.8rem;color:var(--text-light);margin-bottom:1rem">Shown behind "Welcome, [name]" on the student/teacher dashboard. A dark gradient is applied over the left side so text stays readable. Recommended: wide image, at least 1600x400.</p>
            <?php if ($dashBg): ?>
                <img src="<?= e($dashBg) ?>" alt="" style="width:100%;height:140px;object-fit:cover;border-radius:var(--radius);margin-bottom:1rem">
            <?php else: ?>
                <div style="width:100%;height:140px;border-radius:var(--radius);margin-bottom:1rem;background:var(--cream);display:flex;align-items:center;justify-content:center;color:var(--text-light);font-size:.85rem">No image set — using default gradient</div>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" style="display:flex;gap:.6rem;align-items:center">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required style="flex:1;font-size:.82rem">
                <button type="submit" name="upload_site_image" value="dashboard_banner_bg" class="btn btn-primary btn-sm">Upload</button>
            </form>
            <?php if ($dashBg): ?>
            <form method="post" onsubmit="return confirm('Remove this image and revert to the default?')" style="margin-top:.5rem">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <button type="submit" name="remove_site_image" value="dashboard_banner_bg" class="btn btn-outline btn-sm">Remove</button>
            </form>
            <?php endif; ?>
        </div></div>
    <?php elseif ($tab === 'feedback'): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
            <a href="?export=feedback" class="btn btn-outline btn-sm"><i data-lucide="download" class="lucide-icon"></i> Download CSV</a>
        </div>
        <?php if (!$feedback): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="message-circle" class="lucide-icon"></i></div><h3>No feedback yet</h3></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>From</th><th>Email</th><th>Message</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($feedback as $f): ?>
                <tr style="<?= $f['is_read'] ? 'opacity:.6' : '' ?>">
                    <td><?= e($f['name']) ?></td>
                    <td><?= e($f['email']) ?></td>
                    <td style="max-width:320px"><?= e($f['message']) ?></td>
                    <td><?= date('M j, Y', strtotime($f['created_at'])) ?></td>
                    <td><span class="badge <?= $f['is_read'] ? 'badge-paid' : 'badge-free' ?>"><?= $f['is_read'] ? 'Read' : 'New' ?></span></td>
                    <td class="action-row">
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_feedback_read" value="<?= (int) $f['id'] ?>" class="icon-btn" data-tip="<?= $f['is_read'] ? 'Mark unread' : 'Mark read' ?>" aria-label="<?= $f['is_read'] ? 'Mark unread' : 'Mark read' ?>"><?= $f['is_read'] ? '<i data-lucide="mail" class="lucide-icon"></i>' : '<i data-lucide="badge-check" class="lucide-icon"></i>' ?></button></form>
                        <form method="post" onsubmit="return confirm('Delete this feedback?')" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="delete_feedback" value="<?= (int) $f['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete" aria-label="Delete"><i data-lucide="trash-2" class="lucide-icon"></i></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php elseif ($tab === 'messages'): ?>
        <p class="section-sub">Read-only oversight of every conversation on the platform.</p>
        <?php if (!$allConvos): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="eye" class="lucide-icon"></i></div><h3>No conversations yet</h3></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Participants</th><th>Last Message</th><th>Messages</th><th>Last Activity</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($allConvos as $c): ?>
                <tr>
                    <td><?= e($c['u1_name']) ?> <i data-lucide="arrow-left-right" class="lucide-icon"></i> <?= e($c['u2_name']) ?></td>
                    <td style="max-width:280px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($c['last_msg'] ?? '') ?></td>
                    <td><?= (int) $c['message_count'] ?></td>
                    <td><?= date('M j, Y g:i A', strtotime($c['last_at'])) ?></td>
                    <td class="action-row">
                        <a href="admin-chat-view.php?u1=<?= (int) $c['u1'] ?>&u2=<?= (int) $c['u2'] ?>" class="icon-btn" data-tip="View conversation" aria-label="View conversation"><i data-lucide="eye" class="lucide-icon"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php elseif ($tab === 'flags'): ?>
        <p class="section-sub">Rule-based heuristics flag possible spam, disrespect, or links in class discussions for your review — nothing is removed automatically.</p>
        <?php if (!$flaggedMessages): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="shield-check" class="lucide-icon"></i></div><h3>No pending flags</h3></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>Course</th><th>Sender</th><th>Message</th><th>Flag Reason</th><th>When</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($flaggedMessages as $f): ?>
                <tr>
                    <td><a href="class-chat.php?course_id=<?= (int) $f['course_id'] ?>" target="_blank"><?= e($f['course_title']) ?></a></td>
                    <td><?= e($f['sender_name']) ?></td>
                    <td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($f['body']) ?></td>
                    <td><span class="badge badge-paid" style="font-size:.68rem"><?= e(ucfirst($f['flag_type'])) ?></span> <?= e($f['reason']) ?></td>
                    <td><?= date('M j, g:i A', strtotime($f['created_at'])) ?></td>
                    <td class="action-row">
                        <?php if (!$f['is_deleted']): ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Delete this message?')">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="admin_delete_flagged_message_id" value="<?= (int) $f['message_id'] ?>">
                            <button type="submit" name="admin_delete_flagged" value="<?= (int) $f['id'] ?>" class="icon-btn icon-btn-danger" data-tip="Delete message" aria-label="Delete message"><i data-lucide="trash-2" class="lucide-icon"></i></button>
                        </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="admin_dismiss_flag" value="<?= (int) $f['id'] ?>" class="icon-btn" data-tip="Dismiss flag" aria-label="Dismiss flag"><i data-lucide="check" class="lucide-icon"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php elseif ($tab === 'ai_prompts'): ?>
        <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
        <p class="section-sub">These prompts are shown to teachers throughout the course-authoring flow so they can ask an AI assistant (ChatGPT, Claude, DeepSeek, etc.) to write course content as a ready-to-upload CSV. Editing the wording here updates it everywhere immediately — no code deploy needed. Available placeholders for each prompt are listed below its box and are substituted automatically; don't remove the <code>{{double-brace}}</code> tokens unless you mean to.</p>
        <?php foreach (aiPromptDefaults() as $key => $default): $tpl = getAiPromptTemplate($pdo, $key); ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:.6rem"><?= e($tpl['label']) ?></h3>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <textarea name="template_text" class="form-control" rows="14" style="font-family:ui-monospace,'SF Mono',Consolas,monospace;font-size:.82rem" required><?= e($tpl['template_text']) ?></textarea>
                </div>
                <p style="font-size:.78rem;color:var(--text-light);margin-bottom:1rem"><i data-lucide="info" class="lucide-icon"></i> <?= e($tpl['placeholders_help']) ?></p>
                <div style="display:flex;gap:.6rem">
                    <button type="submit" name="save_ai_prompt" value="<?= e($key) ?>" class="btn btn-primary btn-sm">Save</button>
                    <button type="submit" name="reset_ai_prompt" value="<?= e($key) ?>" class="btn btn-outline btn-sm" onclick="return confirm('Reset this prompt to the original default? Your edits will be lost.')">Reset to Default</button>
                </div>
            </form>
        </div></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
            <a href="?export=users" class="btn btn-outline btn-sm"><i data-lucide="download" class="lucide-icon"></i> Download CSV</a>
        </div>
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Country</th><th>Qualification</th><th>Status</th><th>Verified</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= e($u['role']) ?>"><?= e(roleLabel($u['role'])) ?></span></td>
                    <td><?= e($u['country'] ?: '—') ?></td>
                    <td style="max-width:220px"><?= e($u['qualification'] ?: '—') ?></td>
                    <td><span class="badge <?= $u['is_approved'] ? 'badge-free' : 'badge-paid' ?>"><?= $u['is_approved'] ? 'Active' : 'Suspended' ?></span></td>
                    <td><?= $u['is_verified'] ? '<i data-lucide="check" class="lucide-icon"></i> Verified' : '—' ?></td>
                    <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td class="action-row">
                        <a href="chat.php?with=<?= (int) $u['id'] ?>" class="icon-btn" data-tip="Message" aria-label="Message"><i data-lucide="message-circle" class="lucide-icon"></i></a>
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_approved" value="<?= (int) $u['id'] ?>" class="icon-btn <?= $u['is_approved'] ? 'icon-btn-danger' : '' ?>" data-tip="<?= $u['is_approved'] ? 'Suspend' : 'Reactivate' ?>" aria-label="<?= $u['is_approved'] ? 'Suspend' : 'Reactivate' ?>"><?= $u['is_approved'] ? '<i data-lucide="pause" class="lucide-icon"></i>' : '<i data-lucide="play" class="lucide-icon"></i>' ?></button></form>
                        <?php if (!$u['is_verified']): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_verified" value="<?= (int) $u['id'] ?>" class="icon-btn" data-tip="Verify email" aria-label="Verify email"><i data-lucide="badge-check" class="lucide-icon"></i></button></form>
                        <?php endif; ?>
                        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <form method="post" onsubmit="return confirm('Change <?= e($u['name']) ?>\'s role?')">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <select name="set_role" onchange="this.form.submit()" class="form-control" style="padding:.3rem .5rem;font-size:.78rem;width:auto;display:inline-block">
                                <option value="">Change role…</option>
                                <option value="student" <?= $u['role']==='student'?'selected':'' ?>>Student</option>
                                <option value="teacher" <?= $u['role']==='teacher'?'selected':'' ?>>Teacher</option>
                                <option value="parent" <?= $u['role']==='parent'?'selected':'' ?>>Parent</option>
                                <option value="institution" <?= $u['role']==='institution'?'selected':'' ?>>Institution</option>
                                <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                                <option value="customer_service" <?= $u['role']==='customer_service'?'selected':'' ?>>Customer Service</option>
                            </select>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
