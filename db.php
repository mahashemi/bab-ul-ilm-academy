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

// Students, parents, and institution accounts can all enroll in courses —
// only teachers (who create them) and admins are excluded.
function canEnroll(?string $role): bool {
    return in_array($role, ['student', 'parent', 'institution'], true);
}

// ── Class Chat Moderation ───────────────────────────────────────────────
// Rule-based heuristics (keyword/pattern matching + recent-message lookback)
// — NOT a live AI/LLM call. Deliberately simple, fast, and explainable: every
// flag has a concrete, inspectable reason a teacher can verify at a glance.
// Never deletes or hides anything by itself — it only records a flag for a
// human (teacher/admin) to act on.
function scanMessageForFlags(PDO $pdo, int $courseId, int $senderId, string $body): array {
    $flags = [];
    $normalized = mb_strtolower(trim($body));

    static $flaggedPhrases = [
        'idiot', 'stupid', 'shut up', 'shut the', 'dumb', 'i hate you', 'loser',
        'moron', 'kill you', 'ugly', 'retard', 'worthless',
    ];
    foreach ($flaggedPhrases as $phrase) {
        if ($normalized !== '' && mb_strpos($normalized, $phrase) !== false) {
            $flags[] = ['flag_type' => 'disrespect', 'reason' => 'Contains a flagged word or phrase'];
            break;
        }
    }

    if (preg_match('/https?:\/\/[^\s]+/i', $body)) {
        $flags[] = ['flag_type' => 'suspicious_link', 'reason' => 'Message contains a link'];
    }

    $recent = $pdo->prepare(
        'SELECT body FROM class_messages WHERE course_id = ? AND sender_id = ? AND is_deleted = 0
         ORDER BY created_at DESC LIMIT 5'
    );
    $recent->execute([$courseId, $senderId]);
    $repeatCount = count(array_filter(
        $recent->fetchAll(PDO::FETCH_COLUMN),
        fn($b) => mb_strtolower(trim($b)) === $normalized && $normalized !== ''
    ));
    if ($repeatCount >= 2) {
        $flags[] = ['flag_type' => 'spam', 'reason' => 'Same message repeated ' . ($repeatCount + 1) . ' times in a row'];
    }

    $letters = preg_replace('/[^A-Za-z]/', '', $body);
    if (mb_strlen($letters) >= 10) {
        $upper = preg_replace('/[^A-Z]/', '', $letters);
        if (mb_strlen($upper) / mb_strlen($letters) > 0.7) {
            $flags[] = ['flag_type' => 'spam', 'reason' => 'Excessive capital letters'];
        }
    }
    if (preg_match('/[!?]{4,}/', $body)) {
        $flags[] = ['flag_type' => 'spam', 'reason' => 'Excessive punctuation'];
    }

    return $flags;
}

function recordMessageFlags(PDO $pdo, int $messageId, array $flags): void {
    foreach ($flags as $f) {
        $pdo->prepare('INSERT INTO message_flags (message_id, flag_type, reason) VALUES (?, ?, ?)')
            ->execute([$messageId, $f['flag_type'], $f['reason']]);
    }
}

// Internal participation/conduct score — teacher-only, never shown to the
// student or any other user. Computed from real signals (flags, message
// volume), not a fabricated "AI judgment."
function adjustBehaviorScore(PDO $pdo, int $studentId, int $courseId, int $delta): void {
    $pdo->prepare(
        'INSERT INTO student_behavior_scores (student_id, course_id, score) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE score = score + ?'
    )->execute([$studentId, $courseId, $delta, $delta]);
}

function chatTime(string $dt): string {
    $ts = strtotime($dt);
    $today = date('Y-m-d', $ts) === date('Y-m-d');
    return $today ? date('g:i A', $ts) : date('M j, g:i A', $ts);
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $key, string $msg = ''): string {
    static $cache = [];
    if ($msg !== '') {
        $_SESSION['flash'][$key] = $msg;
        return '';
    }
    // Cache the read so calling flash($key) twice in the same request (once to
    // check truthiness, once to print) doesn't return the value then blank it
    // out before the second call — it's only cleared from the session itself.
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = $_SESSION['flash'][$key] ?? '';
        unset($_SESSION['flash'][$key]);
    }
    return $cache[$key];
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
    $domain = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $nameSafe = e($name);
    $siteSafe = e(SITE_NAME);
    $year = date('Y');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<body style="margin:0;padding:0;background:#faf8f4;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#faf8f4;padding:32px 16px;">
