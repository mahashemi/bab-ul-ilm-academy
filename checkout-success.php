<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$gateway = $_GET['gateway'] ?? '';
$orderId = (int) ($_GET['order_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$orderId]);
$order = $stmt->fetch();

// Ownership check -- never fulfill or reveal an order that isn't this
// user's own, even if they guess/iterate an order_id in the URL.
if (!$order || (int) $order['student_id'] !== (int) $user['id']) {
    flash('error', 'Order not found.');
    redirect('cart.php');
}

if ($order['status'] !== 'paid') {
    if ($gateway === 'stripe') {
        $sessionId = $_GET['session_id'] ?? '';
        $session = $sessionId ? stripeRetrieveSession($sessionId) : null;
        if ($session && ($session['payment_status'] ?? '') === 'paid') {
            fulfillOrder($pdo, $orderId, $sessionId);
        }
    } elseif ($gateway === 'paypal') {
        $token = $_GET['token'] ?? '';
        if ($token && paypalCaptureOrder($token)) {
            fulfillOrder($pdo, $orderId, $token);
        }
    }
    // re-fetch — fulfillOrder() may have just updated this row
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
}

if ($order['status'] !== 'paid') {
    flash('error', "We couldn't confirm your payment. If you completed payment on " . ucfirst($gateway ?: 'the provider') . ", please contact us via Feedback with your order number (#$orderId) and we'll sort it out.");
    redirect('cart.php');
}

$items = $pdo->prepare(
    "SELECT oi.*, c.title, c.cover_url, c.subject_id, s.icon AS subject_icon
     FROM order_items oi JOIN courses c ON c.id = oi.course_id LEFT JOIN subjects s ON s.id = c.subject_id
     WHERE oi.order_id = ?"
);
$items->execute([$orderId]);
$items = $items->fetchAll();
$courseIds = implode(',', array_column($items, 'course_id'));

redirect('enrollment-success.php?course_ids=' . urlencode($courseIds));
