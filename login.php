<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$errors = [];
$unverifiedEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, name, email, password, role, is_approved, is_verified FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password'])) {
        $errors[] = 'Incorrect email or password.';
    } elseif (!$u['is_approved']) {
        $errors[] = 'Your account has been suspended. Please contact the administrator.';
    } elseif (!$u['is_verified']) {
        $unverifiedEmail = $email;
    } else {
        $_SESSION['user'] = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']];
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
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <h2>🕌 <?= e(SITE_NAME) ?></h2>
            <p><?= e(SITE_TAGLINE) ?></p>
            <p style="font-size:.75rem;color:var(--text-light);margin-top:.2rem"><?= e(SITE_AFFILIATION) ?></p>
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
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="Your password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Log In</button>
        </form>

        <div style="display:flex;align-items:center;gap:.8rem;margin:1.4rem 0;color:var(--text-light);font-size:.8rem">
            <div style="flex:1;border-top:1px solid var(--border)"></div>
            NEW TO <?= e(mb_strtoupper(SITE_NAME)) ?>?
            <div style="flex:1;border-top:1px solid var(--border)"></div>
        </div>
        <a href="register.php" class="btn btn-outline btn-full">✨ Create a Free Account</a>
    </div>
</div>
</body>
</html>
