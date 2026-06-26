<?php
require_once __DIR__ . '/db.php';

$provider = $_GET['provider'] ?? '';
$expectedState = $_SESSION['oauth_state'] ?? null;
$expectedProvider = $_SESSION['oauth_provider'] ?? null;
unset($_SESSION['oauth_state'], $_SESSION['oauth_provider']);

if (!array_key_exists($provider, oauthProviders())) {
    flash('error', 'Unknown sign-in provider.');
    redirect('login.php');
}

// The user denied access, or the provider itself errored out -- not a
// bug on our end, just show a plain "didn't complete" message rather
// than a scary error page.
if (isset($_GET['error'])) {
    flash('error', 'Sign-in with ' . ucfirst($provider) . ' was not completed.');
    redirect('login.php');
}

// CSRF protection for the OAuth flow itself: the state value minted in
// oauth-login.php must come back unchanged, and for the same provider
// that was actually started.
$state = $_GET['state'] ?? '';
if ($provider !== $expectedProvider || !$expectedState || !hash_equals($expectedState, $state)) {
    flash('error', 'Your sign-in session expired or was invalid. Please try again.');
    redirect('login.php');
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    flash('error', 'Sign-in with ' . ucfirst($provider) . ' was not completed.');
    redirect('login.php');
}

$profile = oauthFetchProfile($provider, $code);
if (!$profile) {
    flash('error', "We couldn't reach " . ucfirst($provider) . " to sign you in. Please try again in a moment.");
    redirect('login.php');
}

$result = oauthLoginOrRegister($pdo, $provider, $profile);
if ($result['error']) {
    flash('error', $result['error']);
    redirect('login.php');
}

redirect('dashboard.php');
