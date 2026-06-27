<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$sent = false;
$devToken = '';
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u) {
        $token = generateVerificationToken();
        $pdo->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?')
            ->execute([$token, $u['id']]);
        sendPasswordResetEmail($email, $u['name'], $token);
        $devToken = DEV_SHOW_VERIFY_LINK ? $token : '';
    }
    // Always show the same message whether or not the email exists, so this
    // form can't be used to check which addresses have an account here.
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password — <?= e(SITE_NAME) ?></title>
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
            <h2><i data-lucide="key-round" class="lucide-icon"></i> Forgot Password</h2>
            <p>Enter your email and we'll send you a reset link.</p>
        </div>

        <?php if ($sent): ?>
            <div class="alert alert-success">If an account exists with that email, a password reset link has been sent.</div>
            <?php if ($devToken): ?>
            <div class="alert alert-info">
                <strong>Local/dev notice:</strong> <a href="reset-password.php?token=<?= e($devToken) ?>">Click here to reset now</a>
            </div>
            <?php endif; ?>
            <p style="text-align:center;margin-top:1rem"><a href="login.php">Back to Login</a></p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" value="<?= e($email) ?>" placeholder="you@example.com" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
            </form>
            <p style="text-align:center;margin-top:1.2rem;font-size:.88rem"><a href="login.php">Back to Login</a></p>
        <?php endif; ?>
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
