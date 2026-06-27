<?php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$stmt = $pdo->prepare('SELECT id, name FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW()');
$stmt->execute([$token]);
$u = $stmt->fetch();

$errors = [];
$done = false;

if ($u && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (mb_strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?')
            ->execute([$hash, $u['id']]);
        logActivity($pdo, (int) $u['id'], 'Password reset via email link');
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — <?= e(SITE_NAME) ?></title>
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
            <h2><i data-lucide="key-round" class="lucide-icon"></i> Reset Password</h2>
        </div>

        <?php if (!$u): ?>
            <div class="alert alert-error">This reset link is invalid or has expired.</div>
            <a href="forgot-password.php" class="btn btn-primary btn-full">Request a New Link</a>
            <p style="text-align:center;margin-top:1rem"><a href="login.php">Back to Login</a></p>
        <?php elseif ($done): ?>
            <div class="alert alert-success">Your password has been reset. You can now log in with your new password.</div>
            <a href="login.php" class="btn btn-primary btn-full">Go to Login</a>
        <?php else: ?>
            <p style="color:var(--text-mid);margin-bottom:1.2rem">Hi <?= e($u['name']) ?>, choose a new password below.</p>
            <?php if ($errors): ?>
                <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <div class="form-group">
                    <label class="form-label">New Password</label>
                    <div style="position:relative">
                        <input type="password" name="password" id="pwInput" class="form-control" placeholder="At least 8 characters" required>
                        <button type="button" onclick="togglePw('pwInput',this)" class="pw-toggle" aria-label="Show password"><i data-lucide="eye" class="lucide-icon"></i></button>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="password_confirm" class="form-control" placeholder="Re-enter password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.innerHTML = '<i data-lucide="' + (showing ? 'eye' : 'eye-off') + '" class="lucide-icon"></i>';
    if (window.lucide) lucide.createIcons();
}
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
