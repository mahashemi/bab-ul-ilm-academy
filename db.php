<?php
require_once __DIR__ . '/config.php';

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

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function auth(): ?array {
    return $_SESSION['user'] ?? null;
}

function requireAuth(string $redirect = 'login.php'): void {
    if (!isset($_SESSION['user'])) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireRole(string $role): void {
    requireAuth();
    if (($_SESSION['user']['role'] ?? '') !== $role) {
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
