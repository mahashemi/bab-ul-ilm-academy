<?php
require_once __DIR__ . '/db.php';
$user = auth();

$subjectId = (int) ($_GET['subject'] ?? 0);
$fieldId   = (int) ($_GET['field'] ?? 0);
$q = trim($_GET['q'] ?? '');
$fieldsOfStudy = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();
$subjects = $pdo->query(
    $fieldId > 0
        ? 'SELECT * FROM subjects WHERE field_of_study_id = ' . (int) $fieldId . ' ORDER BY name'
        : 'SELECT * FROM subjects ORDER BY name'
)->fetchAll();

$courseSelect = "c.*, u.name AS teacher_name, s.name AS subject_name, s.icon AS subject_icon,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count,
            (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
            (SELECT COALESCE(SUM(duration_minutes),0) FROM lessons l WHERE l.course_id = c.id) AS total_minutes,
            (SELECT COUNT(*) FROM course_reviews r WHERE r.course_id = c.id) AS review_count,
            (SELECT COALESCE(AVG(rating),0) FROM course_reviews r WHERE r.course_id = c.id) AS avg_rating";

$sql = "SELECT $courseSelect
        FROM courses c
        JOIN users u ON u.id = c.teacher_id
        LEFT JOIN subjects s ON s.id = c.subject_id
        WHERE c.is_published = 1 AND c.moderation_status = 'approved'";
$params = [];
if ($subjectId > 0) { $sql .= ' AND c.subject_id = ?'; $params[] = $subjectId; }
elseif ($fieldId > 0) { $sql .= ' AND s.field_of_study_id = ?'; $params[] = $fieldId; }
if ($q !== '') { $sql .= ' AND (c.title LIKE ? OR c.description LIKE ? OR u.name LIKE ? OR s.name LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%"); }
$sql .= ' ORDER BY c.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();

// "Bestseller" reflects real enrollment counts — top 3 most-enrolled
// courses in this filtered list (only if they actually have students).
$rankedByStudents = $courses;
usort($rankedByStudents, fn($a, $b) => (int) $b['student_count'] - (int) $a['student_count']);
$bestsellerIds = array_column(array_filter(array_slice($rankedByStudents, 0, 3), fn($c) => (int) $c['student_count'] > 0), 'id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>All Courses — <?= e(SITE_NAME) ?></title>
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
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects..." value="<?= e($q) ?>">
    </form>
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

<nav class="category-nav">
    <a href="courses.php" class="<?= ($fieldId === 0 && $subjectId === 0) ? 'active' : '' ?>"><i data-lucide="library" class="lucide-icon"></i> All Fields</a>
    <?php foreach ($fieldsOfStudy as $f): ?>
        <a href="?field=<?= (int) $f['id'] ?>" class="<?= $fieldId === (int) $f['id'] ? 'active' : '' ?>"><?= catIcon($f['icon']) ?> <?= e($f['name']) ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($subjects): ?>
<nav class="subcategory-nav">
    <?php foreach ($subjects as $s): ?>
        <a href="?<?= $fieldId ? 'field=' . (int) $fieldId . '&' : '' ?>subject=<?= (int) $s['id'] ?>" class="<?= $subjectId === (int) $s['id'] ? 'active' : '' ?>"><?= catIcon($s['icon']) ?> <?= e($s['name']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<div class="container section">
    <h2 class="section-title">All <span>Courses</span></h2>

    <form method="get" style="display:flex;gap:.6rem;margin-bottom:1.5rem;max-width:500px">
        <input type="text" name="q" class="form-control" placeholder="Search by course, teacher, or subject..." value="<?= e($q) ?>">
        <button type="submit" class="btn btn-primary">Search</button>
    </form>

    <p class="section-sub"><?= count($courses) ?> course(s) found</p>

    <?php if (!$courses): ?>
        <div class="empty-state"><div class="icon"><i data-lucide="library" class="lucide-icon"></i></div><h3>No courses found</h3></div>
    <?php else: ?>
    <div class="grid-3">
        <?php foreach ($courses as $c): ?><?= renderCourseCard($c, $bestsellerIds) ?><?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
