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
        WHERE c.is_published = 1";
$params = [];
if ($subjectId > 0) { $sql .= ' AND c.subject_id = ?'; $params[] = $subjectId; }
if ($q !== '') { $sql .= ' AND (c.title LIKE ? OR c.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
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
    <div class="nav-brand">🕌 <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <?php if ($user): ?><a href="dashboard.php">Dashboard</a><a href="logout.php" class="nav-btn">Logout</a>
        <?php else: ?><a href="login.php" class="nav-btn">Login</a><?php endif; ?>
    </div>
</nav>

<div class="container section">
    <h2 class="section-title">All <span>Courses</span></h2>

    <form method="get" style="display:flex;gap:.6rem;margin-bottom:1.5rem;max-width:500px">
        <input type="text" name="q" class="form-control" placeholder="Search courses..." value="<?= e($q) ?>">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <div class="category-grid" style="display:flex;flex-wrap:wrap;gap:.7rem;margin-bottom:2rem">
        <a href="courses.php" class="cat-chip <?= $subjectId === 0 ? 'active' : '' ?>">📚 All Subjects</a>
        <?php foreach ($subjects as $s): ?>
            <a href="?subject=<?= (int) $s['id'] ?>" class="cat-chip <?= $subjectId === (int) $s['id'] ? 'active' : '' ?>"><?= e($s['icon']) ?> <?= e($s['name']) ?></a>
        <?php endforeach; ?>
    </div>

    <p class="section-sub"><?= count($courses) ?> course(s) found</p>

    <?php if (!$courses): ?>
        <div class="empty-state"><div class="icon">📚</div><h3>No courses found</h3></div>
    <?php else: ?>
    <div class="grid-3">
        <?php foreach ($courses as $c): ?>
        <a href="course.php?id=<?= (int) $c['id'] ?>" class="course-card" style="text-decoration:none;color:inherit">
            <div class="course-cover">
                <?php if ($c['cover_url']): ?><img src="<?= e($c['cover_url']) ?>" alt=""><?php else: ?><?= e($c['subject_icon'] ?: '📖') ?><?php endif; ?>
                <span class="badge badge-<?= e($c['level']) ?> course-level"><?= e(ucfirst($c['level'])) ?></span>
            </div>
            <div class="course-body">
                <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                <div class="course-title"><?= e($c['title']) ?></div>
                <div class="course-desc"><?= e($c['description']) ?></div>
                <div class="course-meta">
                    <span>👨‍🏫 <?= e($c['teacher_name']) ?></span>
                    <span>🎓 <?= (int) $c['student_count'] ?> enrolled</span>
                </div>
            </div>
            <div class="course-footer">
                <span class="course-price <?= $c['price'] == 0 ? 'free' : '' ?>"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></span>
                <span class="btn btn-outline btn-sm">View →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
