<?php
require_once __DIR__ . '/db.php';
$user = auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    if ($name !== '' && $email !== '' && $message !== '') {
        $pdo->prepare('INSERT INTO feedback (user_id, name, email, message) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'] ?? null, $name, $email, $message]);
        flash('success', 'Thank you — your feedback has been sent to our team.');
        redirect('feedback.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Feedback — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php">🕌 <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">☰</button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <?php if ($user): ?><span class="nav-user">👤 <?= e($user['name']) ?></span><a href="chat.php">Messages</a>
            <a href="dashboard.php">Dashboard</a>
            <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php">Admin</a><?php endif; ?>
            <a href="logout.php" class="nav-btn">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php" class="nav-btn">Join Free</a>
        <?php endif; ?>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
    </div>
</nav>

<div class="container section" style="max-width:640px">
    <h2 class="section-title">Share Your <span>Feedback</span></h2>
    <p class="section-sub">Found a bug, have a suggestion for a course or subject, or just want to tell us something? We read every message.</p>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <div class="form-group">
                <label class="form-label">Your Name</label>
                <input type="text" name="name" class="form-control" value="<?= e($user['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Your Feedback or Advice</label>
                <textarea name="message" class="form-control" rows="6" required placeholder="Tell us what's on your mind..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-full">Send Feedback</button>
        </form>
    </div></div>
</div>

<footer>
    <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Seek Knowledge — From the Cradle to the Grave.</div>
</footer>
<script src="app.js" defer></script>
</body>
</html>
