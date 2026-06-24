<?php
require_once __DIR__ . '/db.php';
$user = auth();

$STEPS = [
    'en' => [
        'dir' => 'ltr', 'label' => 'Step-by-Step: Creating a Course',
        'steps' => [
            ['icon' => 'plus-circle', 'title' => 'Create your course', 'body' => 'From your Dashboard, click "+ New Course". Give it a clear title and a description of at least 20 characters explaining what students will learn.'],
            ['icon' => 'image', 'title' => 'Add a cover photo', 'body' => 'Upload a 1280×720 (16:9) image from Edit Course. This is the picture students see on your course tile, so keep the important part centered — it gets cropped to fill the tile.'],
            ['icon' => 'clipboard-list', 'title' => 'Add lessons', 'body' => 'From Edit Course, click "Add / Manage Lessons". Give each lesson a title, write the content, and optionally add a YouTube/Vimeo video link. Group lessons into sections — "Week 1", "Week 2" works well.'],
            ['icon' => 'list-checks', 'title' => 'Add a quiz (optional)', 'body' => 'From Edit Course, click "Quizzes" to test what students have learned. Add multiple-choice questions and mark the correct answer for each.'],
            ['icon' => 'file-edit', 'title' => 'Add an assignment (optional)', 'body' => 'From Edit Course, click "Assignments" to give students practical work to submit and get graded on.'],
            ['icon' => 'send', 'title' => 'Submit for review', 'body' => 'Once you have at least one lesson, your course is reviewed by an admin before it appears in the catalog. You\'ll get an email when it\'s approved.'],
        ],
    ],
    'fa' => [
        'dir' => 'rtl', 'label' => 'گام به گام: ساخت یک دوره',
        'steps' => [
            ['icon' => 'plus-circle', 'title' => 'دوره خود را ایجاد کنید', 'body' => 'از داشبورد خود، روی "+ دوره جدید" کلیک کنید. یک عنوان واضح و توضیحاتی حداقل ۲۰ کاراکتری بنویسید که توضیح دهد دانش‌آموزان چه چیزی یاد خواهند گرفت.'],
            ['icon' => 'image', 'title' => 'یک تصویر جلد اضافه کنید', 'body' => 'یک تصویر با ابعاد ۱۲۸۰×۷۲۰ از صفحه ویرایش دوره آپلود کنید. این تصویری است که دانش‌آموزان در کارت دوره شما می‌بینند.'],
            ['icon' => 'clipboard-list', 'title' => 'درس‌ها را اضافه کنید', 'body' => 'از صفحه ویرایش دوره، روی "افزودن / مدیریت درس‌ها" کلیک کنید. برای هر درس عنوان و محتوا بنویسید و در صورت تمایل لینک ویدیو یوتیوب/ویمیو اضافه کنید. درس‌ها را در بخش‌هایی مانند "هفته ۱"، "هفته ۲" گروه‌بندی کنید.'],
            ['icon' => 'list-checks', 'title' => 'یک آزمون اضافه کنید (اختیاری)', 'body' => 'از صفحه ویرایش دوره، روی "آزمون‌ها" کلیک کنید تا آنچه دانش‌آموزان یاد گرفته‌اند را بسنجید.'],
            ['icon' => 'file-edit', 'title' => 'یک تمرین اضافه کنید (اختیاری)', 'body' => 'از صفحه ویرایش دوره، روی "تمرین‌ها" کلیک کنید تا به دانش‌آموزان کار عملی برای ارسال و نمره‌دهی بدهید.'],
            ['icon' => 'send', 'title' => 'برای بررسی ارسال کنید', 'body' => 'به محض اینکه حداقل یک درس داشته باشید، دوره شما توسط یک ادمین بررسی می‌شود. پس از تأیید، ایمیلی دریافت خواهید کرد.'],
        ],
    ],
    'ur' => [
        'dir' => 'rtl', 'label' => 'مرحلہ وار: کورس بنانا',
        'steps' => [
            ['icon' => 'plus-circle', 'title' => 'اپنا کورس بنائیں', 'body' => 'اپنے ڈیش بورڈ سے، "+ نیا کورس" پر کلک کریں۔ ایک واضح عنوان اور کم از کم 20 حروف کی تفصیل لکھیں جو بتائے کہ طلباء کیا سیکھیں گے۔'],
            ['icon' => 'image', 'title' => 'کور فوٹو شامل کریں', 'body' => 'ایڈٹ کورس سے 1280×720 سائز کی تصویر اپلوڈ کریں۔ یہ وہ تصویر ہے جو طلباء آپ کے کورس ٹائل پر دیکھتے ہیں۔'],
            ['icon' => 'clipboard-list', 'title' => 'اسباق شامل کریں', 'body' => 'ایڈٹ کورس سے "اسباق شامل/منظم کریں" پر کلک کریں۔ ہر سبق کا عنوان اور مواد لکھیں، اور اختیاری طور پر یوٹیوب/ویمیو ویڈیو لنک شامل کریں۔ اسباق کو "ہفتہ 1"، "ہفتہ 2" جیسے حصوں میں گروپ کریں۔'],
            ['icon' => 'list-checks', 'title' => 'کوئز شامل کریں (اختیاری)', 'body' => 'ایڈٹ کورس سے "کوئزز" پر کلک کریں تاکہ یہ جانچا جا سکے کہ طلباء نے کیا سیکھا ہے۔'],
            ['icon' => 'file-edit', 'title' => 'اسائنمنٹ شامل کریں (اختیاری)', 'body' => 'ایڈٹ کورس سے "اسائنمنٹس" پر کلک کریں تاکہ طلباء کو عملی کام دیا جا سکے۔'],
            ['icon' => 'send', 'title' => 'جائزہ کے لیے جمع کروائیں', 'body' => 'جیسے ہی آپ کے پاس کم از کم ایک سبق ہو، آپ کا کورس ایڈمن کے ذریعے جائزہ لیا جائے گا۔ منظوری کے بعد آپ کو ای میل ملے گی۔'],
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tutorial: Creating a Course — <?= e(SITE_NAME) ?></title>
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
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
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
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn">Login</a>
        <?php endif; ?>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:760px">
    <div class="dashboard-header">
        <h2><i data-lucide="graduation-cap" class="lucide-icon"></i> How to Create a Course</h2>
        <p>A quick walkthrough for teachers — pick your language below.</p>
    </div>

    <div style="display:flex;gap:.6rem;margin-bottom:1.5rem">
        <?php foreach (['en' => 'English', 'fa' => 'فارسی', 'ur' => 'اردو'] as $code => $label): ?>
        <button type="button" class="btn btn-outline btn-sm tutorial-lang-btn" data-lang="<?= $code ?>" onclick="showTutorialLang('<?= $code ?>')"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>

    <?php foreach ($STEPS as $code => $lang): ?>
    <div class="tutorial-lang-panel" id="tutorial-<?= $code ?>" dir="<?= $lang['dir'] ?>" style="display:none">
        <?php foreach ($lang['steps'] as $i => $step): ?>
        <div class="card" style="margin-bottom:1.2rem"><div class="card-body" style="display:flex;gap:1rem;align-items:flex-start">
            <div class="step-num" style="flex-shrink:0"><?= $i + 1 ?></div>
            <div>
                <h3 style="font-size:1rem;margin-bottom:.4rem;display:flex;align-items:center;gap:.5rem"><i data-lucide="<?= e($step['icon']) ?>" class="lucide-icon"></i> <?= e($step['title']) ?></h3>
                <p style="font-size:.9rem;color:var(--text-mid)"><?= e($step['body']) ?></p>
            </div>
        </div></div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="alert alert-info">
        <i data-lucide="info" class="lucide-icon"></i> Need more help? <a href="feedback.php">Send us feedback</a> or message an admin from your dashboard — we're happy to help you get your first course set up.
    </div>
</div>
<?= renderFooter($pdo) ?>
<script>
function showTutorialLang(code) {
    document.querySelectorAll('.tutorial-lang-panel').forEach(function (p) { p.style.display = p.id === 'tutorial-' + code ? 'block' : 'none'; });
    document.querySelectorAll('.tutorial-lang-btn').forEach(function (b) { b.classList.toggle('btn-primary', b.dataset.lang === code); b.classList.toggle('btn-outline', b.dataset.lang !== code); });
}
showTutorialLang('en');
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
