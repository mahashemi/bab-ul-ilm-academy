<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$stmt = $pdo->prepare('SELECT action, ip_address, user_agent, created_at FROM account_activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->execute([$user['id']]);
$logs = $stmt->fetchAll();

function activityIcon(string $action): string {
    if (str_contains($action, 'Logged in')) return 'log-in';
    if (str_contains($action, 'Logged out')) return 'log-out';
    if (str_contains($action, 'Password')) return 'key-round';
    if (str_contains($action, 'Account created')) return 'user-plus';
    return 'activity';
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Activity — <?= e(SITE_NAME) ?></title>
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
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:760px">
    <div class="dashboard-header">
        <h2><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</h2>
        <p>Recent security-relevant activity on your account. If something here looks unfamiliar, <a href="forgot-password.php" style="color:var(--gold);text-decoration:underline">reset your password</a> right away.</p>
    </div>

    <div class="card">
        <?php if (!$logs): ?>
            <div class="empty-state"><div class="icon"><i data-lucide="shield-check" class="lucide-icon"></i></div><h3>No activity recorded yet</h3></div>
        <?php else: ?>
        <ul class="lesson-list">
            <?php foreach ($logs as $log): ?>
            <li class="lesson-item">
                <div class="step-num"><i data-lucide="<?= e(activityIcon($log['action'])) ?>" class="lucide-icon"></i></div>
                <div style="flex:1">
                    <div style="font-weight:600;font-size:.9rem"><?= e($log['action']) ?></div>
                    <div style="font-size:.78rem;color:var(--text-light)">
                        <?= e(date('M j, Y \a\t g:i A', strtotime($log['created_at']))) ?>
                        <?php if ($log['ip_address']): ?> · IP <?= e($log['ip_address']) ?><?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
