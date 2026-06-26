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
    'SITE_NAME'           => SITE_NAME_DEFAULT,
    'SITE_TAGLINE'        => SITE_TAGLINE_DEFAULT,
    'SITE_AFFILIATION'    => SITE_AFFILIATION_DEFAULT,
    // defined() guard: config.php is skip-worktree'd on production, so a
    // brand-new *_DEFAULT constant added here can land in this file via
    // git pull before that server's actual config.php has been manually
    // updated to define it -- referencing it unconditionally would be a
    // fatal "undefined constant" error on every single page load.
    'HOME_HERO_HEADLINE'  => defined('HOME_HERO_HEADLINE_DEFAULT') ? HOME_HERO_HEADLINE_DEFAULT : 'Teach and Learn Any Subject — <span>All Levels, Anywhere, Everywhere</span>',
]);

// Drives the "Delivered" message state (recipient has been active on the
// site since the message was sent, even without opening that specific
// conversation) — runs on every page load since db.php is required
// everywhere, but the WHERE clause means it only ever writes once a
// minute per user, not on every single request.
if (isset($_SESSION['user']['id'])) {
    try {
        $pdo->prepare(
            'UPDATE users SET last_active_at = NOW() WHERE id = ? AND (last_active_at IS NULL OR last_active_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE))'
        )->execute([(int) $_SESSION['user']['id']]);
    } catch (Exception $e) {
        // best-effort — never let this break page rendering
    }
}

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

// requireRole('teacher') used to gate teacher-only pages; teaching is no
// longer a role value, so this checks isApprovedTeacher() instead. Returns
// the user array (callers need it anyway, e.g. for displayNameOf()).
function requireApprovedTeacher(): array {
    requireAuth();
    $user = auth();
    if (!isApprovedTeacher($user)) {
        header('Location: dashboard.php');
        exit;
    }
    return $user;
}

// Teaching is an orthogonal capability (see isApprovedTeacher()), not a
// different account type -- every real account can enroll, including
// approved teachers. Only internal staff accounts are excluded.
function canEnroll(?string $role): bool {
    return !in_array($role, ['admin', 'customer_service'], true);
}

// The single source of truth for "can this account create/manage courses" —
// independent of `role`, which is now just the account's base identity.
// Approving an instructor application (admin.php) is the only thing that
// sets this to 'approved'; existing pre-this-feature teacher accounts were
// grandfathered in via a one-time migration (see schema.sql).
function isApprovedTeacher(array $user): bool {
    return ($user['teacher_status'] ?? 'none') === 'approved';
}

function roleLabel(string $role): string {
    $labels = ['customer_service' => 'Customer Service'];
    return $labels[$role] ?? ucfirst($role);
}

// ── Customer Service "Acting As Teacher" ────────────────────────────────
// A CS executive doesn't author content under their own account -- on a
// support call they gather a teacher's course info, pick that teacher on
// support-panel.php, and from then on the course/lesson/quiz/assignment
// authoring pages create content attributed to THAT teacher. Every
// create/edit action is still logActivity()'d under the CS rep's own
// user id (see the authoring pages), so "who actually did this" stays
// auditable even though the content correctly belongs to the teacher.

// The teacher_id content should be attributed to: the logged-in user's
// own id if they ARE a teacher, or whichever teacher an admin/customer_service
// rep has selected, or null if neither applies.
function effectiveTeacherId(array $user): ?int {
    if (isApprovedTeacher($user)) {
        return (int) $user['id'];
    }
    if (in_array($user['role'] ?? '', ['customer_service', 'admin'], true) && !empty($_SESSION['acting_as_teacher_id'])) {
        return (int) $_SESSION['acting_as_teacher_id'];
    }
    return null;
}

// Like requireRole('teacher') used to be, but checks isApprovedTeacher()
// instead of role, and also admits an admin or customer_service rep who
// has already selected a teacher on support-panel.php. Returns the
// effective teacher_id for the caller to use in place of auth()['id']
// wherever content is being created or its ownership checked.
function requireTeacherOrSupport(): int {
    requireAuth();
    $user = auth();
    $role = $user['role'] ?? '';
    if (isApprovedTeacher($user)) {
        return (int) $user['id'];
    }
    if (in_array($role, ['customer_service', 'admin'], true)) {
        $teacherId = effectiveTeacherId($user);
        if ($teacherId) return $teacherId;
        flash('error', 'Select a teacher to act on behalf of first.');
        header('Location: support-panel.php');
        exit;
    }
    header('Location: dashboard.php');
    exit;
}

// Persistent banner on the authoring pages while a customer_service rep
// is acting on behalf of a teacher, so it is never ambiguous whose
// course is actually being edited.
function renderActingAsBanner(PDO $pdo): string {
    $user = auth();
    if (!in_array($user['role'] ?? '', ['customer_service', 'admin'], true) || empty($_SESSION['acting_as_teacher_id'])) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT COALESCE(display_name, name) AS name, email FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['acting_as_teacher_id']]);
    $teacher = $stmt->fetch();
    if (!$teacher) return '';
    ob_start();
    ?>
    <div class="acting-as-banner">
        <i data-lucide="headset" class="lucide-icon"></i>
        Acting on behalf of <strong><?= e($teacher['name']) ?></strong> <span style="opacity:.75">(<?= e($teacher['email']) ?>)</span>
        <a href="support-panel.php">Switch teacher</a>
    </div>
    <?php
    return ob_get_clean();
}