<tr><td align="center">
<table width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e0dbd2;max-width:480px;">
<tr><td style="background:#083344;padding:24px 32px;text-align:center;">
<span style="font-size:22px;color:#d4af5a;font-weight:bold;">🕌 {$siteSafe}</span>
</td></tr>
<tr><td style="padding:32px;">
<p style="font-size:16px;color:#1a1a1a;margin:0 0 16px;">Assalamu Alaikum {$nameSafe},</p>
<p style="font-size:15px;color:#444444;line-height:1.6;margin:0 0 28px;">Thank you for joining {$siteSafe}. Please confirm your email address to activate your account and start learning or teaching.</p>
<table cellpadding="0" cellspacing="0" style="margin:0 auto 28px;">
<tr><td style="border-radius:25px;background:#0e5272;">
<a href="{$link}" style="display:inline-block;padding:14px 36px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;border-radius:25px;">Verify My Account</a>
</td></tr>
</table>
<p style="font-size:13px;color:#888888;line-height:1.6;margin:0 0 4px;">Or copy and paste this link into your browser:</p>
<p style="font-size:13px;margin:0 0 24px;"><a href="{$link}" style="color:#0e5272;word-break:break-all;">{$link}</a></p>
<p style="font-size:13px;color:#888888;margin:0;">This link expires in 24 hours. If you didn't create this account, you can safely ignore this email.</p>
</td></tr>
<tr><td style="background:#faf8f4;padding:16px 32px;text-align:center;border-top:1px solid #e0dbd2;">
<span style="font-size:12px;color:#aaaaaa;">© {$year} {$siteSafe}</span>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;

    $headers  = "From: {$siteSafe} <no-reply@{$domain}>\r\n";
    $headers .= "Reply-To: no-reply@{$domain}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    return @mail($toEmail, $subject, $html, $headers);
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

function catIcon(?string $iconName): string {
    return '<i data-lucide="' . e($iconName ?: 'book-open') . '" class="lucide-icon"></i>';
}

// Shared rich course card markup — used on the homepage carousels and the
// courses browse page so cards stay visually identical everywhere.
function renderCourseCard(array $c, array $bestsellerIds = []): string {
    $rating = round((float) $c['avg_rating'], 1);
    $reviewCount = (int) $c['review_count'];
    $hours = round((int) $c['total_minutes'] / 60, 1);
    $isBestseller = in_array((int) $c['id'], $bestsellerIds, true);
    $stars = str_repeat('★', (int) floor($rating)) . str_repeat('☆', 5 - (int) floor($rating));

    ob_start();
    ?>
    <a href="course.php?id=<?= (int) $c['id'] ?>" class="course-card" style="text-decoration:none;color:inherit">
        <div class="course-cover">
            <?php if ($c['cover_url']): ?><img src="<?= e($c['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($c['subject_icon']) ?><?php endif; ?>
            <?php if ($isBestseller): ?><span class="course-bestseller">Bestseller</span><?php endif; ?>
            <span class="badge badge-<?= e($c['level']) ?> course-level"><?= e(ucfirst($c['level'])) ?></span>
        </div>
        <div class="course-body">
            <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
            <div class="course-title"><?= e($c['title']) ?></div>
            <?php if ($reviewCount > 0): ?>
                <div class="course-rating-mini"><span class="num"><?= number_format($rating, 1) ?></span><span class="stars"><?= $stars ?></span><span class="count">(<?= $reviewCount ?>)</span></div>
            <?php endif; ?>
            <div class="course-meta">
                <span><i data-lucide="user" class="lucide-icon"></i> <?= e($c['teacher_name']) ?></span>
            </div>
            <div class="course-meta" style="margin-top:.3rem">
                <?php if ($hours > 0): ?><span><i data-lucide="clock" class="lucide-icon"></i> <?= $hours ?>h</span><?php endif; ?>
                <span><i data-lucide="clipboard-list" class="lucide-icon"></i> <?= (int) $c['lesson_count'] ?> lessons</span>
                <span><i data-lucide="graduation-cap" class="lucide-icon"></i> <?= (int) $c['student_count'] ?></span>
            </div>
        </div>
        <div class="course-footer">
            <span class="course-price <?= $c['price'] == 0 ? 'free' : '' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
            <span class="btn btn-outline btn-sm">View <i data-lucide="arrow-right" class="lucide-icon"></i></span>
        </div>

        <div class="course-hover-info">
            <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
            <h4><?= e($c['title']) ?></h4>
            <?php if ($reviewCount > 0): ?>
                <div class="course-rating-mini"><span class="num"><?= number_format($rating, 1) ?></span><span class="stars"><?= $stars ?></span><span class="count">(<?= $reviewCount ?>)</span></div>
            <?php endif; ?>
            <?php if (!empty($c['description'])): ?><p><?= e($c['description']) ?></p><?php endif; ?>
            <div class="meta">
                <span><i data-lucide="signal" class="lucide-icon"></i> <?= e(ucfirst($c['level'])) ?></span>
                <span><i data-lucide="clipboard-list" class="lucide-icon"></i> <?= (int) $c['lesson_count'] ?> lessons</span>
                <?php if ($hours > 0): ?><span><i data-lucide="clock" class="lucide-icon"></i> <?= $hours ?>h</span><?php endif; ?>
            </div>
            <div class="cta">
                <span class="course-price <?= $c['price'] == 0 ? 'free' : '' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
                <span class="btn btn-primary btn-sm">View Course <i data-lucide="arrow-right" class="lucide-icon"></i></span>
            </div>
        </div>
    </a>
    <?php
    return ob_get_clean();
}

function siteSetting(PDO $pdo, string $key): ?string {
    $stmt = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== '' ? $val : null;
}
