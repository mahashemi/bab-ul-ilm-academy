<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();
if (($user['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Access denied. Admins only. <a href="index.php">Go back</a></p>');
}

// ── CSV Export ─────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    $map = [
        'users'   => ['sql' => 'SELECT id, name, email, role, phone, country, qualification, is_approved, created_at FROM users ORDER BY id', 'file' => 'babulilm_users.csv'],
        'courses' => ['sql' => "SELECT c.id, c.title, u.name AS teacher, s.name AS subject, c.level, c.language, c.price, c.is_published,
                                        (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS students, c.created_at
                                 FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id ORDER BY c.id", 'file' => 'babulilm_courses.csv'],
    ];
    if (isset($map[$type])) {
        $rows = $pdo->query($map[$type]['sql'])->fetchAll();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $map[$type]['file'] . '"');
        $out = fopen('php://output', 'w');
        if ($rows) fputcsv($out, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($out, $r);
        fclose($out);
        exit;
    }
}

// ── Actions ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['toggle_approved'])) {
        $pdo->prepare('UPDATE users SET is_approved = 1 - is_approved WHERE id = ?')->execute([(int) $_POST['toggle_approved']]);
    } elseif (isset($_POST['toggle_verified'])) {
        $pdo->prepare('UPDATE users SET is_verified = 1, verification_token = NULL, verification_expires = NULL WHERE id = ?')->execute([(int) $_POST['toggle_verified']]);
    } elseif (isset($_POST['toggle_published'])) {
        $pdo->prepare('UPDATE courses SET is_published = 1 - is_published WHERE id = ?')->execute([(int) $_POST['toggle_published']]);
    } elseif (isset($_POST['set_role']) && $_POST['set_role'] !== '') {
        $targetId = (int) $_POST['user_id'];
        $newRole = $_POST['set_role'];
        if ($targetId !== (int) $user['id'] && in_array($newRole, ['student','teacher','admin'], true)) {
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);
        }
    }
    redirect('admin.php?tab=' . ($_GET['tab'] ?? 'users'));
}

$tab = $_GET['tab'] ?? 'users';

$stats = $pdo->query(
    "SELECT (SELECT COUNT(*) FROM users WHERE role='teacher') AS teachers,
            (SELECT COUNT(*) FROM users WHERE role='student') AS students,
            (SELECT COUNT(*) FROM courses) AS total_courses,
            (SELECT COUNT(*) FROM courses WHERE is_published=1) AS published_courses,
            (SELECT COUNT(*) FROM enrollments) AS total_enrollments"
)->fetch();

$users = $pdo->query("SELECT * FROM users WHERE role != 'admin' ORDER BY created_at DESC")->fetchAll();
$courses = $pdo->query(
    "SELECT c.*, u.name AS teacher_name, s.name AS subject_name,
            (SELECT COUNT(*) FROM enrollments e WHERE e.course_id = c.id) AS student_count
     FROM courses c JOIN users u ON u.id = c.teacher_id LEFT JOIN subjects s ON s.id = c.subject_id
     ORDER BY c.created_at DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <div class="nav-brand">🕌 <?= e(SITE_NAME) ?> <small style="color:var(--gold)">ADMIN</small></div>
    <div class="nav-links">
        <a href="index.php">Site</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php" class="nav-btn">Logout</a>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:1100px">
    <div class="dashboard-header">
        <h2>🛠️ Admin Panel</h2>
        <p>Manage teachers, students, and courses.</p>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['teachers'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Teachers</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['students'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Students</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['published_courses'] ?> / <?= (int) $stats['total_courses'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Published / Total Courses</div>
        </div>
        <div style="background:var(--white);border-radius:var(--radius-sm);padding:1.2rem;box-shadow:var(--shadow);border:1.5px solid var(--border);text-align:center">
            <div style="font-size:1.8rem;font-weight:800;color:var(--green-deep)"><?= (int) $stats['total_enrollments'] ?></div>
            <div style="font-size:.8rem;color:var(--text-mid)">Total Enrollments</div>
        </div>
    </div>

    <div class="tabs">
        <a href="?tab=users" class="tab-btn <?= $tab === 'users' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center">👥 Users (<?= count($users) ?>)</a>
        <a href="?tab=courses" class="tab-btn <?= $tab === 'courses' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center">📚 Courses (<?= count($courses) ?>)</a>
    </div>

    <?php if ($tab === 'courses'): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
            <a href="?export=courses" class="btn btn-outline btn-sm">⬇ Download CSV</a>
        </div>
        <table class="table">
            <thead><tr><th>Title</th><th>Teacher</th><th>Subject</th><th>Level</th><th>Price</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($courses as $c): ?>
                <tr>
                    <td><a href="course.php?id=<?= (int) $c['id'] ?>" target="_blank"><?= e($c['title']) ?></a></td>
                    <td><?= e($c['teacher_name']) ?></td>
                    <td><?= e($c['subject_name'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= e($c['level']) ?>"><?= e(ucfirst($c['level'])) ?></span></td>
                    <td><?= $c['price'] > 0 ? '$' . number_format((float) $c['price']) : 'Free' ?></td>
                    <td><?= (int) $c['student_count'] ?></td>
                    <td><span class="badge <?= $c['is_published'] ? 'badge-free' : 'badge-paid' ?>"><?= $c['is_published'] ? 'Published' : 'Draft' ?></span></td>
                    <td style="display:flex;gap:.4rem">
                        <a href="edit-course.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_published" value="<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline"><?= $c['is_published'] ? 'Unpublish' : 'Publish' ?></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
            <a href="?export=users" class="btn btn-outline btn-sm">⬇ Download CSV</a>
        </div>
        <table class="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Country</th><th>Qualification</th><th>Status</th><th>Verified</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span></td>
                    <td><?= e($u['country'] ?: '—') ?></td>
                    <td style="max-width:220px"><?= e($u['qualification'] ?: '—') ?></td>
                    <td><span class="badge <?= $u['is_approved'] ? 'badge-free' : 'badge-paid' ?>"><?= $u['is_approved'] ? 'Active' : 'Suspended' ?></span></td>
                    <td><?= $u['is_verified'] ? '✓ Verified' : '—' ?></td>
                    <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    <td style="display:flex;gap:.4rem">
                        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_approved" value="<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline"><?= $u['is_approved'] ? 'Suspend' : 'Reactivate' ?></button></form>
                        <?php if (!$u['is_verified']): ?>
                        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_verified" value="<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline">Verify</button></form>
                        <?php endif; ?>
                        <?php if ((int) $u['id'] !== (int) $user['id']): ?>
                        <form method="post" onsubmit="return confirm('Change <?= e($u['name']) ?>\'s role?')">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                            <select name="set_role" onchange="this.form.submit()" class="form-control" style="padding:.3rem .5rem;font-size:.78rem;width:auto;display:inline-block">
                                <option value="">Change role…</option>
                                <option value="student" <?= $u['role']==='student'?'selected':'' ?>>Student</option>
                                <option value="teacher" <?= $u['role']==='teacher'?'selected':'' ?>>Teacher</option>
                                <option value="admin" <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
                            </select>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
