<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT c.*, COALESCE(u.display_name, u.name) AS teacher_name FROM courses c JOIN users u ON u.id = c.teacher_id WHERE c.id = ?');
$stmt->execute([$courseId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isTeacher = (int) $course['teacher_id'] === (int) $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$isEnrolled = false;
if (!$isTeacher && !$isAdmin) {
    $e = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $e->execute([$user['id'], $courseId]);
    $isEnrolled = (bool) $e->fetch();
}
$canModerate = $isTeacher || $isAdmin;

if (!$isTeacher && !$isAdmin && !$isEnrolled) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You need to be enrolled in this course to view its class discussion. <a href="course.php?id=' . (int) $courseId . '">Go to course page</a></p>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['post_message'])) {
        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            $isBroadcast = $canModerate && isset($_POST['as_announcement']) ? 1 : 0;
            $pdo->prepare('INSERT INTO class_messages (course_id, sender_id, body, is_broadcast) VALUES (?, ?, ?, ?)')
                ->execute([$courseId, $user['id'], $body, $isBroadcast]);
            $newMsgId = (int) $pdo->lastInsertId();

            // Announcements are rare and deliberate (teacher opts in via a
            // checkbox), so every enrolled student gets emailed — unlike
            // regular chat messages, which never trigger email at all.
            if ($isBroadcast) {
                $students = $pdo->prepare('SELECT student_id FROM enrollments WHERE course_id = ?');
                $students->execute([$courseId]);
                foreach ($students->fetchAll(PDO::FETCH_COLUMN) as $studentId) {
                    notifyUser($pdo, (int) $studentId, 'class_announcement', $courseId, 10, function ($u) use ($course, $body) {
                        return [
                            'New announcement in ' . $course['title'],
                            '<p style="margin:0 0 16px"><strong>' . e($course['title']) . '</strong> has a new announcement:</p>'
                                . '<p style="margin:0 0 16px;padding:12px 16px;background:#faf8f4;border-radius:8px;color:#1a1a1a">' . nl2br(e($body)) . '</p>',
                            'View Discussion',
                            siteBaseUrl() . '/class-chat.php?course_id=' . (int) $course['id'],
                        ];
                    });
                }
            }

            // Only scan/score messages from non-moderators — a teacher's own
            // posts aren't subject to their own moderation queue.
            if (!$canModerate) {
                $flags = scanMessageForFlags($pdo, $courseId, $user['id'], $body);
                if ($flags) {
                    recordMessageFlags($pdo, $newMsgId, $flags);
                    $penalty = 0;
                    foreach ($flags as $f) {
                        if ($f['flag_type'] === 'disrespect') $penalty -= 3;
                        elseif ($f['flag_type'] === 'spam') $penalty -= 1;
                    }
                    if ($penalty !== 0) adjustBehaviorScore($pdo, $user['id'], $courseId, $penalty);
                } else {
                    adjustBehaviorScore($pdo, $user['id'], $courseId, 1);
                    awardPoints($pdo, $user['id'], 5, 'Participated in class discussion for "' . $course['title'] . '"');
                }
            }
        }
        redirect('class-chat.php?course_id=' . $courseId);
    }

    if ($canModerate && isset($_POST['warn_message'])) {
        $mid = (int) $_POST['warn_message'];
        $pdo->prepare("UPDATE message_flags SET status='warned', reviewed_by=?, reviewed_at=NOW() WHERE message_id=? AND status='pending'")
            ->execute([$user['id'], $mid]);
        $pdo->prepare('INSERT INTO class_messages (course_id, sender_id, body, is_broadcast) VALUES (?, ?, ?, 1)')
            ->execute([$courseId, $user['id'], 'Reminder: please keep messages respectful and on-topic in this class discussion. — ' . $course['teacher_name']]);
        redirect('class-chat.php?course_id=' . $courseId);
    }

    if ($canModerate && isset($_POST['delete_message'])) {
        $mid = (int) $_POST['delete_message'];
        $pdo->prepare('UPDATE class_messages SET is_deleted=1 WHERE id=? AND course_id=?')->execute([$mid, $courseId]);
        $pdo->prepare("UPDATE message_flags SET status='deleted', reviewed_by=?, reviewed_at=NOW() WHERE message_id=?")
            ->execute([$user['id'], $mid]);
        redirect('class-chat.php?course_id=' . $courseId);
    }

    if ($canModerate && isset($_POST['dismiss_flag'])) {
        $fid = (int) $_POST['dismiss_flag'];
        $pdo->prepare("UPDATE message_flags SET status='dismissed', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
            ->execute([$user['id'], $fid]);
        redirect('class-chat.php?course_id=' . $courseId);
    }
}

$messages = $pdo->prepare(
    "SELECT m.*, COALESCE(u.display_name, u.name) AS sender_name, u.role AS sender_role FROM class_messages m
     JOIN users u ON u.id = m.sender_id
     WHERE m.course_id = ? ORDER BY m.created_at ASC"
);
$messages->execute([$courseId]);
$messages = $messages->fetchAll();

