<?php
// Bab ul Ilm Academy — Database & Site Configuration
// Update these values for your hosting environment

define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change to your DB username
define('DB_PASS', '');              // Change to your DB password
define('DB_NAME', 'bab_ul_ilm');

// SITE_NAME, SITE_TAGLINE, and SITE_AFFILIATION are loaded dynamically from the
// `settings` database table (editable by admins at /admin.php <i data-lucide="arrow-right" class="lucide-icon"></i> Settings tab).
// These are just the fallback defaults used if the table is empty or missing.
define('SITE_NAME_DEFAULT', 'Bab ul Ilm Academy');
define('SITE_TAGLINE_DEFAULT', 'Teach and Learn Any Subject — All Levels, Anywhere, Everywhere');
define('SITE_AFFILIATION_DEFAULT', 'Under Alia University of Holland');
define('SITE_URL', '');             // e.g. https://babulilmacademy.com

// Email verification: when true, the verification link is also shown on screen
// after registration (useful when SMTP isn't configured yet, e.g. local XAMPP).
// Set this to false once real email delivery works in production.
define('DEV_SHOW_VERIFY_LINK', true);

// Idle session timeout, in seconds. After this much inactivity, auth() treats
// the session as expired and logs the user out on their next request.
define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
