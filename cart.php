<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['remove_course'])) {
        removeFromCart($pdo, $user['id'], (int) $_POST['remove_course']);
    }
    redirect('cart.php');
}

$cartItems = getCartItems($pdo, $user['id']);
$total = getCartTotal($cartItems);

$courseSelect = "c.*, COALESCE(u.display_name, u.name) AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
            (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
            (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
            (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating";

// "You might also like" — recommended paid courses not already in the
// cart or already enrolled, ranked by real enrollment+rating like every
// other recommendation row on the site.
$cartCourseIds = array_column($cartItems, 'id');
$exclude = array_merge($cartCourseIds, [0]);
$placeholders = implode(',', array_fill(0, count($exclude), '?'));
$recommended = $pdo->prepare(
    "SELECT $courseSelect FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE c.is_published = 1 AND c.moderation_status = 'approved' AND c.price > 0 AND c.id NOT IN ($placeholders)
       AND c.id NOT IN (SELECT course_id FROM enrollments WHERE student_id = ?)
     ORDER BY student_count DESC, avg_rating DESC LIMIT 4"
);
$recommended->execute([...$exclude, $user['id']]);
$recommended = $recommended->fetchAll();

$gateways = paymentGatewaysConfigured();
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Shopping Cart — <?= e(SITE_NAME) ?></title>
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

        <a href="chat.php"><?= t('nav_messages') ?></a>
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
                <div class="nav-menu-divider"></div>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> <?= t('nav_edit_profile') ?></a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> <?= t('nav_account_activity') ?></a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="container section">
    <h2 class="section-title">Shopping <span>Cart</span></h2>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>
    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <?php if (!$cartItems): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="shopping-cart" class="lucide-icon"></i></div><h3>Your cart is empty</h3><p>Let's change that — time to learn some new skills! <a href="courses.php">Browse Courses</a></p></div>
    <?php else: ?>
    <div class="cart-layout">
        <div class="cart-items">
            <p style="font-size:.88rem;color:var(--text-light);margin-bottom:1rem"><?= count($cartItems) ?> course<?= count($cartItems) == 1 ? '' : 's' ?> in cart</p>
            <?php foreach ($cartItems as $item): ?>
            <div class="cart-item">
                <div class="cart-item-cover">
                    <?php if ($item['cover_url']): ?><img src="<?= e($item['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($item['subject_icon'] ?? null) ?><?php endif; ?>
                </div>
                <div class="cart-item-info">
                    <a href="course.php?id=<?= (int) $item['id'] ?>" class="cart-item-title"><?= e($item['title']) ?></a>
                    <div class="cart-item-teacher">By <?= e($item['teacher_name']) ?></div>
                    <form method="post">
                        <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                        <button type="submit" name="remove_course" value="<?= (int) $item['id'] ?>" class="cart-item-remove">Remove</button>
                    </form>
                </div>
                <div class="cart-item-price">$<?= number_format((float) $item['price'], 2) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="cart-summary">
            <div class="cart-summary-label">Total:</div>
            <div class="cart-summary-total">$<?= number_format($total, 2) ?></div>
            <?php if (!$gateways): ?>
                <div class="alert alert-error" style="font-size:.82rem;margin:1rem 0">Online checkout isn't set up yet — please contact us via <a href="feedback.php"><?= t('nav_feedback') ?></a> to arrange payment.</div>
            <?php else: ?>
                <a href="checkout.php" class="btn btn-primary btn-full" style="margin-top:1rem">Proceed to Checkout <i data-lucide="arrow-right" class="lucide-icon"></i></a>
                <p style="font-size:.78rem;color:var(--text-light);text-align:center;margin-top:.6rem">You won't be charged yet</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($recommended): ?>
    <h3 style="margin:2.5rem 0 1.2rem;color:var(--green-deep)">You might also like</h3>
    <div class="grid-3">
        <?php foreach ($recommended as $c): ?><?= renderCourseCard($c) ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
