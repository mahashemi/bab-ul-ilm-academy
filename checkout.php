<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$cartItems = getCartItems($pdo, $user['id']);
if (!$cartItems) {
    flash('error', 'Your cart is empty.');
    redirect('cart.php');
}

$gateways = paymentGatewaysConfigured();
if (!$gateways) {
    flash('error', "Online checkout isn't set up yet — please contact us via Feedback to arrange payment.");
    redirect('cart.php');
}

$total = getCartTotal($cartItems);
?>
<!DOCTYPE html>
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout — <?= e(SITE_NAME) ?></title>
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
        <a href="cart.php"><i data-lucide="arrow-left" class="lucide-icon"></i> Back to Cart</a>
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
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> <?= t('nav_logout') ?></a>
            </div>
        </div>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:760px">
    <div class="dashboard-header"><h2><i data-lucide="lock" class="lucide-icon"></i> Checkout</h2><p>Review your order, then choose how you'd like to pay.</p></div>

    <?php if (flash('error')): ?><div class="alert alert-error"><?= e(flash('error')) ?></div><?php endif; ?>

    <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:1rem">Order Summary (<?= count($cartItems) ?> course<?= count($cartItems) == 1 ? '' : 's' ?>)</h3>
        <?php foreach ($cartItems as $item): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.6rem 0;border-bottom:1px solid var(--border)">
            <div>
                <strong style="font-size:.9rem"><?= e($item['title']) ?></strong>
                <div style="font-size:.78rem;color:var(--text-light)">By <?= e($item['teacher_name']) ?></div>
            </div>
            <div style="font-weight:700;color:var(--green-deep)">$<?= number_format((float) $item['price'], 2) ?></div>
        </div>
        <?php endforeach; ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding-top:1rem;font-size:1.1rem">
            <strong>Total</strong>
            <strong style="color:var(--green-deep)">$<?= number_format($total, 2) ?></strong>
        </div>
    </div></div>

    <div class="card"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:1rem">Payment Method</h3>
        <p style="font-size:.8rem;color:var(--text-light);margin-bottom:1.2rem">By completing your purchase you agree to be charged the amount shown above. Card and PayPal details are entered on the payment provider's own secure page — we never see or store them.</p>
        <div style="display:flex;flex-direction:column;gap:.8rem">
            <?php if (in_array('stripe', $gateways, true)): ?>
            <form method="post" action="checkout-stripe.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <button type="submit" class="btn btn-primary btn-full"><i data-lucide="credit-card" class="lucide-icon"></i> Pay $<?= number_format($total, 2) ?> with Card (Stripe)</button>
            </form>
            <?php endif; ?>
            <?php if (in_array('paypal', $gateways, true)): ?>
            <form method="post" action="checkout-paypal.php">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <button type="submit" class="btn btn-outline btn-full"><i data-lucide="wallet" class="lucide-icon"></i> Pay $<?= number_format($total, 2) ?> with PayPal</button>
            </form>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<?= renderFooter($pdo) ?>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
