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
        'feedback' => ['sql' => 'SELECT id, name, email, message, is_read, created_at FROM feedback ORDER BY id DESC', 'file' => 'babulilm_feedback.csv'],
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
    } elseif (isset($_POST['add_field'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        if ($name !== '') {
            $pdo->prepare('INSERT INTO fields_of_study (name, icon) VALUES (?, ?)')->execute([$name, $icon]);
        }
    } elseif (isset($_POST['edit_field'])) {
        $pdo->prepare('UPDATE fields_of_study SET name=?, icon=? WHERE id=?')
            ->execute([trim($_POST['name']), trim($_POST['icon']), (int) $_POST['edit_field']]);
    } elseif (isset($_POST['delete_field'])) {
        $pdo->prepare('DELETE FROM fields_of_study WHERE id = ?')->execute([(int) $_POST['delete_field']]);
    } elseif (isset($_POST['add_subject'])) {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '');
        $fieldId = (int) ($_POST['field_of_study_id'] ?? 0) ?: null;
        if ($name !== '') {
            $pdo->prepare('INSERT INTO subjects (field_of_study_id, name, icon) VALUES (?, ?, ?)')->execute([$fieldId, $name, $icon]);
        }
    } elseif (isset($_POST['edit_subject'])) {
        $fieldId = (int) ($_POST['field_of_study_id'] ?? 0) ?: null;
        $pdo->prepare('UPDATE subjects SET name=?, icon=?, field_of_study_id=? WHERE id=?')
            ->execute([trim($_POST['name']), trim($_POST['icon']), $fieldId, (int) $_POST['edit_subject']]);
    } elseif (isset($_POST['delete_subject'])) {
        $pdo->prepare('DELETE FROM subjects WHERE id = ?')->execute([(int) $_POST['delete_subject']]);
    } elseif (isset($_POST['save_settings'])) {
        foreach (['SITE_NAME', 'SITE_TAGLINE', 'SITE_AFFILIATION'] as $key) {
            $val = trim($_POST[$key] ?? '');
            $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?')
                ->execute([$key, $val, $val]);
        }
        flash('success', 'Settings updated.');
    } elseif (isset($_POST['toggle_feedback_read'])) {
        $pdo->prepare('UPDATE feedback SET is_read = 1 - is_read WHERE id = ?')->execute([(int) $_POST['toggle_feedback_read']]);
    } elseif (isset($_POST['delete_feedback'])) {
        $pdo->prepare('DELETE FROM feedback WHERE id = ?')->execute([(int) $_POST['delete_feedback']]);
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
$fieldsOfStudy = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();
$subjects = $pdo->query(
    'SELECT s.*, f.name AS field_name FROM subjects s LEFT JOIN fields_of_study f ON f.id = s.field_of_study_id ORDER BY f.name, s.name'
)->fetchAll();
$currentSettings = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$feedback = $pdo->query('SELECT * FROM feedback ORDER BY created_at DESC')->fetchAll();
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
    <a class="nav-brand" href="index.php">🕌 <?= e(SITE_NAME) ?> <small style="color:var(--gold)">ADMIN</small></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu">☰</button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <div class="nav-links">
        <span class="nav-user">👤 <?= e($user['name']) ?></span><a href="chat.php">Messages</a>
        <a href="index.php">Site</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php" class="nav-btn">Logout</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
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
        <a href="?tab=subjects" class="tab-btn <?= $tab === 'subjects' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center">🏷️ Subjects (<?= count($subjects) ?>)</a>
        <a href="?tab=settings" class="tab-btn <?= $tab === 'settings' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center">⚙️ Settings</a>
        <a href="?tab=feedback" class="tab-btn <?= $tab === 'feedback' ? 'active' : '' ?>" style="text-decoration:none;display:block;text-align:center">💬 Feedback (<?= count($feedback) ?>)</a>
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
                        <a href="course-students.php?id=<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline">Students (<?= (int) $c['student_count'] ?>)</a>
                        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_published" value="<?= (int) $c['id'] ?>" class="btn btn-sm btn-outline"><?= $c['is_published'] ? 'Unpublish' : 'Publish' ?></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'subjects'): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">Fields of Study (top-level grouping)</h3>
            <form method="post" style="display:grid;grid-template-columns:1fr 100px auto;gap:.6rem;align-items:end;margin-bottom:1.2rem">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group" style="margin:0"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group" style="margin:0"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" placeholder="🕌"></div>
                <button type="submit" name="add_field" value="1" class="btn btn-primary">+ Add Field</button>
            </form>
            <table class="table" style="margin:0">
                <thead><tr><th>Icon</th><th>Name</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach ($fieldsOfStudy as $f): $ffid = 'field-' . (int) $f['id']; ?>
                    <tr>
                        <td><input type="text" name="icon" form="<?= $ffid ?>" value="<?= e($f['icon']) ?>" class="form-control" style="width:70px;padding:.4rem"></td>
                        <td><input type="text" name="name" form="<?= $ffid ?>" value="<?= e($f['name']) ?>" class="form-control" style="padding:.4rem"></td>
                        <td style="display:flex;gap:.4rem">
                            <form method="post" id="<?= $ffid ?>" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                                <button type="submit" name="edit_field" value="<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline">Save</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this field of study? Subjects in it will become ungrouped.')" style="display:inline">
                                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                                <button type="submit" name="delete_field" value="<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline" style="color:#c00;border-color:#c00">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>

        <div class="card" style="margin-bottom:1.5rem"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">+ Add New Subject</h3>
            <form method="post" style="display:grid;grid-template-columns:1fr 1fr 100px auto;gap:.6rem;align-items:end">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group" style="margin:0">
                    <label class="form-label">Field of Study</label>
                    <select name="field_of_study_id" class="form-control">
                        <option value="">— None —</option>
                        <?php foreach ($fieldsOfStudy as $f): ?>
                            <option value="<?= (int) $f['id'] ?>"><?= e($f['icon']) ?> <?= e($f['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin:0"><label class="form-label">Name</label><input type="text" name="name" class="form-control" required></div>
                <div class="form-group" style="margin:0"><label class="form-label">Icon</label><input type="text" name="icon" class="form-control" placeholder="📖"></div>
                <button type="submit" name="add_subject" value="1" class="btn btn-primary">+ Add</button>
            </form>
        </div></div>

        <table class="table">
            <thead><tr><th>Icon</th><th>Name</th><th>Field of Study</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($subjects as $s): $fid = 'subj-' . (int) $s['id']; ?>
                <tr>
                    <td><input type="text" name="icon" form="<?= $fid ?>" value="<?= e($s['icon']) ?>" class="form-control" style="width:70px;padding:.4rem"></td>
                    <td><input type="text" name="name" form="<?= $fid ?>" value="<?= e($s['name']) ?>" class="form-control" style="padding:.4rem"></td>
                    <td>
                        <select name="field_of_study_id" form="<?= $fid ?>" class="form-control" style="padding:.4rem">
                            <option value="">— None —</option>
                            <?php foreach ($fieldsOfStudy as $f): ?>
                                <option value="<?= (int) $f['id'] ?>" <?= (int) $s['field_of_study_id'] === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['icon']) ?> <?= e($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="display:flex;gap:.4rem">
                        <form method="post" id="<?= $fid ?>" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="edit_subject" value="<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline">Save</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this subject? Courses using it will become uncategorized.')" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                            <button type="submit" name="delete_subject" value="<?= (int) $s['id'] ?>" class="btn btn-sm btn-outline" style="color:#c00;border-color:#c00">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($tab === 'settings'): ?>
        <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
        <div class="card"><div class="card-body">
            <h3 style="font-size:1rem;margin-bottom:1rem">Site Branding</h3>
            <form method="post">
                <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                <div class="form-group">
                    <label class="form-label">Site Name</label>
                    <input type="text" name="SITE_NAME" class="form-control" value="<?= e($currentSettings['SITE_NAME'] ?? SITE_NAME) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Tagline</label>
                    <input type="text" name="SITE_TAGLINE" class="form-control" value="<?= e($currentSettings['SITE_TAGLINE'] ?? SITE_TAGLINE) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Affiliation (shown below the site name in the header)</label>
                    <input type="text" name="SITE_AFFILIATION" class="form-control" value="<?= e($currentSettings['SITE_AFFILIATION'] ?? SITE_AFFILIATION) ?>">
                </div>
                <button type="submit" name="save_settings" value="1" class="btn btn-primary">Save Settings</button>
            </form>
        </div></div>
    <?php elseif ($tab === 'feedback'): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
            <a href="?export=feedback" class="btn btn-outline btn-sm">⬇ Download CSV</a>
        </div>
        <?php if (!$feedback): ?>
            <div class="empty-state"><div class="icon">💬</div><h3>No feedback yet</h3></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>From</th><th>Email</th><th>Message</th><th>Date</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($feedback as $f): ?>
                <tr style="<?= $f['is_read'] ? 'opacity:.6' : '' ?>">
                    <td><?= e($f['name']) ?></td>
                    <td><?= e($f['email']) ?></td>
                    <td style="max-width:320px"><?= e($f['message']) ?></td>
                    <td><?= date('M j, Y', strtotime($f['created_at'])) ?></td>
                    <td><span class="badge <?= $f['is_read'] ? 'badge-paid' : 'badge-free' ?>"><?= $f['is_read'] ? 'Read' : 'New' ?></span></td>
                    <td style="display:flex;gap:.4rem">
                        <form method="post"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="toggle_feedback_read" value="<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline"><?= $f['is_read'] ? 'Mark Unread' : 'Mark Read' ?></button></form>
                        <form method="post" onsubmit="return confirm('Delete this feedback?')"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><button type="submit" name="delete_feedback" value="<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline" style="color:#c00;border-color:#c00">Delete</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
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
<script src="app.js" defer></script>
</body>
</html>
