<?php
require_once __DIR__ . '/db.php';
$user = auth();

$code = trim($_GET['code'] ?? '');
$stmt = $pdo->prepare(
    "SELECT cert.*, COALESCE(s.display_name, s.name) AS student_name, c.title AS course_title,
            COALESCE(t.display_name, t.name) AS teacher_name, t.headline AS teacher_headline,
            (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons WHERE course_id = c.id) AS total_minutes
     FROM certificates cert
     JOIN users s ON s.id = cert.student_id
     JOIN courses c ON c.id = cert.course_id
     JOIN users t ON t.id = c.teacher_id
     WHERE cert.certificate_code = ?"
);
$stmt->execute([$code]);
$cert = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $cert ? 'Certificate — ' . e($cert['course_title']) : 'Certificate Not Found' ?> — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
<style>
@media print {
    .navbar, footer, .cert-page-actions { display: none !important; }
    .cert-card { box-shadow: none !important; border: 2px solid var(--green-deep) !important; }
}
</style>
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="courses.php">Courses</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <?php if ($user): ?>
            <a href="chat.php">Messages</a>
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
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
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> Dashboard</a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                    <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                    <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> Admin Panel</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn">Login</a>
        <?php endif; ?>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:800px">
    <?php if (!$cert): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="shield-x" class="lucide-icon"></i></div><h3>Certificate Not Found</h3><p>No certificate matches this code. If you believe this is an error, contact the issuing teacher.</p></div>
    <?php else: ?>
        <div class="cert-card">
            <div class="cert-seal"><img src="assets/seal-curved-gold.svg" alt=""></div>
            <div class="cert-eyebrow">Certificate of Completion</div>
            <div class="cert-name"><?= e($cert['student_name']) ?></div>
            <p class="cert-line">has successfully completed the course</p>
            <div class="cert-course"><?= e($cert['course_title']) ?></div>
            <p class="cert-line">
                <?php if ((int) $cert['total_minutes'] > 0): ?><?= round((int) $cert['total_minutes'] / 60, 1) ?> hours of instruction · <?php endif; ?>
                Taught by <?= e($cert['teacher_name']) ?><?= $cert['teacher_headline'] ? ', ' . e($cert['teacher_headline']) : '' ?>
            </p>
            <div class="cert-footer-row">
                <div>
                    <div class="cert-footer-label">Issued</div>
                    <div class="cert-footer-value"><?= e(date('F j, Y', strtotime($cert['issued_at']))) ?></div>
                </div>
                <div>
                    <div class="cert-footer-label">Certificate Code</div>
                    <div class="cert-footer-value" style="font-family:monospace"><?= e($cert['certificate_code']) ?></div>
                </div>
            </div>
            <p class="cert-verify-note"><i data-lucide="shield-check" class="lucide-icon"></i> Verified by <?= e(SITE_NAME) ?> — anyone with this link can confirm this certificate is authentic.</p>
        </div>
        <div class="cert-page-actions">
            <button onclick="window.print()" class="btn btn-primary"><i data-lucide="printer" class="lucide-icon"></i> Print / Save as PDF</button>
            <a href="course.php?id=<?= (int) $cert['course_id'] ?>" class="btn btn-outline">View Course</a>
        </div>
    <?php endif; ?>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
