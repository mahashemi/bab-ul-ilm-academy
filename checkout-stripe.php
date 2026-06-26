<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('cart.php');
verifyCsrf();

$cartItems = getCartItems($pdo, $user['id']);
if (!$cartItems) {
    flash('error', 'Your cart is empty.');
    redirect('cart.php');
}
if (!in_array('stripe', paymentGatewaysConfigured(), true)) {
    flash('error', 'Card payment is not available right now.');
    redirect('checkout.php');
}

$orderId = createPendingOrder($pdo, $user['id'], $cartItems, 'stripe');
$url = stripeCreateCheckoutSession($cartItems, $orderId, $user['email']);
if (!$url) {
    flash('error', "We couldn't start the Stripe checkout. Please try again or use a different payment method.");
    redirect('checkout.php');
}
redirect($url);
