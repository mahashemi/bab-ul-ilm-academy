<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (($user['role'] ?? '') === 'admin') redirect('admin.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php">🕌 <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">☰</button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <span class="nav-user">👤 <?= e($user['name']) ?></span><a href="chat.php">Messages</a>
        <a href="courses.php">Courses</a>
        <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
        <a href="edit-profile.php">Edit Profile</a>
        <?php if (($user['role'] ?? '') === 'admin'): ?><a href="admin.php">Admin</a><?php endif; ?>
        <a href="logout.php" class="nav-btn">Logout</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
    </div>
</nav>

<div class="dashboard-wrap">
    <div class="dashboard-header">
        <h2>👋 Welcome, <?= e($user['name']) ?></h2>
        <p><?= ($user['role'] ?? '') === 'teacher' ? 'Manage your courses and track your students.' : 'Continue your learning journey.' ?></p>
        <span class="dashboard-role"><?= e(ucfirst(($user['role'] ?? ''))) ?></span>
    </div>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>

    <?php if (($user['role'] ?? '') === 'teacher'): ?>
        <?php
        $stmt = $pdo->prepare(
            "SELECT c.*, s.name AS subject_name, (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
             FROM courses c LEFT JOIN subjects s ON s.id = c.subject_id
             WHERE c.teacher_id = ? ORDER BY c.created_at DESC"
        );
        $stmt->execute([$user['id']]);
        $myCourses = $stmt->fetchAll();
        ?>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
            <h3 style="font-size:1.1rem;color:var(--green-deep)">My Courses (<?= count($myCourses) ?>)</h3>
            <a href="add-course.php" class="btn btn-primary btn-sm">+ New Course</a>
        </div>

        <?php if (!$myCourses): ?>
            <div class="empty-state"><div class="icon">📚</div><h3>You haven't created any courses yet</h3></div>
        <?php else: ?>
        <table class="table table-cards">
            <thead><tr><th>Title</th><th>Subject</th><th>Level</th><th>Price</th><th>Students</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($myCourses as $c): ?>
                <tr>
                    <td data-label="Title"><a href="course.php?id=<?= (int) $c['id'] ?>"><?= e($c['title']) ?></a></td>
                    <td data-label="Subject"><?= e($c['subject_name'] ?? '—') ?></td>
                    <td data-label="Level"><span class="badge badge-<?= e($c['level']) ?>"><?= e(ucfirst($c['level'])) ?></span></td>
                    <td data-label="Price"><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></td>
                    <td data-label="Students"><?= (int) $c['student_count'] ?></td>
                    <td data-label="Status"><span class="badge <?= $c['is_published'] ? 'badge-free' : 'badge-paid' ?>"><?= $c['is_published'] ? 'Published' : 'Draft' ?></span></td>
                    <td data-label="Actions" class="table-actions">
                        <a href="edit-course.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                        <a href="add-lesson.php?course_id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline">+ Lesson</a>
                        <a href="course-students.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline">Students (<?= (int) $c['student_count'] ?>)</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    <?php else: ?>
        <?php
        $stmt = $pdo->prepare(
            "SELECT c.*, u.name AS teacher_name, s.name AS subject_name,
                    (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM lesson_progress lp JOIN lessons l2 ON l2.id = lp.lesson_id WHERE l2.course_id = c.id AND lp.student_id = ?) AS completed_count
             FROM enrollments e
             JOIN courses c ON c.id = e.course_id
             JOIN users u ON u.id = c.teacher_id
             LEFT JOIN subjects s ON s.id = c.subject_id
             WHERE e.student_id = ?
             ORDER BY e.enrolled_at DESC"
        );
        $stmt->execute([$user['id'], $user['id']]);
        $myCourses = $stmt->fetchAll();
        ?>
        <h3 style="font-size:1.1rem;color:var(--green-deep);margin-bottom:1rem">My Enrolled Courses (<?= count($myCourses) ?>)</h3>

        <?php if (!$myCourses): ?>
            <div class="empty-state">
                <div class="icon">🎓</div>
                <h3>You haven't enrolled in any courses yet</h3>
                <p><a href="courses.php" class="btn btn-primary" style="margin-top:1rem">Browse Courses</a></p>
            </div>
        <?php else: ?>
        <div class="grid-2">
            <?php foreach ($myCourses as $c): ?>
            <?php $pct = $c['lesson_count'] ? (int) round($c['completed_count'] / $c['lesson_count'] * 100) : 0; ?>
            <a href="course.php?id=<?= (int) $c['id'] ?>" class="card" style="text-decoration:none;color:inherit">
                <div class="card-body">
                    <div class="course-subject"><?= e($c['subject_name'] ?? 'General') ?></div>
                    <div class="card-title"><?= e($c['title']) ?></div>
                    <div style="font-size:.85rem;color:var(--text-light);margin-bottom:.6rem">👨‍🏫 <?= e($c['teacher_name']) ?></div>
                    <div class="progress-bar"><div class="progress-fill" style="width:<?= $pct ?>%"></div></div>
                    <p style="font-size:.8rem;color:var(--text-light);margin-top:.4rem"><?= $pct ?>% complete (<?= (int) $c['completed_count'] ?>/<?= (int) $c['lesson_count'] ?> lessons)</p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<script src="app.js" defer></script>
</body>
</html>
