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

// List of conversations
$convos = $pdo->prepare(
    "SELECT u.id, u.name,
            (SELECT body FROM messages m2 WHERE (m2.sender_id=u.id AND m2.receiver_id=?) OR (m2.sender_id=? AND m2.receiver_id=u.id) ORDER BY m2.created_at DESC LIMIT 1) AS last_msg,
            (SELECT created_at FROM messages m3 WHERE (m3.sender_id=u.id AND m3.receiver_id=?) OR (m3.sender_id=? AND m3.receiver_id=u.id) ORDER BY m3.created_at DESC LIMIT 1) AS last_at
     FROM users u
     WHERE u.id IN (
        SELECT sender_id FROM messages WHERE receiver_id = ?
        UNION
        SELECT receiver_id FROM messages WHERE sender_id = ?
     )
     ORDER BY last_at DESC"
);
$convos->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
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
    <a class="nav-brand" href="index.php">🕌 <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">☰</button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <span class="nav-user">👤 <?= e($user['name']) ?></span>
        <a href="dashboard.php">Dashboard</a>
        <a href="chat.php">Messages</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php">Admin</a><?php endif; ?>
        <a href="logout.php" class="nav-btn">Logout</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
    </div>
</nav>

<div class="chat-wrap">
    <div class="chat-list">
        <?php if (!$conversations): ?>
            <div style="padding:1.5rem;text-align:center;color:var(--text-light);font-size:.88rem">No conversations yet. Teachers can message students from their course's student list; students can message a teacher from the course page.</div>
        <?php endif; ?>
        <?php foreach ($conversations as $c): ?>
            <a href="chat.php?with=<?= (int) $c['id'] ?>" class="chat-list-item <?= $c['id'] == $withId ? 'active' : '' ?>">
                <div class="student-avatar"><?= e(mb_substr($c['name'], 0, 1)) ?></div>
                <div class="chat-preview" style="overflow:hidden">
                    <div style="font-weight:600;font-size:.88rem"><?= e($c['name']) ?></div>
                    <div style="font-size:.78rem;color:var(--text-light);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($c['last_msg'] ?? '') ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="chat-main">
        <?php if (!$activeUser): ?>
            <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--text-light)">
                <div style="text-align:center"><div style="font-size:3rem">💬</div>Select a conversation to start chatting</div>
            </div>
        <?php else: ?>
            <div style="padding:1rem 1.5rem;background:var(--white);border-bottom:1px solid var(--border);font-weight:700">
                <?= e($activeUser['name']) ?>
            </div>
            <div class="chat-messages">
                <?php foreach ($activeMessages as $m): ?>
                    <div class="msg <?= $m['sender_id'] == $user['id'] ? 'msg-sent' : 'msg-recv' ?>">
                        <?= e($m['body']) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" class="chat-input-bar">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <input type="text" name="body" class="chat-input" placeholder="Type a message..." required autocomplete="off">
                <button type="submit" class="btn btn-primary">Send</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="app.js" defer></script>
</body>
</html>
