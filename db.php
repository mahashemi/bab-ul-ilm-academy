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

// Registration only collects the minimum (name/email/country/password) — everything
// else is filled in later from Edit Profile. This scores how much of that optional
// detail is filled in, so the dashboard can nudge users to complete it.
function profileCompletionPercent(PDO $pdo, array $me): int {
    $checks = [
        !empty($me['bio']),
        !empty($me['phone']),
        ($me['gender'] ?? 'unspecified') !== 'unspecified',
        !empty($me['date_of_birth']),
        !empty($me['preferred_language']),
    ];
    if (in_array($me['role'], ['student', 'parent'], true)) {
        $checks[] = !empty($me['education_level']);
    }
    if ($me['role'] === 'teacher') {
        $checks[] = !empty($me['headline']);
    }

    $fieldCount = $pdo->prepare('SELECT COUNT(*) FROM user_learning_fields WHERE user_id = ?');
    $fieldCount->execute([$me['id']]);
    $checks[] = (int) $fieldCount->fetchColumn() > 0;

    $skillCount = $pdo->prepare('SELECT COUNT(*) FROM user_skills WHERE user_id = ?');
    $skillCount->execute([$me['id']]);
    $checks[] = (int) $skillCount->fetchColumn() > 0;

    $done = count(array_filter($checks));
    return $checks ? (int) round($done / count($checks) * 100) : 100;
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

// ── Gamification: points & badges ───────────────────────────────────────
// Points map only to real platform actions (enroll, complete a lesson, post
// a clean class-chat message, leave/receive a review, get a course approved)
// — no fabricated quiz/assignment features that don't exist yet. Every award
// is logged in point_log for an auditable history, and awarding points always
// re-checks badge eligibility since several badges are point-threshold based.
function awardPoints(PDO $pdo, int $userId, int $points, string $reason): void {
    $pdo->prepare('INSERT INTO user_points (user_id, points) VALUES (?, ?) ON DUPLICATE KEY UPDATE points = points + ?')
        ->execute([$userId, $points, $points]);
    $pdo->prepare('INSERT INTO point_log (user_id, points, reason) VALUES (?, ?, ?)')
        ->execute([$userId, $points, $reason]);
    $newBadges = checkAndAwardBadges($pdo, $userId);

    // Build an on-screen celebration only for the user's own browser session —
    // e.g. a teacher passively earning points from a student's review isn't
    // in this request at all, so there's no session to pop a celebration in.
    // Multiple awardPoints() calls in one request (lesson + course-completion
    // bonus) accumulate into a single combined celebration instead of
    // overwriting each other.
    if (isset($_SESSION['user']) && (int) $_SESSION['user']['id'] === $userId) {
        $existing = $_SESSION['points_celebration'] ?? ['earned' => 0, 'badges' => []];
        $existing['earned'] += $points;
        $existing['total'] = getUserPoints($pdo, $userId);
        $existing['badges'] = array_merge($existing['badges'], $newBadges);
        $_SESSION['points_celebration'] = $existing;
    }
}

function popPointsCelebration(): ?array {
    $data = $_SESSION['points_celebration'] ?? null;
    unset($_SESSION['points_celebration']);
    return $data;
}

function getUserPoints(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare('SELECT points FROM user_points WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

// Returns the badge's [name, icon] when newly awarded this call, or null if
// already owned / the code doesn't exist. Used both to drive the in-app
// celebration popup and the badge-earned email.
function awardBadge(PDO $pdo, int $userId, string $code): ?array {
    $badge = $pdo->prepare('SELECT id, name, description, icon FROM badges WHERE code = ?');
    $badge->execute([$code]);
    $badge = $badge->fetch();
    if (!$badge) return null;
    $stmt = $pdo->prepare('INSERT IGNORE INTO user_badges (user_id, badge_id) VALUES (?, ?)');
    $stmt->execute([$userId, $badge['id']]);
    if ($stmt->rowCount() === 0) return null;

    notifyUser($pdo, $userId, 'badge_earned', (int) $badge['id'], 5, function ($u) use ($badge) {
        $nameSafe = e($badge['name']);
        $descSafe = e($badge['description']);
        return [
            'New badge earned: ' . $badge['name'],
            '<p style="margin:0 0 16px">You just earned the <strong>' . $nameSafe . '</strong> badge — ' . $descSafe . '. Keep going!</p>',
            'View Your Badges',
            siteBaseUrl() . '/dashboard.php',
        ];
    });
    return ['name' => $badge['name'], 'icon' => $badge['icon']];
}

// Re-evaluates every badge condition for a user and returns any newly earned
// this call (for the celebration popup). Idempotent — awardBadge() uses
// INSERT IGNORE — so it's safe to call after any point-earning action.
function checkAndAwardBadges(PDO $pdo, int $userId): array {
    $newBadges = [];
    $check = function (string $code) use ($pdo, $userId, &$newBadges) {
        if ($b = awardBadge($pdo, $userId, $code)) $newBadges[] = $b;
    };

    $userStmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $role = $userStmt->fetchColumn();

    $points = getUserPoints($pdo, $userId);
    if ($points >= 100) $check('bronze_learner');
    if ($points >= 500) $check('silver_learner');
    if ($points >= 1000) $check('gold_learner');

    $enrollStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ?');
    $enrollStmt->execute([$userId]);
    if ((int) $enrollStmt->fetchColumn() >= 1) $check('first_enrollment');

    $completedStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT e.course_id
            FROM enrollments e
            JOIN lessons l ON l.course_id = e.course_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.id AND lp.student_id = e.student_id
            WHERE e.student_id = ?
            GROUP BY e.course_id
            HAVING COUNT(l.id) > 0 AND COUNT(l.id) = COUNT(lp.lesson_id)
        ) completed"
    );
    $completedStmt->execute([$userId]);
    $completedCount = (int) $completedStmt->fetchColumn();
    if ($completedCount >= 1) $check('first_completion');
    if ($completedCount >= 5) $check('five_completions');

    $skillStmt = $pdo->prepare('SELECT COUNT(*) FROM user_skills WHERE user_id = ?');
    $skillStmt->execute([$userId]);
    if ((int) $skillStmt->fetchColumn() >= 5) $check('skilled');

    $chatStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM class_messages cm
         WHERE cm.sender_id = ? AND cm.is_deleted = 0
         AND NOT EXISTS (SELECT 1 FROM message_flags mf WHERE mf.message_id = cm.id)'
    );
    $chatStmt->execute([$userId]);
    if ((int) $chatStmt->fetchColumn() >= 10) $check('chatty');

    if ($role === 'teacher') {
        $publishedStmt = $pdo->prepare("SELECT COUNT(*) FROM courses WHERE teacher_id = ? AND moderation_status = 'approved'");
        $publishedStmt->execute([$userId]);
        if ((int) $publishedStmt->fetchColumn() >= 1) $check('published_teacher');

        $studentStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.teacher_id = ?');
        $studentStmt->execute([$userId]);
        if ((int) $studentStmt->fetchColumn() >= 50) $check('popular_teacher');

        $ratingStmt = $pdo->prepare(
            'SELECT AVG(r.rating) avg_rating, COUNT(*) review_count
             FROM course_reviews r JOIN courses c ON c.id = r.course_id WHERE c.teacher_id = ?'
        );
        $ratingStmt->execute([$userId]);
        $ratingRow = $ratingStmt->fetch();
        if ($ratingRow && (float) $ratingRow['avg_rating'] >= 4.5 && (int) $ratingRow['review_count'] >= 5) {
            $check('top_rated');
        }
    }

    return $newBadges;
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

// Security activity log — lets a user see "is this really my recent
// activity" (logins, password changes, etc.), same idea as Google/
// Facebook's account activity page. Best-effort: never blocks the action
// it's logging, so a logging failure can't break login/password-change.
function logActivity(PDO $pdo, int $userId, string $action): void {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $pdo->prepare('INSERT INTO account_activity_log (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $action, $ip, $ua]);
    } catch (Exception $e) {
        // swallow — activity logging is best-effort, never fatal
    }
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

function sendPasswordResetEmail(string $toEmail, string $name, string $token): bool {
    $link = siteBaseUrl() . '/reset-password.php?token=' . $token;
    return sendNotificationEmail(
        $toEmail, $name,
        'Reset your ' . SITE_NAME . ' password',
        '<p style="margin:0 0 16px">We received a request to reset your password. Click the button below to choose a new one — this link expires in 1 hour.</p>'
            . '<p style="margin:0;font-size:13px;color:#888888">If you didn\'t request this, you can safely ignore this email — your password will not be changed.</p>',
        'Reset My Password',
        $link
    );
}

// ── Engagement notification emails ──────────────────────────────────────
// Shared branded template for every "something happened" email (new
// message, course approved, badge earned, etc.) so each call site only
// supplies a subject + a short HTML fragment, not a whole template.
// $bodyHtml may contain markup but any user-supplied text inside it must
// already be passed through e() by the caller before interpolating.
function sendNotificationEmail(string $toEmail, string $name, string $subject, string $bodyHtml, string $ctaText, string $ctaLink): bool {
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
<div style="font-size:15px;color:#444444;line-height:1.6;margin:0 0 28px;">{$bodyHtml}</div>
<table cellpadding="0" cellspacing="0" style="margin:0 auto;">
<tr><td style="border-radius:25px;background:#0e5272;">
<a href="{$ctaLink}" style="display:inline-block;padding:14px 36px;color:#ffffff;text-decoration:none;font-size:15px;font-weight:bold;border-radius:25px;">{$ctaText}</a>
</td></tr>
</table>
</td></tr>
<tr><td style="background:#faf8f4;padding:16px 32px;text-align:center;border-top:1px solid #e0dbd2;">
<span style="font-size:12px;color:#aaaaaa;">© {$year} {$siteSafe} — You're receiving this because of activity on your account.</span>
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

// Cooldown-gated notification dispatch — the actual anti-spam mechanism.
// Skips silently (returns false) if the same (user, type, related_id) was
// already emailed within $cooldownMinutes, so frequent events (chat
// messages, enrollments) can't flood an inbox. $buildEmail receives the
// recipient row and must return [subject, bodyHtml, ctaText, ctaLink].
function notifyUser(PDO $pdo, int $userId, string $type, int $relatedId, int $cooldownMinutes, callable $buildEmail): bool {
    $check = $pdo->prepare(
        'SELECT 1 FROM notification_log WHERE user_id = ? AND type = ? AND related_id = ?
         AND sent_at > DATE_SUB(NOW(), INTERVAL ? MINUTE) LIMIT 1'
    );
    $check->execute([$userId, $type, $relatedId, $cooldownMinutes]);
    if ($check->fetch()) return false;

    $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $u = $userStmt->fetch();
    if (!$u) return false;

    [$subject, $bodyHtml, $ctaText, $ctaLink] = $buildEmail($u);
    $sent = sendNotificationEmail($u['email'], $u['name'], $subject, $bodyHtml, $ctaText, $ctaLink);
    if ($sent) {
        $pdo->prepare('INSERT INTO notification_log (user_id, type, related_id) VALUES (?, ?, ?)')->execute([$userId, $type, $relatedId]);
    }
    return $sent;
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

// The name shown publicly (class chat, instructor byline, navbar) — falls
// back to the account's legal name when no display name is set. The legal
// name itself is never overwritten/hidden, just not shown by default.
function displayNameOf(?array $user): string {
    if (!$user) return '?';
    return ($user['display_name'] ?? '') ?: ($user['name'] ?? '?');
}

// Renders a profile photo if the user has uploaded one, falling back to the
// existing initial-letter circle otherwise — both fit the same nav-avatar/
// chat-avatar sizing classes, so this is a drop-in replacement wherever an
// avatar is shown. $user only needs 'name'/'display_name' and 'avatar' keys.
function renderAvatar(?array $user, string $class = 'nav-avatar'): string {
    $name = displayNameOf($user);
    if (!empty($user['avatar'])) {
        return '<img src="' . e($user['avatar']) . '" alt="" class="' . e($class) . ' ' . e($class) . '-img">';
    }
    return '<span class="' . e($class) . '">' . e(mb_substr($name, 0, 1)) . '</span>';
}

// Pops and renders the points-celebration popup (see awardPoints() /
// popPointsCelebration()) — call once near the top of <body> on any page
// reached right after a point-earning action. Returns '' when there's
// nothing to celebrate, so it's safe to echo unconditionally.
function renderPointsCelebration(): string {
    $c = popPointsCelebration();
    if (!$c || $c['earned'] <= 0) return '';

    ob_start();
    ?>
    <div class="points-celebration-overlay" id="pointsCelebration">
        <div class="points-celebration-modal">
            <button type="button" class="points-celebration-close" onclick="document.getElementById('pointsCelebration').remove()" aria-label="Close">&times;</button>
            <div class="points-celebration-burst"><i data-lucide="sparkles" class="lucide-icon"></i></div>
            <div class="points-celebration-amount">+<?= (int) $c['earned'] ?> Points</div>
            <div class="points-celebration-total"><i data-lucide="zap" class="lucide-icon"></i> Total Points: <strong><?= number_format((int) $c['total']) ?></strong></div>
            <?php if ($c['badges']): ?>
            <div class="points-celebration-badges">
                <?php foreach ($c['badges'] as $b): ?>
                    <div class="points-celebration-badge"><?= catIcon($b['icon']) ?> <span>New Badge: <?= e($b['name']) ?></span></div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="points-celebration-actions">
                <a href="dashboard.php" class="btn btn-outline">See Your Achievements</a>
                <button type="button" class="btn btn-primary" onclick="document.getElementById('pointsCelebration').remove()">Keep Learning</button>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
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