$pendingFlags = [];
if ($canModerate) {
    $flagStmt = $pdo->prepare(
        "SELECT * FROM message_flags WHERE status = 'pending' AND message_id IN
         (SELECT id FROM class_messages WHERE course_id = ?)"
    );
    $flagStmt->execute([$courseId]);
    foreach ($flagStmt->fetchAll() as $f) {
        $pendingFlags[(int) $f['message_id']][] = $f;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Class Discussion — <?= e($course['title']) ?></title>
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
        <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><?= t('nav_new_course') ?></a><?php endif; ?>
        <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><?= t('nav_become_instructor') ?></a><?php endif; ?>
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
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_admin_panel') ?></a><?php endif; ?>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:900px">
    <p style="font-size:.85rem;margin-bottom:.6rem"><a href="course.php?id=<?= $courseId ?>"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to <?= e($course['title']) ?></a></p>
    <div class="dashboard-header" style="margin-bottom:1.2rem">
        <h2><i data-lucide="message-circle" class="lucide-icon"></i> Class Discussion</h2>
        <p><?= e($course['title']) ?> <?= $canModerate ? '· You are moderating this discussion' : '' ?></p>
        <?php if ($canModerate): ?><a href="course-students.php?id=<?= $courseId ?>" class="dashboard-header-link" style="display:inline-block;margin-top:.4rem"><i data-lucide="bar-chart-3" class="lucide-icon"></i> View Class Report</a><?php endif; ?>
    </div>

    <?php if (!$messages): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="message-circle" class="lucide-icon"></i></div><h3>No messages yet</h3><p>Be the first to say something in this class.</p></div>
    <?php else: ?>
    <div class="card" style="margin-bottom:1rem">
        <div class="card-body" style="max-height:520px;overflow-y:auto;display:flex;flex-direction:column;gap:1rem">
            <?php foreach ($messages as $m): ?>
                <?php if ($m['is_deleted'] && !$canModerate) continue; ?>
                <?php $flagsForMsg = $pendingFlags[(int) $m['id']] ?? []; ?>
                <div style="display:flex;gap:.7rem;<?= $m['is_deleted'] ? 'opacity:.5' : '' ?>">
                    <div class="profile-avatar" style="width:36px;height:36px;font-size:.85rem;flex-shrink:0;<?= $m['is_broadcast'] ? 'background:var(--gold)' : '' ?>"><?= e(mb_substr($m['sender_name'], 0, 1)) ?></div>
                    <div style="flex:1;min-width:0">
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                            <span style="font-weight:700;font-size:.85rem"><?= e($m['sender_name']) ?></span>
                            <?php if ((int) $m['sender_id'] === (int) $course['teacher_id']): ?><span class="badge badge-free" style="font-size:.65rem">Teacher</span><?php endif; ?>
                            <?php if ($m['is_broadcast']): ?><span class="badge" style="background:#fff8e1;color:#e65100;font-size:.65rem">Announcement</span><?php endif; ?>
                            <span style="font-size:.75rem;color:var(--text-light)"><?= chatTime($m['created_at']) ?></span>
                        </div>
                        <?php if ($m['is_deleted']): ?>
                            <p style="font-size:.85rem;color:var(--text-light);font-style:italic;margin-top:.2rem">Message removed by moderator.</p>
                        <?php else: ?>
                            <p style="font-size:.9rem;color:var(--text-mid);margin-top:.2rem;white-space:pre-line"><?= e($m['body']) ?></p>
                        <?php endif; ?>

                        <?php if ($canModerate && $flagsForMsg): ?>
                        <div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:var(--radius-sm);padding:.5rem .7rem;margin-top:.4rem">
                            <div style="font-size:.78rem;color:#e65100;font-weight:600;margin-bottom:.3rem"><i data-lucide="triangle-alert" class="lucide-icon"></i> Flagged: <?= e(implode('; ', array_column($flagsForMsg, 'reason'))) ?></div>
                            <?php if (!$m['is_deleted']): ?>
                            <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                                <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="warn_message" value="<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline">Warn Class</button></form>
                                <form method="post" style="display:inline" onsubmit="return confirm('Delete this message?')"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="delete_message" value="<?= (int) $m['id'] ?>" class="btn btn-sm btn-outline">Delete</button></form>
                                <?php foreach ($flagsForMsg as $f): ?>
                                <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="dismiss_flag" value="<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline">Dismiss</button></form>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <textarea name="body" class="form-control" rows="2" placeholder="<?= $canModerate ? 'Message the class...' : 'Say something to the class...' ?>" required></textarea>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.6rem">
                <?php if ($canModerate): ?>
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.82rem;color:var(--text-mid);cursor:pointer">
                    <input type="checkbox" name="as_announcement" value="1" style="width:auto">
                    Send as announcement
                </label>
                <?php else: ?><span></span><?php endif; ?>
                <button type="submit" name="post_message" value="1" class="btn btn-primary btn-sm">Send</button>
            </div>
        </form>
    </div></div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
