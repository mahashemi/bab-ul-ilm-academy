<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$withId = (int) ($_GET['with'] ?? 0);
$courseId = (int) ($_GET['course'] ?? 0) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['body'])) {
    verifyCsrf();
    $body = trim($_POST['body']);
    if ($body !== '' && $withId > 0) {
        $pdo->prepare('INSERT INTO messages (sender_id, receiver_id, course_id, body) VALUES (?, ?, ?, ?)')
            ->execute([$user['id'], $withId, $courseId, $body]);
    }
    redirect('chat.php?with=' . $withId . ($courseId ? '&course=' . $courseId : ''));
}

// List of conversations, with unread count per conversation
$convos = $pdo->prepare(
    "SELECT u.id, u.name,
            (SELECT body FROM messages m2 WHERE (m2.sender_id=u.id AND m2.receiver_id=?) OR (m2.sender_id=? AND m2.receiver_id=u.id) ORDER BY m2.created_at DESC LIMIT 1) AS last_msg,
            (SELECT created_at FROM messages m3 WHERE (m3.sender_id=u.id AND m3.receiver_id=?) OR (m3.sender_id=? AND m3.receiver_id=u.id) ORDER BY m3.created_at DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM messages m4 WHERE m4.sender_id=u.id AND m4.receiver_id=? AND m4.is_read=0) AS unread_count
     FROM users u
     WHERE u.id IN (
        SELECT sender_id FROM messages WHERE receiver_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
     )
     ORDER BY last_at DESC"
);
$convos->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$conversations = $convos->fetchAll();

$activeMessages = [];
$activeUser = null;
if ($withId > 0) {
    $stmt = $pdo->prepare('SELECT id, name FROM users WHERE id = ?');
    $stmt->execute([$withId]);
    $activeUser = $stmt->fetch();

    $stmt = $pdo->prepare(
        'SELECT * FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?) ORDER BY created_at ASC'
    );
    $stmt->execute([$user['id'], $withId, $withId, $user['id']]);
    $activeMessages = $stmt->fetchAll();

    $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?')->execute([$withId, $user['id']]);
}

// Group consecutive messages from the same sender so avatars/timestamps don't repeat every line
$groups = [];
foreach ($activeMessages as $m) {
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
<title>Messages — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><i data-lucide="landmark" class="lucide-icon"></i> <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <?php if ($user): ?>
            <a href="chat.php">Messages</a>
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
            <div class="nav-account">
                <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="Account menu">
                    <span class="nav-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
                    <i data-lucide="chevron-down" class="lucide-icon"></i>
                </button>
                <div class="nav-account-menu">
                    <div class="nav-account-header">
                        <span class="nav-avatar"><?= e(mb_substr($user['name'], 0, 1)) ?></span>
                        <div>
                            <div class="nav-account-name"><?= e($user['name']) ?></div>
                            <div class="nav-account-email"><?= e($user['email']) ?></div>
                        </div>
                    </div>
                    <div class="nav-menu-divider"></div>
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> Dashboard</a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                    <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                    <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php"><i data-lucide="shield-check" class="lucide-icon"></i> Admin Panel</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="logout.php"><i data-lucide="log-out" class="lucide-icon"></i> Logout</a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="nav-btn">Login</a>
        <?php endif; ?>
    </div>
</nav>

<div class="chat-page">
    <div class="chat-wrap <?= $withId ? 'thread-open' : '' ?>">
        <div class="chat-list">
            <div class="chat-list-header">Messages</div>
            <?php if (!$conversations): ?>
                <div class="chat-empty-list"><i data-lucide="message-circle" class="lucide-icon"></i><br>No conversations yet.<br>Teachers can message students from their course's student list; students can message a teacher from the course page.</div>
            <?php endif; ?>
            <?php foreach ($conversations as $c): ?>
                <a href="chat.php?with=<?= (int) $c['id'] ?>" class="chat-list-item <?= $c['id'] == $withId ? 'active' : '' ?>">
                    <div class="chat-avatar"><?= e(mb_substr($c['name'], 0, 1)) ?></div>
                    <div style="overflow:hidden;flex:1;min-width:0">
                        <div class="chat-preview-top">
                            <span class="chat-preview-name"><?= e($c['name']) ?></span>
                            <span class="chat-preview-time"><?= $c['last_at'] ? chatTime($c['last_at']) : '' ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                            <span class="chat-preview-msg"><?= e($c['last_msg'] ?? '') ?></span>
                            <?php if ((int) $c['unread_count'] > 0): ?><span class="chat-unread-dot"></span><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="chat-main">
            <?php if (!$activeUser): ?>
                <div class="chat-empty-thread">
                    <div><div class="icon"><i data-lucide="message-circle" class="lucide-icon"></i></div>Select a conversation to start chatting</div>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <button class="chat-back" onclick="location.href='chat.php'" aria-label="Back to conversations"><i data-lucide="arrow-left" class="lucide-icon"></i></button>
                    <div class="chat-avatar"><?= e(mb_substr($activeUser['name'], 0, 1)) ?></div>
                    <div>
                        <div class="chat-header-name"><?= e($activeUser['name']) ?></div>
                    </div>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <?php foreach ($groups as $g): $isSent = $g['sender_id'] == $user['id']; ?>
                    <div class="msg-group <?= $isSent ? 'sent' : '' ?>">
                        <div class="msg-group-avatar"><?= e(mb_substr($isSent ? $user['name'] : $activeUser['name'], 0, 1)) ?></div>
                        <div class="msg-bubbles">
                            <?php foreach ($g['messages'] as $m): ?>
                                <div class="msg <?= $isSent ? 'msg-sent' : 'msg-recv' ?>"><?= e($m['body']) ?></div>
                            <?php endforeach; ?>
                            <div class="msg-time"><?= chatTime(end($g['messages'])['created_at']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="chat-input-bar" id="chatForm">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <textarea name="body" id="chatBody" class="chat-input" rows="1" placeholder="Type a message..." required autocomplete="off"></textarea>
                    <button type="submit" class="chat-send-btn" aria-label="Send"><i data-lucide="send" class="lucide-icon"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var box = document.getElementById('chatMessages');
    if (box) box.scrollTop = box.scrollHeight;

    var textarea = document.getElementById('chatBody');
    var form = document.getElementById('chatForm');
    if (textarea && form) {
        textarea.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 110) + 'px';
        });
        textarea.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (textarea.value.trim() !== '') form.submit();
            }
        });
    }
})();
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
