<?php
require_once __DIR__ . '/db.php';
$user = auth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Instructor Policies — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
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
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
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
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> Become an Instructor</a><?php endif; ?>
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

<section class="course-hero-band">
    <div class="course-hero-inner">
        <h1 class="course-hero-title" style="font-size:1.7rem">Instructor Policies</h1>
        <p class="course-hero-meta" style="font-size:.95rem;max-width:680px">What you're agreeing to when you teach on <?= e(SITE_NAME) ?>.</p>
    </div>
</section>

<div class="dashboard-wrap" style="max-width:760px">

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="color:var(--green-deep);margin-bottom:.6rem"><i data-lucide="badge-check" class="lucide-icon"></i> Content Quality</h3>
        <p style="font-size:.9rem;color:var(--text-mid);margin-bottom:.6rem">Every course must offer genuine educational value:</p>
        <ul style="font-size:.9rem;color:var(--text-mid);margin-left:1.2rem;line-height:1.8">
            <li>Content must be your own work, or material you have the rights to use — no plagiarism or uploading someone else's course.</li>
            <li>At least one real lesson is required before a course can be submitted for review.</li>
            <li>Islamic-studies content should be presented accurately, respectfully, and within mainstream scholarly understanding.</li>
            <li>Every course is reviewed by an admin before it appears in the public catalog — submitting doesn't mean it's instantly live.</li>
        </ul>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="color:var(--green-deep);margin-bottom:.6rem"><i data-lucide="sparkles" class="lucide-icon"></i> AI-Assisted Content</h3>
        <p style="font-size:.9rem;color:var(--text-mid)">Unlike some platforms, we don't restrict AI-assisted course writing — our own lesson-builder includes an AI prompt helper. Using AI to draft lessons, quizzes, or assignments is fine, as long as you review everything for accuracy before publishing. You're responsible for what your course actually says, regardless of how it was drafted.</p>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="color:var(--green-deep);margin-bottom:.6rem"><i data-lucide="users" class="lucide-icon"></i> Code of Conduct</h3>
        <ul style="font-size:.9rem;color:var(--text-mid);margin-left:1.2rem;line-height:1.8">
            <li>Treat students respectfully in messages, class discussions, and Q&amp;A — no harassment, discrimination, or abusive language.</li>
            <li>Respond to student questions and messages in a reasonable time.</li>
            <li>Don't use the platform to solicit students off-platform to avoid normal course enrollment.</li>
            <li>Repeated policy violations can result in a course being unpublished or an instructor account's teaching access being revoked.</li>
        </ul>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="color:var(--green-deep);margin-bottom:.6rem"><i data-lucide="circle-dollar-sign" class="lucide-icon"></i> Payments &amp; Payouts</h3>
        <p style="font-size:.9rem;color:var(--text-mid);margin-bottom:.6rem">We want to be upfront that our model is simpler — and currently more manual — than large platforms like Udemy:</p>
        <ul style="font-size:.9rem;color:var(--text-mid);margin-left:1.2rem;line-height:1.8">
            <li><strong>How students pay:</strong> through our own Stripe/PayPal checkout (once a gateway is configured) — card or PayPal, processed securely by the gateway itself, we never see or store payment details.</li>
            <li><strong>Where the money goes:</strong> 100% of every payment is collected into the platform's own Stripe/PayPal account at checkout — there is no automatic, real-time revenue split to instructors built into the app yet.</li>
            <li><strong>How instructors get paid:</strong> payouts are handled manually, outside the app, on a schedule arranged directly with you — not an automatic monthly transfer like Udemy's PayPal/Payoneer payout system. Reach out via <a href="feedback.php">Feedback</a> to discuss your payout arrangement once your course starts earning.</li>
            <li>This is a deliberate, honest limitation of where the platform is today, not hidden fine print — it will be revisited as the platform grows.</li>
        </ul>
    </div></div>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="color:var(--green-deep);margin-bottom:.6rem"><i data-lucide="copyright" class="lucide-icon"></i> Intellectual Property</h3>
        <p style="font-size:.9rem;color:var(--text-mid)">You keep ownership of the courses you create. By publishing on <?= e(SITE_NAME) ?>, you grant us a license to host, display, and stream your content to enrolled students for as long as your course remains on the platform. You're responsible for ensuring you have the rights to any textbook excerpts, images, or other third-party material you include.</p>
    </div></div>

    <div class="alert alert-info">
        <i data-lucide="info" class="lucide-icon"></i> Questions about any of this? <a href="feedback.php">Send us feedback</a> before applying and we'll get back to you.
    </div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
