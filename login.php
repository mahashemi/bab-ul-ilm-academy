<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$errors = [];
$unverifiedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, name, display_name, email, password, role, avatar, is_approved, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password'])) {
        $errors[] = 'Incorrect email or password.';
    } elseif (!$u['is_approved']) {
        $errors[] = 'Your account has been suspended. Please contact the administrator.';
    } elseif (!$u['is_verified']) {
        $unverifiedEmail = $email;
    } else {
        $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'display_name' => $u['display_name'], 'email' => $u['email'], 'role' => $u['role'], 'avatar' => $u['avatar']];
        logActivity($pdo, (int) $u['id'], 'Logged in');
        redirect('dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <img src="assets/lockup-green.svg" alt="<?= e(SITE_NAME) ?>" class="auth-logo-img">
            <p><?= e(SITE_TAGLINE) ?></p>
            <p style="font-size:1rem;font-weight:600;color:var(--green-mid);margin-top:.3rem"><?= e(SITE_AFFILIATION) ?></p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php elseif ($unverifiedEmail): ?>
            <div class="alert alert-error">
                Please verify your email before logging in.
                <a href="resend-verification.php?email=<?= e(urlencode($unverifiedEmail)) ?>">Resend verification link</a>
            </div>
        <?php elseif (flash('error')): ?>
            <div class="alert alert-error"><?= e(flash('error')) ?></div>
        <?php endif; ?>

        <?= renderOauthButtons() ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group">
                <div style="display:flex;justify-content:space-between;align-items:baseline">
                    <label class="form-label">Password</label>
                    <a href="forgot-password.php" style="font-size:.8rem">Forgot password?</a>
                </div>
                <input type="password" name="password" class="form-control" placeholder="Your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Log In</button>
        </form>

        <div style="display:flex;align-items:center;gap:.8rem;margin:1.4rem 0;color:var(--text-light);font-size:.8rem">
            <div style="flex:1;border-top:1px solid var(--border)"></div>
            NEW TO <?= e(mb_strtoupper(SITE_NAME)) ?>?
            <div style="flex:1;border-top:1px solid var(--border)"></div>
        </div>
        <a href="register.php" class="btn btn-outline btn-full"><i data-lucide="sparkles" class="lucide-icon"></i> Create a Free Account</a>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