// Renders the Udemy-style left sidebar shared across the course
// creation/edit flow (add-course.php, edit-course.php, and a non-active
// link out to add-lesson.php for Curriculum). $courseId is null only on
// add-course.php before the course is first saved — every step besides
// Basics is disabled (greyed, unclickable) until a course exists, since
// there's nothing yet to attach a cover/price/curriculum to.
function renderCourseWizardSidebar(?int $courseId, string $activeStep): string {
    $groups = [
        'Plan your course' => [
            'basics' => ['label' => 'Basics', 'icon' => 'file-text'],
        ],
        'Create your content' => [
            'cover' => ['label' => 'Cover Image', 'icon' => 'image'],
            'curriculum' => ['label' => 'Curriculum', 'icon' => 'list-checks'],
        ],
        'Publish your course' => [
            'pricing' => ['label' => 'Pricing', 'icon' => 'dollar-sign'],
            'publish' => ['label' => 'Publish', 'icon' => 'rocket'],
        ],
    ];
    ob_start();
    ?>
    <aside class="course-wizard-sidebar">
        <?php foreach ($groups as $groupLabel => $items): ?>
        <div class="wizard-group-label"><?= e($groupLabel) ?></div>
        <?php foreach ($items as $step => $info):
            $isActive = $step === $activeStep;
            $isDisabled = $courseId === null && $step !== 'basics';
            $href = $isDisabled ? '#' : ($step === 'curriculum'
                ? 'add-lesson.php?course_id=' . $courseId
                : ($step === 'basics' && $courseId === null ? 'add-course.php' : 'edit-course.php?id=' . $courseId . '&step=' . $step));
        ?>
            <a href="<?= e($href) ?>" class="wizard-step<?= $isActive ? ' active' : '' ?><?= $isDisabled ? ' disabled' : '' ?>" <?= $isDisabled ? 'onclick="return false" title="Save Basics first"' : '' ?>>
                <i data-lucide="<?= e($info['icon']) ?>" class="lucide-icon"></i>
                <?= e($info['label']) ?>
            </a>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </aside>
    <?php
    return ob_get_clean();
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
    if (isApprovedTeacher($me)) {
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

    $userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $userStmt->execute([$userId]);
    $badgeUser = $userStmt->fetch();

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

    if (isApprovedTeacher($badgeUser)) {
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

// Issues a certificate the first time a student reaches 100% lesson
// completion in a course (same definition already used for the "Course
// Graduate" badge) — idempotent, safe to call on every lesson completion.
// Returns the certificate row (new or existing) so callers can show a
// "Certificate earned!" notice only when it's genuinely new.
function issueCertificateIfEligible(PDO $pdo, int $studentId, int $courseId): ?array {
    $existing = $pdo->prepare('SELECT * FROM certificates WHERE student_id = ? AND course_id = ?');
    $existing->execute([$studentId, $courseId]);
    if ($existing0 = $existing->fetch()) return ['row' => $existing0, 'isNew' => false];

    $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM lessons WHERE course_id = ?');
    $totalStmt->execute([$courseId]);
    $total = (int) $totalStmt->fetchColumn();
    if ($total === 0) return null;

    $doneStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l ON l.id = lp.lesson_id
         WHERE lp.student_id = ? AND l.course_id = ?'
    );
    $doneStmt->execute([$studentId, $courseId]);
    if ((int) $doneStmt->fetchColumn() < $total) return null;

    $code = strtoupper(bin2hex(random_bytes(6)));
    $pdo->prepare('INSERT IGNORE INTO certificates (student_id, course_id, certificate_code) VALUES (?, ?, ?)')
        ->execute([$studentId, $courseId, $code]);

    $row = $pdo->prepare('SELECT * FROM certificates WHERE student_id = ? AND course_id = ?');
    $row->execute([$studentId, $courseId]);
    return ['row' => $row->fetch(), 'isNew' => true];
}

function siteBaseUrl(): string {
    if (SITE_URL !== '') return rtrim(SITE_URL, '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $_SERVER['HTTP_HOST'] . $dir;
}

// ── Social Login (OAuth) ─────────────────────────────────────────────────
// defined() guard: config.php is skip-worktree'd on production, so
// OAUTH_PROVIDERS landing in code via git pull doesn't mean a given
// server's actual config.php defines it yet (the same lesson as
// HOME_HERO_HEADLINE_DEFAULT earlier) -- referencing it unconditionally
// would be a fatal "undefined constant" error on every page that calls
// these helpers, including login.php/register.php on every load.
function oauthProviders(): array {
    return defined('OAUTH_PROVIDERS') ? OAUTH_PROVIDERS : [];
}

function oauthProviderConfig(string $provider): ?array {
    return oauthProviders()[$provider] ?? null;
}

// A provider only shows a "Continue with X" button once both halves of
// its credential pair are filled in -- never a button that's guaranteed
// to fail because config.php still has empty placeholders.
function oauthConfigured(string $provider): bool {
    $cfg = oauthProviderConfig($provider);
    return $cfg && $cfg['client_id'] !== '' && $cfg['client_secret'] !== '';
}

function oauthRedirectUri(string $provider): string {
    return siteBaseUrl() . '/oauth-callback.php?provider=' . urlencode($provider);
}

function oauthAuthUrl(string $provider, string $state): ?string {
    $cfg = oauthProviderConfig($provider);
    if (!$cfg || !oauthConfigured($provider)) return null;
    $params = [
        'client_id'     => $cfg['client_id'],
        'redirect_uri'  => oauthRedirectUri($provider),
        'response_type' => 'code',
        'scope'         => $cfg['scope'],
        'state'         => $state,
    ];
    if ($provider === 'google') { $params['access_type'] = 'online'; $params['prompt'] = 'select_account'; }
    return $cfg['auth_url'] . '?' . http_build_query($params);
}

function oauthHttpPost(string $url, array $params, array $extraHeaders = []): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: BabUlIlmAcademy', 'Accept: application/json'], $extraHeaders),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    return $ok ? json_decode($response, true) : null;
}

function oauthHttpGet(string $url, string $accessToken, bool $githubStyleAuth = false): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'User-Agent: BabUlIlmAcademy',
            'Accept: application/json',
            ($githubStyleAuth ? 'Authorization: token ' : 'Authorization: Bearer ') . $accessToken,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    return $ok ? json_decode($response, true) : null;
}

// Exchanges the authorization code for an access token, then fetches and
// normalizes that provider's profile into a common ['id','email','name',
// 'avatar'] shape -- each provider's raw response differs (Google:
// sub/email/name/picture; Facebook: id/email/name/picture.data.url;
// Microsoft Graph: id/mail-or-userPrincipalName/displayName, no direct
// photo URL since that needs a separate binary-image call, skipped here;
// GitHub: id/email(often null if kept private)/name/avatar_url, with a
// fallback call to /user/emails for the verified primary address).
// Returns null on any failure -- callers show a generic "couldn't sign
// in with X" rather than leaking provider-specific error detail.
function oauthFetchProfile(string $provider, string $code): ?array {
    $cfg = oauthProviderConfig($provider);
    if (!$cfg) return null;

    $tokenData = oauthHttpPost($cfg['token_url'], [
        'client_id'     => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'code'          => $code,
        'redirect_uri'  => oauthRedirectUri($provider),
        'grant_type'    => 'authorization_code',
    ]);
    $accessToken = $tokenData['access_token'] ?? null;
    if (!$accessToken) return null;

    switch ($provider) {
        case 'google':
            $p = oauthHttpGet('https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);
            if (!$p) return null;
            return ['id' => $p['sub'] ?? null, 'email' => $p['email'] ?? null, 'name' => $p['name'] ?? null, 'avatar' => $p['picture'] ?? null];

        case 'facebook':
            $p = oauthHttpGet('https://graph.facebook.com/me?fields=id,name,email,picture', $accessToken);
            if (!$p) return null;
            return ['id' => $p['id'] ?? null, 'email' => $p['email'] ?? null, 'name' => $p['name'] ?? null, 'avatar' => $p['picture']['data']['url'] ?? null];

        case 'microsoft':
            $p = oauthHttpGet('https://graph.microsoft.com/v1.0/me', $accessToken);
            if (!$p) return null;
            return ['id' => $p['id'] ?? null, 'email' => $p['mail'] ?? $p['userPrincipalName'] ?? null, 'name' => $p['displayName'] ?? null, 'avatar' => null];

        case 'github':
            $p = oauthHttpGet('https://api.github.com/user', $accessToken, true);
            if (!$p) return null;
            $email = $p['email'] ?? null;
            if (!$email) {
                $emails = oauthHttpGet('https://api.github.com/user/emails', $accessToken, true);
                foreach ((array) $emails as $e) {
                    if (!empty($e['primary']) && !empty($e['verified'])) { $email = $e['email']; break; }
                }
            }
            return ['id' => isset($p['id']) ? (string) $p['id'] : null, 'email' => $email, 'name' => $p['name'] ?? $p['login'] ?? null, 'avatar' => $p['avatar_url'] ?? null];

        default:
            return null;
    }
}

// Finds-or-creates the account matching a normalized OAuth profile and
// logs it in. An existing password-based (or different-provider) account
// with a matching email gets this provider linked to it rather than a
// duplicate account created -- Google/Facebook/Microsoft/GitHub all
// verify the email themselves before handing it to us, so a matching
// email is treated as the same person, the same trust assumption every
// "Sign in with Google" implementation makes. New accounts default to
// the 'student' role (the common case) since the whole point of this
// flow is removing friction, not adding a role-picker step back in --
// switchable later from Edit Profile same as any other account detail.
// Returns ['user' => array, 'error' => ?string].
function oauthLoginOrRegister(PDO $pdo, string $provider, array $profile): array {
    $email = trim((string) ($profile['email'] ?? ''));
    $oauthId = trim((string) ($profile['id'] ?? ''));
    if ($email === '' || $oauthId === '') {
        return ['user' => null, 'error' => 'That ' . ucfirst($provider) . ' account did not share an email address with us, so we could not sign you in.'];
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE oauth_provider = ? AND oauth_id = ?');
    $stmt->execute([$provider, $oauthId]);
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $pdo->prepare('UPDATE users SET oauth_provider = ?, oauth_id = ?, is_verified = 1 WHERE id = ?')
                ->execute([$provider, $oauthId, $user['id']]);
            $user['oauth_provider'] = $provider;
            $user['is_verified'] = 1;
        } else {
            $name = trim((string) ($profile['name'] ?? '')) ?: explode('@', $email)[0];
            $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $pdo->prepare(
                'INSERT INTO users (name, email, password, role, oauth_provider, oauth_id, avatar, is_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
            )->execute([$name, $email, $randomPassword, 'student', $provider, $oauthId, $profile['avatar'] ?? null]);
            $newId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
            $stmt->execute([$newId]);
            $user = $stmt->fetch();
            logActivity($pdo, $newId, 'Account created via ' . ucfirst($provider) . ' sign-in');
        }
    }

    if (!$user['is_approved']) {
        return ['user' => null, 'error' => 'Your account has been suspended. Please contact the administrator.'];
    }

    $_SESSION['user'] = [
        'id' => $user['id'], 'name' => $user['name'], 'display_name' => $user['display_name'] ?? null,
        'email' => $user['email'], 'role' => $user['role'], 'teacher_status' => $user['teacher_status'] ?? 'none',
        'avatar' => $user['avatar'],
    ];
    $_SESSION['last_activity'] = time();
    logActivity($pdo, (int) $user['id'], 'Logged in via ' . ucfirst($provider));
    return ['user' => $user, 'error' => null];
}

// Brand marks for the "Continue with X" buttons -- inline SVG rather than
// a Lucide icon (Lucide is a generic UI icon set with no brand/logo
// icons) and rather than pulling in a whole separate icon-font library
// just for four logos.
function oauthProviderIcon(string $provider): string {
    $icons = [
        'google' => '<svg viewBox="0 0 48 48" width="20" height="20"><path fill="#FFC107" d="M43.611,20.083H42V20H24v8h11.303c-1.649,4.657-6.08,8-11.303,8c-6.627,0-12-5.373-12-12c0-6.627,5.373-12,12-12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C12.955,4,4,12.955,4,24c0,11.045,8.955,20,20,20c11.045,0,20-8.955,20-20C44,22.659,43.862,21.35,43.611,20.083z"/><path fill="#FF3D00" d="M6.306,14.691l6.571,4.819C14.655,15.108,18.961,12,24,12c3.059,0,5.842,1.154,7.961,3.039l5.657-5.657C34.046,6.053,29.268,4,24,4C16.318,4,9.656,8.337,6.306,14.691z"/><path fill="#4CAF50" d="M24,44c5.166,0,9.86-1.977,13.409-5.192l-6.19-5.238C29.211,35.091,26.715,36,24,36c-5.202,0-9.619-3.317-11.283-7.946l-6.522,5.025C9.505,39.556,16.227,44,24,44z"/><path fill="#1976D2" d="M43.611,20.083H42V20H24v8h11.303c-0.792,2.237-2.231,4.166-4.087,5.571c0.001-0.001,0.002-0.001,0.003-0.002l6.19,5.238C36.971,39.205,44,34,44,24C44,22.659,43.862,21.35,43.611,20.083z"/></svg>',
        'facebook' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="#1877F2"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
        'microsoft' => '<svg viewBox="0 0 21 21" width="20" height="20"><rect x="1" y="1" width="9" height="9" fill="#F25022"/><rect x="11" y="1" width="9" height="9" fill="#7FBA00"/><rect x="1" y="11" width="9" height="9" fill="#00A4EF"/><rect x="11" y="11" width="9" height="9" fill="#FFB900"/></svg>',
        'github' => '<svg viewBox="0 0 16 16" width="20" height="20" fill="#181717"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>',
    ];
    return $icons[$provider] ?? '';
}

// Shared "Continue with X" button row for login.php/register.php --
// empty string (renders nothing) if no provider has both client_id and
// client_secret filled in yet, so the divider/buttons don't show up
// floating above an otherwise-unchanged form.
function renderOauthButtons(): string {
    $available = array_filter(array_keys(oauthProviders()), 'oauthConfigured');
    if (!$available) return '';
    ob_start();
    ?>
    <div class="oauth-buttons">
        <?php foreach ($available as $p): $cfg = oauthProviderConfig($p); ?>
            <a href="oauth-login.php?provider=<?= e($p) ?>" class="oauth-btn"><?= oauthProviderIcon($p) ?> Continue with <?= e($cfg['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="auth-divider"><span>or</span></div>
    <?php
    return ob_get_clean();
}

// ── Cart & Checkout ──────────────────────────────────────────────────────
function getCartItems(PDO $pdo, int $studentId): array {
    $stmt = $pdo->prepare(
        "SELECT ci.id AS cart_item_id, c.*, COALESCE(u.display_name, u.name) AS teacher_name
         FROM cart_items ci JOIN courses c ON c.id = ci.course_id JOIN users u ON u.id = c.teacher_id
         WHERE ci.student_id = ? ORDER BY ci.added_at DESC"
    );
    $stmt->execute([$studentId]);
    return $stmt->fetchAll();
}

function getCartCount(PDO $pdo, int $studentId): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM cart_items WHERE student_id = ?');
    $stmt->execute([$studentId]);
    return (int) $stmt->fetchColumn();
}

function getCartTotal(array $cartItems): float {
    return array_sum(array_map('floatval', array_column($cartItems, 'price')));
}

// Shared navbar cart icon (with item-count badge) -- a single function so
// every page's copy-pasted navbar only needs one inserted line, instead
// of duplicating the count query and badge markup ~45 times.
function renderCartIcon(PDO $pdo, ?array $user): string {
    if (!$user || !canEnroll($user['role'] ?? null)) return '';
    $count = getCartCount($pdo, (int) $user['id']);
    ob_start();
    ?>
    <a href="cart.php" class="nav-cart-link" aria-label="Shopping cart">
        <i data-lucide="shopping-cart" class="lucide-icon"></i>
        <?php if ($count > 0): ?><span class="nav-cart-badge"><?= $count ?></span><?php endif; ?>
    </a>
    <?php
    return ob_get_clean();
}

// Returns ['ok' => bool, 'error' => ?string] -- never throws, always a
// reason a caller can flash() straight to the student.
function addToCart(PDO $pdo, int $studentId, int $courseId): array {
    $stmt = $pdo->prepare('SELECT price, is_published, moderation_status FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    if (!$course) return ['ok' => false, 'error' => 'Course not found.'];
    if ((float) $course['price'] <= 0) return ['ok' => false, 'error' => "Free courses don't need a cart — enroll directly from the course page."];
    if (!$course['is_published'] || $course['moderation_status'] !== 'approved') return ['ok' => false, 'error' => 'This course is not currently available.'];

    $already = $pdo->prepare('SELECT 1 FROM enrollments WHERE student_id = ? AND course_id = ?');
    $already->execute([$studentId, $courseId]);
    if ($already->fetch()) return ['ok' => false, 'error' => 'You are already enrolled in this course.'];

    $pdo->prepare('INSERT IGNORE INTO cart_items (student_id, course_id) VALUES (?, ?)')->execute([$studentId, $courseId]);
    return ['ok' => true, 'error' => null];
}

function removeFromCart(PDO $pdo, int $studentId, int $courseId): void {
    $pdo->prepare('DELETE FROM cart_items WHERE student_id = ? AND course_id = ?')->execute([$studentId, $courseId]);
}

function clearCartItems(PDO $pdo, int $studentId, array $courseIds): void {
    if (!$courseIds) return;
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $pdo->prepare("DELETE FROM cart_items WHERE student_id = ? AND course_id IN ($placeholders)")
        ->execute([$studentId, ...$courseIds]);
}

function paymentGatewaysConfigured(): array {
    $available = [];
    if (defined('STRIPE_PUBLISHABLE_KEY') && defined('STRIPE_SECRET_KEY') && STRIPE_PUBLISHABLE_KEY !== '' && STRIPE_SECRET_KEY !== '') {
        $available[] = 'stripe';
    }
    if (defined('PAYPAL_CLIENT_ID') && defined('PAYPAL_CLIENT_SECRET') && PAYPAL_CLIENT_ID !== '' && PAYPAL_CLIENT_SECRET !== '') {
        $available[] = 'paypal';
    }
    return $available;
}

function platformCurrency(): string {
    return defined('PLATFORM_CURRENCY') ? PLATFORM_CURRENCY : 'USD';
}

// Creates a pending order + its line items (price/teacher_id snapshotted
// now, so a later course edit or price change never rewrites a past
// receipt) and returns the new order id.
function createPendingOrder(PDO $pdo, int $studentId, array $cartItems, string $gateway): int {
    $total = getCartTotal($cartItems);
    $stmt = $pdo->prepare('INSERT INTO orders (student_id, total_amount, currency, status, payment_gateway) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$studentId, $total, platformCurrency(), 'pending', $gateway]);
    $orderId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, course_id, teacher_id, price) VALUES (?, ?, ?, ?)');
    foreach ($cartItems as $item) {
        $itemStmt->execute([$orderId, $item['id'], $item['teacher_id'], $item['price']]);
    }
    return $orderId;
}

// Marks an order paid, enrolls the student in every course on it, clears
// those items from their cart, and awards the same enrollment points a
// free-course enroll already gives (course.php) -- paying for a course
// is not treated as a "lesser" enrollment.
function fulfillOrder(PDO $pdo, int $orderId, string $paymentReference): void {
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order || $order['status'] === 'paid') return; // already fulfilled or doesn't exist -- don't double-enroll

    $pdo->prepare("UPDATE orders SET status = 'paid', payment_reference = ?, paid_at = NOW() WHERE id = ?")
        ->execute([$paymentReference, $orderId]);

    $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $items = $items->fetchAll();

    $courseIds = [];
    foreach ($items as $item) {
        $pdo->prepare('INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)')
            ->execute([$order['student_id'], $item['course_id']]);
        $courseStmt = $pdo->prepare('SELECT title FROM courses WHERE id = ?');
        $courseStmt->execute([$item['course_id']]);
        $title = $courseStmt->fetchColumn();
        awardPoints($pdo, (int) $order['student_id'], 10, 'Enrolled in "' . $title . '"');
        $courseIds[] = (int) $item['course_id'];
    }
    clearCartItems($pdo, (int) $order['student_id'], $courseIds);
    logActivity($pdo, (int) $order['student_id'], 'Completed purchase: order #' . $orderId . ' (' . count($items) . ' course(s))');
}

// ── Stripe (REST API directly via cURL -- no SDK/Composer dependency,
// consistent with how this app calls every other external API) ──────────
function stripeApiRequest(string $method, string $path, array $params = []): ?array {
    $ch = curl_init('https://api.stripe.com/v1/' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . STRIPE_SECRET_KEY],
        CURLOPT_TIMEOUT => 15,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    return $ok ? json_decode($response, true) : null;
}

// $cartItems: each needs 'title' and 'price'. Returns the Checkout
// Session's hosted URL to redirect the student to, or null on failure.
function stripeCreateCheckoutSession(array $cartItems, int $orderId, string $studentEmail): ?string {
    $params = [
        'mode' => 'payment',
        'success_url' => siteBaseUrl() . '/checkout-success.php?gateway=stripe&order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => siteBaseUrl() . '/cart.php',
        'client_reference_id' => (string) $orderId,
        'customer_email' => $studentEmail,
    ];
    foreach ($cartItems as $i => $item) {
        $params['line_items'][$i]['price_data']['currency'] = strtolower(platformCurrency());
        $params['line_items'][$i]['price_data']['product_data']['name'] = $item['title'];
        $params['line_items'][$i]['price_data']['unit_amount'] = (int) round(((float) $item['price']) * 100);
        $params['line_items'][$i]['quantity'] = 1;
    }
    $session = stripeApiRequest('POST', 'checkout/sessions', $params);
    return $session['url'] ?? null;
}

function stripeRetrieveSession(string $sessionId): ?array {
    return stripeApiRequest('GET', 'checkout/sessions/' . urlencode($sessionId));
}

// ── PayPal (REST API v2, sandbox or live per PAYPAL_MODE) ───────────────
function paypalApiBase(): string {
    return PAYPAL_MODE === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypalGetAccessToken(): ?string {
    $ch = curl_init(paypalApiBase() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => PAYPAL_CLIENT_ID . ':' . PAYPAL_CLIENT_SECRET,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    $data = $ok ? json_decode($response, true) : null;
    return $data['access_token'] ?? null;
}

function paypalApiRequest(string $method, string $path, array $body = []): ?array {
    $token = paypalGetAccessToken();
    if (!$token) return null;
    $ch = curl_init(paypalApiBase() . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT => 15,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
    curl_close($ch);
    return $ok ? json_decode($response, true) : null;
}

// Returns the PayPal-hosted approval URL to redirect the student to, or
// null on failure.
function paypalCreateOrder(array $cartItems, int $orderId): ?string {
    $total = number_format(getCartTotal($cartItems), 2, '.', '');
    $order = paypalApiRequest('POST', '/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => (string) $orderId,
            'amount' => ['currency_code' => platformCurrency(), 'value' => $total],
            'description' => count($cartItems) . ' course(s) on ' . SITE_NAME,
        ]],
        'application_context' => [
            'return_url' => siteBaseUrl() . '/checkout-success.php?gateway=paypal&order_id=' . $orderId,
            'cancel_url' => siteBaseUrl() . '/cart.php',
        ],
    ]);
    if (!$order || !isset($order['id'])) return null;
    foreach ($order['links'] ?? [] as $link) {
        if ($link['rel'] === 'approve') return $link['href'];
    }
    return null;
}

function paypalCaptureOrder(string $paypalOrderId): bool {
    $result = paypalApiRequest('POST', '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture');
    return ($result['status'] ?? '') === 'COMPLETED';
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

// Admin-triggered nudge for teachers whose course has few/no lessons —
// English, Persian, and Urdu, since teachers on this platform come from
// all three language backgrounds. Not auto-sent on a schedule; an admin
// clicks a button per course (admin.php), so it can't become spam.
function sendCourseReminderEmail(PDO $pdo, string $toEmail, string $name, string $courseTitle, int $courseId, int $adminId): bool {
    $nameSafe = e($name);
    $titleSafe = e($courseTitle);
    $link = siteBaseUrl() . '/edit-course.php?id=' . $courseId;

    $body = '<p style="margin:0 0 18px">Your course <strong>"' . $titleSafe . '"</strong> could use a bit more content before students get the most out of it:</p>'
        . '<ul style="margin:0 0 22px;padding-left:20px">'
        . '<li style="margin-bottom:6px">Add lessons with clear titles and content</li>'
        . '<li style="margin-bottom:6px">Add video links to your lessons (YouTube/Vimeo embed links)</li>'
        . '<li style="margin-bottom:6px">Consider adding a quiz to check student understanding</li>'
        . '<li>Need help? Reply to this email or message us through the platform</li>'
        . '</ul>'
        . '<p style="margin:0 0 22px"><a href="' . e(siteBaseUrl()) . '/tutorial.php">View the step-by-step tutorial (English / Persian / Urdu)</a></p>'
        . '<hr style="border:none;border-top:1px solid #e0dbd2;margin:0 0 18px">'
        . '<div dir="rtl" style="text-align:right;margin:0 0 22px">'
        . '<p style="margin:0 0 12px;font-weight:bold">' . $nameSafe . ' عزیز،</p>'
        . '<p style="margin:0 0 12px">دوره شما «' . $titleSafe . '» برای استفاده بهتر دانش‌آموزان به محتوای بیشتری نیاز دارد:</p>'
        . '<ul style="margin:0 0 12px;padding-right:20px;padding-left:0">'
        . '<li style="margin-bottom:6px">درس‌های خود را با عناوین و محتوای واضح اضافه کنید</li>'
        . '<li style="margin-bottom:6px">لینک‌های ویدیو به درس‌های خود اضافه کنید (لینک‌های یوتیوب/ویمیو)</li>'
        . '<li style="margin-bottom:6px">می‌توانید یک آزمون برای بررسی درک دانش‌آموزان اضافه کنید</li>'
        . '<li>به کمک نیاز دارید؟ به این ایمیل پاسخ دهید یا از طریق پلتفرم با ما پیام دهید</li>'
        . '</ul>'
        . '</div>'
        . '<hr style="border:none;border-top:1px solid #e0dbd2;margin:0 0 18px">'
        . '<div dir="rtl" style="text-align:right">'
        . '<p style="margin:0 0 12px;font-weight:bold">پیارے ' . $nameSafe . '،</p>'
        . '<p style="margin:0 0 12px">آپ کے کورس "' . $titleSafe . '" کو طلباء کے لیے زیادہ فائدہ مند بنانے کے لیے مزید مواد کی ضرورت ہے:</p>'
        . '<ul style="margin:0;padding-right:20px;padding-left:0">'
        . '<li style="margin-bottom:6px">اپنے اسباق کو واضح عنوانات اور مواد کے ساتھ شامل کریں</li>'
        . '<li style="margin-bottom:6px">اپنے اسباق میں ویڈیو لنکس شامل کریں (یوٹیوب/ویمیو ایمبیڈ لنکس)</li>'
        . '<li style="margin-bottom:6px">طلباء کی سمجھ جانچنے کے لیے ایک کوئز شامل کرنے پر غور کریں</li>'
        . '<li>مدد کی ضرورت ہے؟ اس ای میل کا جواب دیں یا پلیٹ فارم کے ذریعے ہمیں پیغام بھیجیں</li>'
        . '</ul>'
        . '</div>';

    $sent = sendNotificationEmail($toEmail, $name, 'Let\'s finish setting up "' . $courseTitle . '"', $body, 'Edit Your Course', $link);
    if ($sent) {
        logActivity($pdo, $adminId, 'Sent course-setup reminder email for course #' . $courseId);
    }
    return $sent;
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

// Broader than handleImageUpload() — accepts images OR PDFs (chat
// attachments/assignment submissions need PDFs too, not just photos).
// Never trusts the client-supplied MIME type; verifies via getimagesize()
// for images and the actual file signature for PDFs. Returns
// ['path' => ..., 'type' => 'image'|'file', 'name' => original filename]
// or null if nothing was uploaded / it failed validation.
function handleAttachmentUpload(string $fieldName, string $subDir): ?array {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    if ($_FILES[$fieldName]['size'] > 10 * 1024 * 1024) {
        return null; // 10MB limit
    }

    $originalName = $_FILES[$fieldName]['name'];
    $imageInfo = @getimagesize($tmpPath);
    $allowedImageTypes = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    if ($imageInfo && isset($allowedImageTypes[$imageInfo[2]])) {
        $ext = $allowedImageTypes[$imageInfo[2]];
        $type = 'image';
    } else {
        $handle = fopen($tmpPath, 'rb');
        $header = $handle ? fread($handle, 5) : '';
        if ($handle) fclose($handle);
        if ($header !== '%PDF-') {
            return null; // not an image we accept, not a real PDF
        }
        $ext = 'pdf';
        $type = 'file';
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destDir = __DIR__ . '/uploads/' . $subDir;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    if (!move_uploaded_file($tmpPath, $destDir . '/' . $filename)) {
        return null;
    }

    return ['path' => 'uploads/' . $subDir . '/' . $filename, 'type' => $type, 'name' => $originalName];
}

// Allowlist HTML sanitizer for lesson "Article" content (stored as rich
// HTML from the Quill editor, rendered unescaped on lesson.php). Strips
// everything not explicitly allowed -- tags, on* event attributes, and
// javascript:/data: URLs in href/src -- rather than trying to blocklist
// dangerous patterns, since an allowlist can't be bypassed by a payload
// we didn't think of. Disallowed tags are unwrapped (text kept), not
// dropped, except for active-content tags (script/style/iframe/object/
// embed/form), which are removed entirely along with their contents.
function sanitizeLessonHtml(string $html): string {
    $html = trim($html);
    if ($html === '') return '';

    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'ul', 'ol', 'li', 'a', 'blockquote',
        'code', 'pre', 'span', 'img', 'hr'];
    $allowedAttrs = [
        'a'   => ['href'],
        'img' => ['src', 'alt'],
    ];
    $removeEntirely = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'link', 'meta'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="sanitize-root">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();

    $root = $doc->getElementById('sanitize-root');
    if (!$root) return '';

    $isSafeUrl = function (string $url): bool {
        $url = trim($url);
        if ($url === '' || $url[0] === '/' || $url[0] === '#') return true;
        return (bool) preg_match('/^(https?|mailto):/i', $url);
    };

    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs, $removeEntirely, $isSafeUrl, $doc) {
        $children = [];
        foreach ($node->childNodes as $child) $children[] = $child;

        foreach ($children as $child) {
            if (!($child instanceof DOMElement)) continue; // text/comment nodes are inert, leave as-is
            $tag = strtolower($child->tagName);

            if (in_array($tag, $removeEntirely, true)) {
                $node->removeChild($child);
                continue;
            }

            $walk($child); // recurse before unwrapping so nested disallowed tags are also cleaned

            if (!in_array($tag, $allowedTags, true)) {
                while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes ?? []) as $attr) {
                $name = strtolower($attr->name);
                $keep = in_array($name, $allowedAttrs[$tag] ?? [], true);
                if ($keep && in_array($name, ['href', 'src'], true) && !$isSafeUrl($attr->value)) {
                    $keep = false;
                }
                if (!$keep) $child->removeAttribute($attr->name);
            }
            if ($tag === 'a') $child->setAttribute('rel', 'noopener noreferrer');
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/**
 * Parses an uploaded CSV into a lowercase header row + associative data rows.
 * Reads via fgetcsv() on a memory stream (not a manual newline split) so that
 * quoted multi-line fields -- e.g. a lesson's paragraph-long "content" column
 * -- survive intact instead of being torn apart at an embedded newline.
 * Tolerates a UTF-8 BOM and blank trailing lines, both common artifacts of
 * Excel exports and AI-generated CSV text.
 */
function parseCsvUploadFile(string $fieldName): ?array {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'Upload failed. Please try again.'];
    }
    $raw = file_get_contents($_FILES[$fieldName]['tmp_name']);
    if ($raw === false || trim($raw) === '') {
        return ['error' => 'The file is empty.'];
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $header = fgetcsv($stream);
    if (!$header) {
        fclose($stream);
        return ['error' => 'No header row found in the file.'];
    }
    $header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

    $rows = [];
    while (($line = fgetcsv($stream)) !== false) {
        if (count($line) === 1 && trim((string) ($line[0] ?? '')) === '') continue;
        $assoc = [];
        foreach ($header as $i => $key) {
            $assoc[$key] = trim((string) ($line[$i] ?? ''));
        }
        $rows[] = $assoc;
    }
    fclose($stream);

    return ['header' => $header, 'rows' => $rows, 'raw' => $raw];
}

/** Re-serializes CSV rows with an extra import_errors column, for "download and hand to an AI to fix" reports. */
function buildAnnotatedCsv(array $header, array $rows, array $rowErrors): string {
    $out = fopen('php://temp', 'r+');
    fputcsv($out, array_merge($header, ['import_errors']));
    foreach ($rows as $i => $row) {
        $line = [];
        foreach ($header as $key) $line[] = $row[$key] ?? '';
        $line[] = $rowErrors[$i] ?? '' ? implode('; ', $rowErrors[$i]) : '';
        fputcsv($out, $line);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv;
}

// ── AI-Assisted Course Authoring ────────────────────────────────────────
// Prompt wording lives in the ai_prompt_templates table (editable by an
// admin from admin.php) rather than hardcoded in each page, so tuning the
// AI instructions never requires a code deploy. This array is the
// fallback/seed source of truth -- used if a template row is ever missing
// (fresh install before the seed script runs) and by admin's "Reset to
// Default" button, so both paths can never drift from each other.
function aiPromptDefaults(): array {
    return [
        'course_creation' => [
            'label' => 'Single Course Upload (no specific course yet)',
            'placeholders_help' => '{{site_name}} -- platform name. {{subject_list}} -- comma-separated list of every valid subject name, generated live from the subjects table.',
            'template_text' => <<<'TXT'
I'm creating a course for an online learning platform called {{site_name}}. Generate a CSV file I can upload directly.

My course topic: [DESCRIBE YOUR COURSE HERE — e.g. "A beginner course on Tajweed rules for Quran recitation"]

Output ONLY raw CSV text (no explanation, no markdown code fences) with EXACTLY these column headers, in this order, and EXACTLY one data row below the header:
title,description,subject,level,language,price,learning_objectives,requirements,textbook

Rules for each column:
- title: 5-80 characters, clear and specific
- description: at least 20 characters (aim for 100-300), explain what students will learn
- subject: pick the closest match from this exact list, copied exactly: {{subject_list}}
- level: must be exactly one of: beginner, intermediate, advanced
- language: the language the course is taught in (e.g. English, Urdu, Persian, Arabic)
- price: a plain number, use 0 for a free course
- learning_objectives: 3-5 short points separated by semicolons
- requirements: prerequisites, or "None" if there aren't any
- textbook: a real reference textbook/material this course is based on, or leave blank if none
TXT,
        ],
        'course_lessons' => [
            'label' => 'Lesson Plan Generation (for one already-created course)',
            'placeholders_help' => '{{site_name}} -- platform name. {{course_title}} -- the course\'s title. {{course_description}} -- the course\'s description. {{textbook}} -- the optional reference textbook the teacher entered, or "None specified" if left blank.',
            'template_text' => <<<'TXT'
I'm building the full lesson plan for a course on an online learning platform called {{site_name}}.

Course title: {{course_title}}
Course description: {{course_description}}
Reference textbook / material: {{textbook}}
{{schedule_note}}
Please act as an experienced curriculum designer and write a complete, well-structured set of lessons for this course. You must produce an actual, ready-to-download CSV file — not a description of one, not a sample, not a few example rows with the rest implied. Output ONLY raw CSV text (no explanation, no markdown code fences, no extra commentary) with EXACTLY these column headers, in this order:
section_title,title,content,video_url,duration_minutes

Rules for each column:
- section_title: group lessons logically into sections, e.g. "Week 1", "Week 2", or named modules that fit the subject. Use the exact same text on every lesson row within the same section.
- title: a short, specific lesson title (3-80 characters)
- content: REAL educational content the student will actually read and learn from — never a placeholder, summary, outline, or text like "[lesson content here]". Write 150-400 words of substantive, accurate teaching material for EVERY single row: explain concepts clearly, define key terms, and include a short example where useful. If a reference textbook was given above, align the content and terminology with it. Do not shorten or skip this for later rows even if the course has many lessons — every row gets the full treatment.
- video_url: leave this blank — do not invent a fake video link
- duration_minutes: a realistic whole number (typically 10-30)

Plan a complete course: decide how many lessons and sections fit the topic and description above (use your judgment — a typical course has 8-20 lessons unless a schedule is specified above), and order them so each lesson builds on the previous one.

Generate one full data row per lesson, in teaching order, with real content in every row — this is the actual file I will upload, not a draft.
TXT,
        ],
        'quiz_questions' => [
            'label' => 'Quiz Question Generation (based on a course\'s actual lessons)',
            'placeholders_help' => '{{site_name}} -- platform name. {{course_title}} -- the course\'s title. {{lesson_summary}} -- auto-generated numbered list of the course\'s lesson titles plus short content snippets.',
            'template_text' => <<<'TXT'
I'm creating a quiz for a course on {{site_name}} called "{{course_title}}".

Here are the lessons this quiz should test (in order):
{{lesson_summary}}

Please act as an experienced instructor and write quiz questions that genuinely test understanding of the material above — base each question on actual content from the lessons listed, not generic trivia. Output ONLY raw CSV text (no explanation, no markdown code fences) with EXACTLY these column headers, in this order:
quiz_title,passing_score,question,option_1,option_2,option_3,option_4,correct_option

Rules:
- quiz_title: the exact same text on every row (this groups questions into one quiz) — name it after the section/topic it covers
- passing_score: the same number on every row (a reasonable value, e.g. 70)
- question: a clear question that tests a real concept from the lessons above
- option_1, option_2: required answer choices; option_3, option_4 optional (2-4 total per question). Make incorrect options plausible, not obviously wrong.
- correct_option: which option is correct — just the number 1, 2, 3, or 4

Write one question per important concept covered in the lessons above (a typical quiz has 5-10 questions).
TXT,
        ],
        'assignments' => [
            'label' => 'Assignment Generation (based on a course\'s actual lessons)',
            'placeholders_help' => '{{site_name}} -- platform name. {{course_title}} -- the course\'s title. {{lesson_summary}} -- auto-generated numbered list of the course\'s lesson titles plus short content snippets.',
            'template_text' => <<<'TXT'
I'm creating assignment(s) for a course on {{site_name}} called "{{course_title}}".

Here are the lessons this assignment should be based on (in order):
{{lesson_summary}}

Please act as an experienced instructor and design a practical assignment that has students apply what's covered in the lessons above — not a generic task unrelated to the material. Output ONLY raw CSV text (no explanation, no markdown code fences) with EXACTLY these column headers, in this order:
title,description,due_date

Rules:
- title: short, specific (3-80 characters)
- description: clear, complete instructions for what the student must do and submit, referencing the actual concepts/skills from the lessons above
- due_date: a date in YYYY-MM-DD format, or leave blank for no deadline

Generate one row per assignment.
TXT,
        ],
    ];
}

function getAiPromptTemplate(PDO $pdo, string $key): array {
    $stmt = $pdo->prepare('SELECT * FROM ai_prompt_templates WHERE template_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row) return $row;

    $defaults = aiPromptDefaults();
    $d = $defaults[$key] ?? ['label' => $key, 'placeholders_help' => '', 'template_text' => ''];
    return ['template_key' => $key, 'label' => $d['label'], 'template_text' => $d['template_text'], 'placeholders_help' => $d['placeholders_help']];
}

// Builds the optional {{schedule_note}} line for the course_lessons prompt
// from the "pacing" mini-form's ?days=&minutes_per_day= query params, so
// the AI sizes the lesson plan to an actual course length instead of
// guessing. Empty string (no extra line) if either value is missing/zero.
function lessonScheduleNote(): string {
    $days = (int) ($_GET['days'] ?? 0);
    $minutesPerDay = (int) ($_GET['minutes_per_day'] ?? 0);
    if ($days <= 0 || $minutesPerDay <= 0) return '';
    return "This course should be structured as $days day(s) of lessons, with approximately $minutesPerDay minutes of lesson content per day (combine multiple short lessons per day if needed to reach that target). Use section_title values like \"Day 1\", \"Day 2\", etc. to group each day's lessons.\n";
}

function renderAiPrompt(PDO $pdo, string $key, array $vars): string {
    $tpl = getAiPromptTemplate($pdo, $key);
    $text = $tpl['template_text'];
    foreach ($vars as $k => $v) {
        $text = str_replace('{{' . $k . '}}', (string) $v, $text);
    }
    return $text;
}

// Compiles a course's lessons into a numbered list (title + short content
// snippet) so the quiz/assignment AI prompts are grounded in what was
// actually taught, not just the course's top-level title/description.
function buildLessonSummaryForPrompt(PDO $pdo, int $courseId): string {
    $stmt = $pdo->prepare('SELECT section_title, title, content FROM lessons WHERE course_id = ? ORDER BY sort_order ASC');
    $stmt->execute([$courseId]);
    $lessons = $stmt->fetchAll();

    $lines = [];
    foreach ($lessons as $i => $l) {
        $section = $l['section_title'] ? '[' . $l['section_title'] . '] ' : '';
        $content = trim($l['content']);
        $snippet = mb_substr($content, 0, 220);
        if (mb_strlen($content) > 220) $snippet .= '...';
        $lines[] = ($i + 1) . '. ' . $section . $l['title'] . ' — ' . $snippet;
    }
    return implode("\n", $lines);
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

// Udemy-style category nav: a row of fields-of-study, each revealing its
// own subjects in a second bar on hover (app.js handles the show/hide).
// All fields' subject panels are rendered up front (hidden until hovered)
// so the hover swap is instant with no extra request. $activeFieldId/
// $activeSubjectId just drive which links render with the "active" class
// (e.g. when arriving via a direct ?field= link rather than hovering).
// The subcategory hover panel only has room for a handful of items before
// it turns into a horizontally-scrolling row a quick hover will never
// think to scroll (Islamic Studies alone has 13 subjects) -- so each
// field shows only its top-ranked subjects here, by real enrollment
// count, with a "More in [field]" link as the escape hatch to the full
// list on courses.php. Whichever subject the user currently has selected
// is always kept visible even if it didn't rank, so the active state is
// never silently dropped.
//
// Returns ['desktop' => ..., 'mobile' => ...] rather than one string: the
// desktop version is a hover strip rendered as its own full-width section
// right after </nav>, but on mobile it needs to live INSIDE the hamburger
// drawer (.nav-links) instead of as a second, always-visible bar -- two
// different DOM locations, so the caller echoes each piece where it
// belongs. The mobile version uses native <details>/<summary> for the
// expand/collapse so no JS is needed for the accordion itself.
function renderCategoryNav(PDO $pdo, int $activeFieldId = 0, int $activeSubjectId = 0, int $topN = 3): array {
    $fields = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();
    $allSubjects = $pdo->query(
        "SELECT s.*, (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.subject_id = s.id) AS enrollment_count
         FROM subjects s ORDER BY enrollment_count DESC, s.name ASC"
    )->fetchAll();
    $byField = [];
    foreach ($allSubjects as $s) {
        $byField[(int) $s['field_of_study_id']][] = $s;
    }

    $topByField = [];
    foreach ($byField as $fid => $subjects) {
        $top = array_slice($subjects, 0, $topN);
        if ($activeSubjectId && !in_array($activeSubjectId, array_column($top, 'id'), true)) {
            foreach ($subjects as $s) {
                if ((int) $s['id'] === $activeSubjectId) { $top[] = $s; break; }
            }
        }
        $topByField[$fid] = ['shown' => $top, 'total' => count($subjects)];
    }

    ob_start();
    ?>
    <div class="category-nav-group" id="categoryNavGroup">
        <nav class="category-nav">
            <a href="courses.php" class="<?= ($activeFieldId === 0 && $activeSubjectId === 0) ? 'active' : '' ?>"><i data-lucide="library" class="lucide-icon"></i> All Fields</a>
            <?php foreach ($fields as $f): $fid = (int) $f['id']; ?>
                <a href="courses.php?field=<?= $fid ?>" data-field-id="<?= $fid ?>" class="<?= $activeFieldId === $fid ? 'active' : '' ?>"><?= catIcon($f['icon']) ?> <?= e($f['name']) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="subcategory-nav-panel">
            <?php foreach ($fields as $f): $fid = (int) $f['id']; if (empty($topByField[$fid]['shown'])) continue; ?>
            <nav class="subcategory-nav<?= $activeFieldId === $fid ? ' active-panel' : '' ?>" data-for-field="<?= $fid ?>">
                <?php foreach ($topByField[$fid]['shown'] as $s): ?>
                    <a href="courses.php?field=<?= $fid ?>&subject=<?= (int) $s['id'] ?>" class="<?= $activeSubjectId === (int) $s['id'] ? 'active' : '' ?>"><?= catIcon($s['icon']) ?> <?= e($s['name']) ?></a>
                <?php endforeach; ?>
                <?php if ($topByField[$fid]['total'] > count($topByField[$fid]['shown'])): ?>
                    <a href="courses.php?field=<?= $fid ?>" style="opacity:.7"><i data-lucide="ellipsis" class="lucide-icon"></i> More in <?= e($f['name']) ?></a>
                <?php endif; ?>
            </nav>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    $desktop = ob_get_clean();

    ob_start();
    ?>
    <div class="category-nav-mobile">
        <div class="nav-links-label">Browse by Category</div>
        <a href="courses.php" class="<?= ($activeFieldId === 0 && $activeSubjectId === 0) ? 'active' : '' ?>"><i data-lucide="library" class="lucide-icon"></i> All Fields</a>
        <?php foreach ($fields as $f): $fid = (int) $f['id']; if (empty($topByField[$fid]['shown'])) continue; ?>
        <details<?= $activeFieldId === $fid ? ' open' : '' ?>>
            <summary><?= catIcon($f['icon']) ?> <?= e($f['name']) ?></summary>
            <?php foreach ($topByField[$fid]['shown'] as $s): ?>
                <a href="courses.php?field=<?= $fid ?>&subject=<?= (int) $s['id'] ?>" class="<?= $activeSubjectId === (int) $s['id'] ? 'active' : '' ?>"><?= catIcon($s['icon']) ?> <?= e($s['name']) ?></a>
            <?php endforeach; ?>
            <?php if ($topByField[$fid]['total'] > count($topByField[$fid]['shown'])): ?>
                <a href="courses.php?field=<?= $fid ?>" style="opacity:.7"><i data-lucide="ellipsis" class="lucide-icon"></i> More in <?= e($f['name']) ?></a>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>
    </div>
    <?php
    $mobile = ob_get_clean();

    return ['desktop' => $desktop, 'mobile' => $mobile];
}

// Shared rich footer — fields of study, popular subjects, and the real
// account/learn/company links that actually exist (no Privacy/Terms links
// since those pages don't exist yet, rather than linking to a 404).
function renderFooter(PDO $pdo): string {
    $fields = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();
    $popularSubjects = $pdo->query(
        "SELECT s.*, (SELECT COUNT(*) FROM enrollments e JOIN courses c ON c.id = e.course_id WHERE c.subject_id = s.id) AS enrollment_count
         FROM subjects s ORDER BY enrollment_count DESC, s.name ASC LIMIT 8"
    )->fetchAll();

    ob_start();
    ?>
    <footer>
        <div class="footer-grid">
            <div>
                <img src="assets/seal-curved-gold.svg" alt="<?= e(SITE_NAME) ?>" class="footer-seal">
                <div class="footer-brand"><?= e(SITE_NAME) ?></div>
                <p>Seek Knowledge — From the Cradle to the Grave.</p>
            </div>
            <div>
                <div class="footer-heading">Browse by Field</div>
                <ul class="footer-links">
                    <?php foreach ($fields as $f): ?>
                        <li><a href="courses.php?field=<?= (int) $f['id'] ?>"><?= e($f['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <div class="footer-heading">Popular Subjects</div>
                <ul class="footer-links">
                    <?php foreach ($popularSubjects as $s): ?>
                        <li><a href="courses.php?subject=<?= (int) $s['id'] ?>"><?= e($s['name']) ?></a></li>
                    <?php endforeach; ?>
                    <li><a href="courses.php"><strong>View All Courses →</strong></a></li>
                </ul>
            </div>
            <div>
                <div class="footer-heading">Learn</div>
                <ul class="footer-links">
                    <li><a href="getting-started.php">How It Works</a></li>
                    <li><a href="courses.php">All Courses</a></li>
                    <li><a href="register.php">Join Free</a></li>
                    <li><a href="register.php">Become a Teacher</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-heading">Account</div>
                <ul class="footer-links">
                    <li><a href="login.php">Login</a></li>
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="edit-profile.php">Edit Profile</a></li>
                    <li><a href="activity-log.php">Account Activity</a></li>
                </ul>
            </div>
            <div>
                <div class="footer-heading">Company</div>
                <ul class="footer-links">
                    <li><a href="about.php">About Us</a></li>
                    <li><a href="feedback.php">Send Feedback</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom">&copy; <?= date('Y') ?> <?= e(SITE_NAME) ?>. Built with <i data-lucide="heart" class="lucide-icon"></i> for the Ummah.</div>
    </footer>
    <?php
    return ob_get_clean();
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
