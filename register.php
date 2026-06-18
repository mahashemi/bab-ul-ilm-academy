<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$errors = [];
$role = $_POST['role'] ?? 'student';
if (!in_array($role, ['student', 'teacher'], true)) $role = 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $password      = $_POST['password'] ?? '';
    $country       = trim($_POST['country'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');

    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (mb_strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($role === 'teacher' && mb_strlen($qualification) < 5) $errors[] = 'Please describe your teaching qualification (min 5 characters).';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'An account with this email already exists.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, role, country, qualification) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$name, $email, $hash, $role, $country, $role === 'teacher' ? $qualification : null]);

        $userId = (int) $pdo->lastInsertId();
        $_SESSION['user'] = ['id' => $userId, 'name' => $name, 'email' => $email, 'role' => $role];
        flash('success', 'Welcome to Bab ul Ilm Academy, ' . $name . '!');
        redirect('dashboard.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="style.css">
<script>
function setRole(r) {
    document.getElementById('roleInput').value = r;
    document.getElementById('roleStudent').classList.toggle('active', r === 'student');
    document.getElementById('roleTeacher').classList.toggle('active', r === 'teacher');
    document.getElementById('teacherFields').style.display = (r === 'teacher') ? 'block' : 'none';
}
</script>
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box">
        <div class="auth-logo">
            <h2>🕌 <?= e(SITE_NAME) ?></h2>
            <p><?= e(SITE_TAGLINE) ?></p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="role-switch">
            <div class="role-option <?= $role === 'student' ? 'active' : '' ?>" id="roleStudent" onclick="setRole('student')">🎓 I'm a Student</div>
            <div class="role-option <?= $role === 'teacher' ? 'active' : '' ?>" id="roleTeacher" onclick="setRole('teacher')">📖 I'm a Teacher</div>
        </div>

        <form method="post" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="role" id="roleInput" value="<?= e($role) ?>">

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Ahmad Hassan" value="<?= e($_POST['name'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Country</label>
                <input type="text" name="country" class="form-control" placeholder="Pakistan" value="<?= e($_POST['country'] ?? '') ?>">
            </div>

            <div id="teacherFields" style="display:<?= $role === 'teacher' ? 'block' : 'none' ?>">
                <div class="form-group">
                    <label class="form-label">Teaching Qualification</label>
                    <textarea name="qualification" class="form-control" placeholder="e.g. MA Islamic Studies, Hafiz-ul-Quran, 5 years teaching Tajweed"><?= e($_POST['qualification'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" placeholder="At least 6 characters" required>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Create My Account</button>
        </form>

        <p style="text-align:center;margin-top:1.2rem;font-size:.88rem;color:var(--text-light)">
            Already have an account? <a href="login.php">Log in</a>
        </p>
    </div>
</div>
</body>
</html>
