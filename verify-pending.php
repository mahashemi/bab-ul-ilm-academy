<?php
require_once __DIR__ . '/db.php';

$email = $_GET['email'] ?? '';
$devToken = DEV_SHOW_VERIFY_LINK ? ($_GET['token'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Your Email — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box" style="text-align:center">
        <div class="auth-logo">
            <h2>📧 Check Your Email</h2>
        </div>

        <p style="color:var(--text-mid);margin-bottom:1rem">
            We've sent a verification link to <strong><?= e($email) ?></strong>.
            Click the link in that email to activate your account.
        </p>

        <?php if ($devToken): ?>
        <div class="alert alert-info" style="text-align:left">
            <strong>Local/dev environment notice:</strong> no SMTP server is configured yet, so the email above was not actually delivered.
            For testing, you can verify immediately using the link below:<br><br>
            <a href="verify.php?token=<?= e($devToken) ?>" class="btn btn-primary btn-sm">Verify My Account Now</a>
        </div>
        <?php endif; ?>

        <p style="margin-top:1.5rem;font-size:.88rem;color:var(--text-light)">
            Didn't get the email? <a href="resend-verification.php?email=<?= e(urlencode($email)) ?>">Resend verification link</a>
        </p>
        <p style="margin-top:.6rem;font-size:.88rem">
            <a href="login.php">Back to Login</a>
        </p>
    </div>
</div>
</body>
</html>
