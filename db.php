<?php
require_once __DIR__ . '/config.php';

// Prevent the browser's back/forward cache from showing a stale "logged in"
// page after the user has logged out or their session has timed out.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    die('<div style="font-family:sans-serif;padding:2rem;background:#fff3f3;border:1px solid #f00;border-radius:8px;margin:2rem auto;max-width:500px">
        <h3 style="color:#c00">⚠️ Database Connection Failed</h3>
        <p>Please check your <strong>config.php</strong> credentials.</p>
        <code>' . htmlspecialchars($e->getMessage()) . '</code>
    </div>');
}

// ── Site Settings (editable by admins, stored in DB, with safe defaults) ──
function loadSiteSettings(PDO $pdo, array $defaults): void {
    $map = [];
    try {
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        foreach ($rows as $r) { $map[$r['setting_key']] = $r['setting_value']; }
    } catch (Exception $e) {
        // settings table doesn't exist yet — fall back to defaults silently
    }
    foreach ($defaults as $key => $default) {
        if (!defined($key)) {
            define($key, $map[$key] ?? $default);
        }
    }
}
loadSiteSettings($pdo, [
    'SITE_NAME'        => SITE_NAME_DEFAULT,
    'SITE_TAGLINE'      => SITE_TAGLINE_DEFAULT,
    'SITE_AFFILIATION'  => SITE_AFFILIATION_DEFAULT,
]);

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function auth(): ?array {
    if (!isset($_SESSION['user'])) {
        return null;
    }
    // Idle timeout: if too long has passed since the last authenticated
    // request, treat the session as expired instead of leaving it valid
    // indefinitely (PHP's own session garbage collection is unreliable for
    // this — it's probabilistic and may not run for a long time).
    $lastActivity = $_SESSION['last_activity'] ?? time();
    if ((time() - $lastActivity) > SESSION_IDLE_TIMEOUT) {
        session_unset();
        session_destroy();
        return null;
    }
    $_SESSION['last_activity'] = time();
    return $_SESSION['user'];
}

function requireAuth(string $redirect = 'login.php'): void {
    if (!auth()) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireRole(string $role): void {
    requireAuth();
    if ((auth()['role'] ?? '') !== $role) {
        header('Location: dashboard.php');
        exit;
    }
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $key, string $msg = ''): string {
    if ($msg !== '') {
        $_SESSION['flash'][$key] = $msg;
        return '';
    }
    $val = $_SESSION['flash'][$key] ?? '';
    unset($_SESSION['flash'][$key]);
    return $val;
}

function csrf(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $token = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid request token. <a href="javascript:history.back()">Go back</a>');
    }
}

// ── Email Verification ─────────────────────────────────────────────────
function generateVerificationToken(): string {
    return bin2hex(random_bytes(32));
}

function siteBaseUrl(): string {
    if (SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

function sendVerificationEmail(string $toEmail, string $name, string $token): bool {
    $link = siteBaseUrl() . '/verify.php?token=' . $token;
    $subject = 'Verify your ' . SITE_NAME . ' account';
    $body = "Assalamu Alaikum $name,\n\n"
        . "Thank you for joining " . SITE_NAME . ". Please verify your email address by clicking the link below:\n\n"
        . "$link\n\n"
        . "This link expires in 24 hours. If you did not create this account, you can ignore this email.\n\n"
        . "- " . SITE_NAME . " Team";
    $headers = 'From: no-reply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    return @mail($toEmail, $subject, $body, $headers);
}

// ── Image Upload ──────────────────────────────────────────────────────────
// Returns a relative path to store in the DB, or null if no valid file was uploaded.
function handleImageUpload(string $fieldName, string $subDir): ?string {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    if ($_FILES[$fieldName]['size'] > 5 * 1024 * 1024) {
        return null; // 5MB limit
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!$imageInfo) {
        return null; // not a real image
    }

    $allowedTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!isset($allowedTypes[$imageInfo[2]])) {
        return null;
    }

    $ext = $allowedTypes[$imageInfo[2]];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = __DIR__ . '/uploads/' . $subDir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (!move_uploaded_file($tmpPath, $destDir . '/' . $filename)) {
        return null;
    }

    return 'uploads/' . $subDir . '/' . $filename;
}
