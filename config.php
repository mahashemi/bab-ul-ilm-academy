<?php
// Bab ul Ilm Academy — Database & Site Configuration
// Update these values for your hosting environment

define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change to your DB username
define('DB_PASS', '');              // Change to your DB password
define('DB_NAME', 'bab_ul_ilm');

// SITE_NAME, SITE_TAGLINE, and SITE_AFFILIATION are loaded dynamically from the
// `settings` database table (editable by admins at /admin.php -> Settings tab).
// These are just the fallback defaults used if the table is empty or missing.
define('SITE_NAME_DEFAULT', 'Bab ul Ilm Academy');
define('SITE_TAGLINE_DEFAULT', 'Teach and Learn Any Subject — All Levels, Anywhere, Everywhere');
define('SITE_AFFILIATION_DEFAULT', 'Under Alia University of Holland');
// Separate from SITE_TAGLINE (which is plain text, used in <title>/meta tags
// where HTML would show up literally) -- this one is rendered unescaped on
// the homepage hero, so an admin can use basic HTML (e.g. a <span> for the
// gold-colored part) and real line breaks.
define('HOME_HERO_HEADLINE_DEFAULT', "Teach and Learn Any Subject — <span>All Levels, Anywhere, Everywhere</span>");
define('SITE_URL', '');             // e.g. https://babulilmacademy.com

// Email verification: when true, the verification link is also shown on screen
// after registration (useful when SMTP isn't configured yet, e.g. local XAMPP).
// Set this to false once real email delivery works in production.
define('DEV_SHOW_VERIFY_LINK', true);

// Idle session timeout, in seconds. After this much inactivity, auth() treats
// the session as expired and logs the user out on their next request.
define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes

// ── Social Login (OAuth) ─────────────────────────────────────────────────
// Each provider's client_id/client_secret start empty. The "Continue with
// X" button on login.php/register.php only renders once BOTH are filled
// in for that provider (see oauthConfigured() in db.php) -- an
// unconfigured provider simply has no button, rather than one that would
// fail. See oauth-login.php/oauth-callback.php for the flow itself, and
// ask for the setup guide (exact steps per provider's developer console,
// including the redirect URI to register) when you're ready to fill these in.
define('OAUTH_PROVIDERS', [
    'google' => [
        'label' => 'Google',
        'client_id' => '',
        'client_secret' => '',
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'scope' => 'openid email profile',
    ],
    'facebook' => [
        'label' => 'Facebook',
        'client_id' => '',
        'client_secret' => '',
        'auth_url' => 'https://www.facebook.com/v19.0/dialog/oauth',
        'token_url' => 'https://graph.facebook.com/v19.0/oauth/access_token',
        'scope' => 'email public_profile',
    ],
    'microsoft' => [
        'label' => 'Microsoft',
        'client_id' => '',
        'client_secret' => '',
        'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'scope' => 'openid email profile User.Read',
    ],
    'github' => [
        'label' => 'GitHub',
        'client_id' => '',
        'client_secret' => '',
        'auth_url' => 'https://github.com/login/oauth/authorize',
        'token_url' => 'https://github.com/login/oauth/access_token',
        'scope' => 'read:user user:email',
    ],
]);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
