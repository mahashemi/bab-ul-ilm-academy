<?php
// Bab ul Ilm Academy — Database & Site Configuration
// Update these values for your hosting environment

define('DB_HOST', 'localhost');
define('DB_USER', 'root');          // Change to your DB username
define('DB_PASS', '');              // Change to your DB password
define('DB_NAME', 'bab_ul_ilm');

define('SITE_NAME', 'Bab ul Ilm Academy');
define('SITE_TAGLINE', 'Seek Knowledge — From the Cradle to the Grave');
define('SITE_URL', '');             // e.g. https://babulilm.com

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
