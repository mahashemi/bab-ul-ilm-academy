<?php
require_once __DIR__ . '/db.php';

$token = $_GET['token'] ?? '';
$stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE verification_token = ? AND verification_expires > NOW()');
$stmt->execute([$token]);
$u = $stmt->fetch();

if (!$u) {
    $message = 'This verification link is invalid or has expired.';
    $success = false;
} else {
    $pdo->prepare('UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?')
        ->execute([$u['id']]);
    $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
    $message = 'Your email has been verified! Welcome to ' . SITE_NAME . '.';
    $success = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Email — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box" style="text-align:center">
        <div class="auth-logo">
            <h2><?= $success ? '✅ Verified!' : '⚠️ Verification Failed' ?></h2>
        </div>
        <p style="color:var(--text-mid);margin-bottom:1.5rem"><?= e($message) ?></p>
        <?php if ($success): ?>
            <a href="dashboard.php" class="btn btn-primary btn-full">Go to Dashboard</a>
        <?php else: ?>
            <a href="resend-verification.php" class="btn btn-primary btn-full">Request a New Link</a>
            <p style="margin-top:1rem"><a href="login.php">Back to Login</a></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
