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
if (!in_array('paypal', paymentGatewaysConfigured(), true)) {
    flash('error', 'PayPal payment is not available right now.');
    redirect('checkout.php');
}

$orderId = createPendingOrder($pdo, $user['id'], $cartItems, 'paypal');
$url = paypalCreateOrder($cartItems, $orderId);
if (!$url) {
    flash('error', "We couldn't start the PayPal checkout. Please try again or use a different payment method.");
    redirect('checkout.php');
}
redirect($url);
