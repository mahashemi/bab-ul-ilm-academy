<?php
require_once __DIR__ . '/db.php';

$sent = false;
$devToken = '';
$email = trim($_GET['email'] ?? $_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = trim($_POST['email'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if ($u && !$u['is_verified']) {
        $token = generateVerificationToken();
        $pdo->prepare('UPDATE users SET verification_token = ?, verification_expires = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE id = ?')
            ->execute([$token, $u['id']]);
        sendVerificationEmail($email, $u['name'], $token);
        $devToken = DEV_SHOW_VERIFY_LINK ? $token : '';
    }
    $sent = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resend Verification — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <h2>📧 Resend Verification</h2>
        </div>

        <?php if ($sent): ?>
            <div class="alert alert-success">If an unverified account exists with that email, a new verification link has been sent.</div>
            <?php if ($devToken): ?>
            <div class="alert alert-info">
                <strong>Local/dev notice:</strong> <a href="verify.php?token=<?= e($devToken) ?>">Click here to verify now</a>
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
                <button type="submit" class="btn btn-primary btn-full">Send Verification Link</button>
            </form>
            <p style="text-align:center;margin-top:1.2rem;font-size:.88rem"><a href="login.php">Back to Login</a></p>
        <?php endif; ?>
    </div>
</div>
<script src="app.js" defer></script>
</body>
</html>
