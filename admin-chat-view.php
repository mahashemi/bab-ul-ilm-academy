<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Access denied. Admins only. <a href="index.php">Go back</a></p>');
}

$u1 = (int) ($_GET['u1'] ?? 0);
$u2 = (int) ($_GET['u2'] ?? 0);

$stmt = $pdo->prepare('SELECT id, name FROM users WHERE id IN (?, ?)');
$stmt->execute([$u1, $u2]);
$people = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

if (count($people) !== 2) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Conversation not found. <a href="admin.php?tab=messages">Go back</a></p>');
}

$stmt = $pdo->prepare(
    'SELECT * FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC'
);
$stmt->execute([$u1, $u2, $u2, $u1]);
$messages = $stmt->fetchAll();

$groups = [];
foreach ($messages as $m) {
    $last = end($groups);
    if ($last !== false && $last['sender_id'] == $m['sender_id'] && (strtotime($m['created_at']) - strtotime(end($last['messages'])['created_at'])) < 300) {
        $groups[key($groups)]['messages'][] = $m;
    } else {
        $groups[] = ['sender_id' => $m['sender_id'], 'messages' => [$m]];
    }
}

function chatTime(string $dt): string {
    $ts = strtotime($dt);
    $today = date('Y-m-d', $ts) === date('Y-m-d');
    return $today ? date('g:i A', $ts) : date('M j, g:i A', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Conversation Oversight — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Site</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <div class="nav-account">
            <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="Account menu">
                <?= renderAvatar($user) ?>
                <i data-lucide="chevron-down" class="lucide-icon"></i>
            </button>
            <div class="nav-account-menu">
                <div class="nav-account-header">
                    <?= renderAvatar($user) ?>
                    <div>
                        <div class="nav-account-name"><?= e(displayNameOf($user)) ?></div>
                        <div class="nav-account-email"><?= e($user['email']) ?></div>
                    </div>
                </div>
                <div class="nav-menu-divider"></div>
                <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
                <div class="nav-menu-divider"></div>
                <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
            </div>
        </div>
    </div>
</nav>

<div class="chat-page">
    <div class="chat-wrap thread-open">
        <div class="chat-main" style="display:flex">
            <div class="chat-header">
                <button class="chat-back" onclick="location.href='admin.php?tab=messages'" aria-label="Back to all chats" style="display:inline-flex"><i data-lucide="arrow-left" class="lucide-icon"></i></button>
                <div class="chat-avatar"><i data-lucide="eye" class="lucide-icon"></i></div>
                <div>
                    <div class="chat-header-name"><?= e($people[$u1]) ?> <i data-lucide="arrow-left-right" class="lucide-icon"></i> <?= e($people[$u2]) ?></div>
                    <div class="chat-header-sub">Read-only admin oversight — <?= count($messages) ?> message(s)</div>
                </div>
            </div>
            <div class="chat-messages" id="chatMessages">
                <?php foreach ($groups as $g): $isU1 = $g['sender_id'] == $u1; ?>
                <div class="msg-group <?= $isU1 ? '' : 'sent' ?>">
                    <div class="msg-group-avatar"><?= e(mb_substr($people[$g['sender_id']], 0, 1)) ?></div>
                    <div class="msg-bubbles">
                        <div style="font-size:.7rem;color:var(--text-light);padding:0 .3rem"><?= e($people[$g['sender_id']]) ?></div>
                        <?php foreach ($g['messages'] as $m): ?>
                            <div class="msg <?= $isU1 ? 'msg-recv' : 'msg-sent' ?>"><?= e($m['body']) ?></div>
                        <?php endforeach; ?>
                        <div class="msg-time"><?= chatTime(end($g['messages'])['created_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="padding:.9rem 1.1rem;background:var(--white);border-top:1px solid var(--border);text-align:center;font-size:.8rem;color:var(--text-light)">
                <i data-lucide="eye" class="lucide-icon"></i> Admin oversight mode — this conversation cannot be replied to from here
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var box = document.getElementById('chatMessages');
    if (box) box.scrollTop = box.scrollHeight;
})();
</script>
<?= renderFooter($pdo) ?>
</body>
</html>
