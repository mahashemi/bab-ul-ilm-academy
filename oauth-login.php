<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$provider = $_GET['provider'] ?? '';
if (!array_key_exists($provider, oauthProviders()) || !oauthConfigured($provider)) {
    flash('error', 'That sign-in option is not available right now.');
    redirect('login.php');
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_provider'] = $provider;

$url = oauthAuthUrl($provider, $state);
if (!$url) {
    flash('error', 'That sign-in option is not available right now.');
    redirect('login.php');
}
redirect($url);
