<?php
require_once __DIR__ . '/db.php';
$user = auth();

$ROLE_GUIDES = [
    'student' => [
        'icon' => 'graduation-cap', 'label' => 'Learn', 'desc' => 'I want to learn',
        'steps' => [
            ['icon' => 'user-plus', 'title' => 'Create your free account', 'body' => 'Click <a href="register.php">Register</a> and fill in your name, email, country, and a password — every account starts the same way, there\'s no role to choose. We\'ll send a verification link to your email — click it before logging in. (You can also skip this with one click via <a href="login.php">Google, Facebook, Microsoft, or GitHub</a>, if enabled.)'],
            ['icon' => 'sparkles', 'title' => 'Tell us what you\'re interested in (optional)', 'body' => 'From your Dashboard, click "Add occupation and interests" to pick your fields of study. This shapes the "Recommended for You" courses on your Dashboard and homepage.'],
            ['icon' => 'search', 'title' => 'Find a course', 'body' => 'Browse <a href="courses.php">All Courses</a> and use the filters (level, language, price) or the search bar, or hover/tap a category from the home page to drill into a field and subject.'],
            ['icon' => 'log-in', 'title' => 'Enroll', 'body' => 'Open a course page and click Enroll — free courses unlock immediately. For a paid course, click Buy Now or Add to Cart and check out securely with a card (Stripe) or PayPal.'],
            ['icon' => 'clipboard-list', 'title' => 'Learn at your own pace', 'body' => 'Mark each lesson complete as you go, take any quizzes, and submit assignments for grading. Your Dashboard tracks exactly how far through each course you are.'],
            ['icon' => 'message-circle', 'title' => 'Get help when you\'re stuck', 'body' => 'Message your teacher directly, post in the course\'s Class Discussion, or ask in its Q&A — all from the course page.'],
            ['icon' => 'award', 'title' => 'Earn points, badges, and certificates', 'body' => 'Enrolling, completing lessons, and passing quizzes earn you points and badges (see your Dashboard). Finish 100% of a course\'s lessons and we automatically issue a certificate with a verification code anyone can check.'],
        ],
    ],
    'teacher' => [
        'icon' => 'book-open', 'label' => 'Teach', 'desc' => 'I want to teach too',
        'steps' => [
            ['icon' => 'user-plus', 'title' => 'Create your free account', 'body' => 'Same as everyone — click <a href="register.php">Register</a> and sign up. Every account can already browse and enroll in courses; teaching is something you add on afterward, whenever you\'re ready.'],
            ['icon' => 'presentation', 'title' => 'Apply to become an instructor', 'body' => 'From your Dashboard\'s account menu, click "Become an Instructor," then fill in a short professional headline and describe your teaching qualification. You\'ll need to agree to our <a href="instructor-policies.php">Instructor Policies</a> (content quality, code of conduct, and how payments/payouts currently work) before submitting. This goes to an admin for review.'],
            ['icon' => 'hourglass', 'title' => 'Wait for approval', 'body' => 'You\'ll keep full access to everything you can already do (browsing, enrolling, messaging) while your application is reviewed. Course-creation tools unlock automatically the moment you\'re approved — no need to re-apply.'],
            ['icon' => 'book-open', 'title' => 'Create your first course', 'body' => 'Once approved, click "+ New Course" from your Dashboard. The wizard walks you through Basics, Details, Cover Image, Curriculum, Pricing, and Publishing one step at a time, saving as you go. For the full walkthrough — including how to let an AI help write your lessons, and how to bulk-upload a whole course from a CSV — see our <a href="tutorial.php">step-by-step Course Creation Tutorial</a>.'],
            ['icon' => 'send', 'title' => 'Submit for review', 'body' => 'Once your course has at least one lesson and you publish it, an admin reviews it before it appears in the public catalog. You\'ll be notified once it\'s approved.'],
            ['icon' => 'users', 'title' => 'Engage your students', 'body' => 'Answer direct messages, host live Class Discussion, answer questions in each course\'s Q&A, and grade quizzes/assignments as students submit them.'],
            ['icon' => 'headset', 'title' => 'Need a hand building your course?', 'body' => 'If it\'s easier to describe your course over a phone call than to type it all out yourself, reach out via <a href="feedback.php">Feedback</a> — our support team can build the course, lessons, quizzes, and assignments for you directly, attributed to your account.'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>How It Works — <?= e(SITE_NAME) ?></title>
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
        <input type="text" name="q" placeholder="<?= e(t('nav_search_placeholder')) ?>">
    </form>
    <div class="nav-links">
        <a href="index.php"><?= t('nav_home') ?></a>
        <a href="courses.php"><?= t('nav_courses') ?></a>
        <a href="about.php"><?= t('nav_about') ?></a>
        <a href="feedback.php"><?= t('nav_feedback') ?></a>
        <div class="nav-account">
            <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="<?= e(t('nav_language')) ?>">
                <i data-lucide="globe" class="lucide-icon"></i>
            </button>
            <div class="nav-account-menu">
                <a href="set-language.php?lang=en&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">English</a>
                <a href="set-language.php?lang=ur&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">اردو</a>
                <a href="set-language.php?lang=fa&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">فارسی</a>
                <a href="set-language.php?lang=ar&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">العربية</a>
            </div>
        </div>

        <?php if ($user): ?>
            <a href="chat.php"><?= t('nav_messages') ?></a>
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><?= t('nav_new_course') ?></a><?php endif; ?>
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
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> <?= t('nav_dashboard') ?></a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> <?= t('nav_messages') ?></a>
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> <?= t('nav_new_course_plain') ?></a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> <?= t('nav_become_instructor') ?></a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                    <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_admin_panel') ?></a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn"><?= t('nav_login') ?></a>
        <?php endif; ?>
    </div>
</nav>

<section class="course-hero-band">
    <div class="course-hero-inner">
        <h1 class="course-hero-title" style="font-size:1.7rem">How <?= e(SITE_NAME) ?> Works</h1>
        <p class="course-hero-meta" style="font-size:.95rem;max-width:680px">A structured e-learning platform spanning Islamic studies and core academics — qualified teachers publish courses, students learn at their own pace with real progress tracking. <a href="about.php" style="color:var(--gold);text-decoration:underline">Read our full mission &amp; vision →</a></p>
    </div>
</section>

<div class="dashboard-wrap" style="max-width:780px">
    <h3 style="margin-bottom:1rem;color:var(--green-deep)">Pick the guide that matches you:</h3>
    <div class="role-card-grid" style="margin-bottom:2rem">
        <?php foreach ($ROLE_GUIDES as $key => $g): ?>
        <div class="role-card gs-role-btn<?= $key === 'student' ? ' active' : '' ?>" data-role="<?= e($key) ?>" onclick="showRoleGuide('<?= e($key) ?>')" id="gsbtn-<?= e($key) ?>">
            <i data-lucide="<?= e($g['icon']) ?>" class="lucide-icon"></i>
            <div class="role-card-label"><?= e($g['label']) ?></div>
            <div class="role-card-desc"><?= e($g['desc']) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($ROLE_GUIDES as $key => $g): ?>
    <div class="gs-role-panel" id="gspanel-<?= e($key) ?>" style="display:none">
        <?php foreach ($g['steps'] as $i => $step): ?>
        <div class="card" style="margin-bottom:1.2rem"><div class="card-body" style="display:flex;gap:1rem;align-items:flex-start">
            <div class="step-num" style="flex-shrink:0"><?= $i + 1 ?></div>
            <div>
                <h3 style="font-size:1rem;margin-bottom:.4rem;display:flex;align-items:center;gap:.5rem"><i data-lucide="<?= e($step['icon']) ?>" class="lucide-icon"></i> <?= e($step['title']) ?></h3>
                <p style="font-size:.9rem;color:var(--text-mid)"><?= $step['body'] /* trusted, hardcoded copy with inline links — not escaped */ ?></p>
            </div>
        </div></div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="alert alert-info">
        <i data-lucide="info" class="lucide-icon"></i> Still have questions? <a href="feedback.php">Send us feedback</a> and we'll get back to you.
    </div>
</div>
<?= renderFooter($pdo) ?>
<script>
function showRoleGuide(role) {
    document.querySelectorAll('.gs-role-panel').forEach(function (p) { p.style.display = p.id === 'gspanel-' + role ? 'block' : 'none'; });
    document.querySelectorAll('.gs-role-btn').forEach(function (b) { b.classList.toggle('active', b.dataset.role === role); });
}
showRoleGuide('student');
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
