<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

if (isApprovedTeacher($me)) {
    redirect('dashboard.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (isset($_POST['reapply'])) {
        $pdo->prepare("UPDATE users SET teacher_status = 'none' WHERE id = ?")->execute([$user['id']]);
        redirect('become-instructor.php');
    }

    $headline = trim($_POST['headline'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $agreedPolicies = isset($_POST['agree_policies']);

    if (mb_strlen($qualification) < 5) $errors[] = 'Please describe your teaching qualification (min 5 characters).';
    if ($headline !== '' && mb_strlen($headline) > 150) $errors[] = 'Headline must be 150 characters or fewer.';
    if (!$agreedPolicies) $errors[] = 'Please read and agree to the Instructor Policies before applying.';

    if (!$errors) {
        $pdo->prepare("UPDATE users SET headline = ?, qualification = ?, teacher_status = 'pending', instructor_policies_agreed_at = NOW() WHERE id = ?")
            ->execute([$headline ?: null, $qualification, $user['id']]);
        $_SESSION['user']['teacher_status'] = 'pending';
        logActivity($pdo, (int) $user['id'], 'Applied to become an instructor');
        redirect('become-instructor.php');
    }
    $me['headline'] = $headline;
    $me['qualification'] = $qualification;
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Become an Instructor — <?= e(SITE_NAME) ?></title>
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

<div class="dashboard-wrap" style="max-width:640px">
    <div class="dashboard-header"><h2><i data-lucide="presentation" class="lucide-icon"></i> Become an Instructor</h2><p>Share your knowledge and reach students anywhere in the world.</p></div>

    <?php if ($me['teacher_status'] === 'pending'): ?>
        <div class="card"><div class="card-body" style="text-align:center;padding:2.5rem 1.5rem">
            <i data-lucide="hourglass" class="lucide-icon" style="width:42px;height:42px;color:var(--gold);margin-bottom:1rem"></i>
            <h3 style="margin-bottom:.6rem">Your application is under review</h3>
            <p style="color:var(--text-light);font-size:.92rem">An admin will review your headline and qualification shortly. You'll gain access to course-creation tools as soon as you're approved — no need to apply again.</p>
        </div></div>

    <?php elseif ($me['teacher_status'] === 'rejected'): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body" style="text-align:center;padding:2rem 1.5rem">
            <i data-lucide="circle-x" class="lucide-icon" style="width:42px;height:42px;color:#c62828;margin-bottom:1rem"></i>
            <h3 style="margin-bottom:.6rem">Your application wasn't approved</h3>
            <p style="color:var(--text-light);font-size:.92rem;margin-bottom:1.2rem">You're welcome to update your details and apply again.</p>
            <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="reapply" value="1" class="btn btn-primary">Apply Again</button></form>
        </div></div>

    <?php else: ?>
        <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

        <div class="card"><div class="card-body">
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

                <div class="form-group">
                    <label class="form-label">Professional Headline</label>
                    <input type="text" name="headline" class="form-control" placeholder="e.g. Developer and Lead Instructor" maxlength="150" value="<?= e($me['headline'] ?? '') ?>">
                    <div class="form-hint">Shown under your name on your courses — like "Dr. Angela Yu, Developer and Lead Instructor."</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Teaching Qualification</label>
                    <textarea name="qualification" class="form-control" placeholder="e.g. MA Islamic Studies, Hafiz-ul-Quran, 5 years teaching Tajweed"><?= e($me['qualification'] ?? '') ?></textarea>
                </div>

                <div class="form-group">
                    <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;font-size:.88rem">
                        <input type="checkbox" name="agree_policies" value="1" style="width:auto;margin-top:.2rem" required>
                        <span>I have read and agree to the <a href="instructor-policies.php" target="_blank">Instructor Policies</a> — including content quality, code of conduct, and how payments/payouts currently work on this platform.</span>
                    </label>
                </div>

                <button type="submit" class="btn btn-primary btn-full">Submit Application</button>
            </form>
        </div></div>
        <p style="font-size:.85rem;color:var(--text-light);margin-top:1rem">An admin reviews every application before course-creation tools are unlocked. You'll keep full access to everything you can do today in the meantime.</p>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
