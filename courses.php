<?php
require_once __DIR__ . '/db.php';
$user = auth();

$subjectId = (int) ($_GET['subject'] ?? 0);
$q = trim($_GET['q'] ?? '');
$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();

$sql = "SELECT c.*, u.name AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
               (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
        FROM courses c
        JOIN users u ON u.id = c.teacher_id
        LEFT JOIN subjects s ON s.id = c.subject_id
        WHERE c.is_published = 1 AND c.moderation_status = 'approved'";
$params = [];
if ($subjectId > 0) { $sql .= ' AND c.subject_id = ?'; $params[] = $subjectId; }
if ($q !== '') { $sql .= ' AND (c.title LIKE ? OR c.description LIKE ? OR u.name LIKE ? OR s.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$sql .= ' ORDER BY c.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Courses — <?= e(SITE_NAME) ?></title>
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

<div class="container section">
    <h2 class="section-title">All <span>Courses</span></h2>

    <form method="get" style="display:flex;gap:.6rem;margin-bottom:1.5rem;max-width:500px">
        <input type="text" name="q" class="form-control" placeholder="Search by course, teacher, or subject..." value="<?= e($q) ?>">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="chip-row">
        <a href="courses.php" class="cat-chip <?= $subjectId === 0 ? 'active' : '' ?>"><i data-lucide="library" class="lucide-icon"></i> All Subjects</a>
        <?php foreach ($subjects as $s): ?>
            <a href="?subject=<?= (int) $s['id'] ?>" class="cat-chip <?= $subjectId === (int) $s['id'] ? 'active' : '' ?>"><?= catIcon($s['icon']) ?> <?= e($s['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <p class="section-sub"><?= count($courses) ?> course(s) found</p>

    <?php if (!$courses): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="library" class="lucide-icon"></i></div><h3>No courses found</h3></div>
    <?php else: ?>
    <div class="grid-3">
        <?php foreach ($courses as $c): ?>
        <a href="course.php?id=<?= (int) $c['id'] ?>" class="course-card" style="text-decoration:none;color:inherit">
            <div class="course-cover">
                <?php if ($c['cover_url']): ?><img src="<?= e($c['cover_url']) ?>" alt=""><?php else: ?><?= catIcon($c['subject_icon']) ?><?php endif; ?>
                <span class="badge badge-<?= e($c['level']) ?> course-level"><?= e(ucfirst($c['level'])) ?></span>
            </div>
            <div class="course-body">
                <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                <div class="course-title"><?= e($c['title']) ?></div>
                <div class="course-desc"><?= e($c['description']) ?></div>
                <div class="course-meta">
                    <span><i data-lucide="user" class="lucide-icon"></i> <?= e($c['teacher_name']) ?></span>
                    <span><i data-lucide="graduation-cap" class="lucide-icon"></i> <?= (int) $c['student_count'] ?> enrolled</span>
                </div>
            </div>
            <div class="course-footer">
                <span class="course-price <?= $c['price'] == 0 ? 'free' : '' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
                <span class="btn btn-outline btn-sm">View <i data-lucide="arrow-right" class="lucide-icon"></i></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
