<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
$stmt->execute([$id]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found. <a href="courses.php">Go back</a></p>');
}

$isOwner = $course['teacher_id'] == $user['id'];
$isAdmin = ($user['role'] ?? '') === 'admin';
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">You do not have permission to edit this course. <a href="course.php?id=' . $id . '">Go back</a></p>');
}

$subjects = $pdo->query('SELECT * FROM subjects ORDER BY name')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subjectId   = (int) ($_POST['subject_id'] ?? 0);
    $level       = $_POST['level'] ?? 'beginner';
    $language    = trim($_POST['language'] ?? 'English');
    $price       = (float) ($_POST['price'] ?? 0);
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if (mb_strlen($title) < 5) $errors[] = 'Title must be at least 5 characters.';
    if (mb_strlen($description) < 20) $errors[] = 'Description must be at least 20 characters.';
    if (!in_array($level, ['beginner','intermediate','advanced'], true)) $errors[] = 'Invalid level.';

    if (!$errors) {
        $imagePath = handleImageUpload('cover', 'courses') ?? $course['cover_url'];
        $stmt = $pdo->prepare(
            'UPDATE courses SET title=?, description=?, subject_id=?, level=?, language=?, price=?, cover_url=?, is_published=?, updated_by=?, updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([$title, $description, $subjectId ?: null, $level, $language, $price, $imagePath, $isPublished, $user['id'], $id]);
        flash('success', 'Course updated.');
        redirect('course.php?id=' . $id);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Course — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<nav class="navbar">
    <div class="nav-brand">🕌 <?= e(SITE_NAME) ?><small><?= e(SITE_AFFILIATION) ?></small></div>
    <div class="nav-links"><a href="dashboard.php">Dashboard</a><a href="logout.php" class="nav-btn">Logout</a></div>
</nav>

<div class="dashboard-wrap">
    <div class="dashboard-header">
        <h2>✏️ Edit Course</h2>
        <p><?= $isAdmin && !$isOwner ? 'You are editing this course as an admin.' : 'Update your course details below.' ?></p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Cover Image</label>
                <?php if ($course['cover_url']): ?>
                    <img src="<?= e($course['cover_url']) ?>" style="max-width:200px;border-radius:8px;margin-bottom:.6rem;display:block">
                <?php endif; ?>
                <input type="file" name="cover" class="form-control" accept="image/jpeg,image/png,image/webp">
                <div class="form-hint">Upload a new cover to replace the current one, or leave blank to keep it.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Course Title</label>
                <input type="text" name="title" class="form-control" value="<?= e($_POST['title'] ?? $course['title']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" required><?= e($_POST['description'] ?? $course['description']) ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Subject</label>
                    <select name="subject_id" class="form-control">
                        <option value="">Select subject</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= $course['subject_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['icon']) ?> <?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Level</label>
                    <select name="level" class="form-control">
                        <?php foreach (['beginner'=>'Beginner','intermediate'=>'Intermediate','advanced'=>'Advanced'] as $val=>$label): ?>
                            <option value="<?= $val ?>" <?= $course['level'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Language</label>
                    <input type="text" name="language" class="form-control" value="<?= e($_POST['language'] ?? $course['language']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Price ($) — 0 for free</label>
                    <input type="number" name="price" class="form-control" min="0" step="0.01" value="<?= e($_POST['price'] ?? $course['price']) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                    <input type="checkbox" name="is_published" value="1" style="width:auto" <?= $course['is_published'] ? 'checked' : '' ?>>
                    Published (visible in course catalog)
                </label>
            </div>

            <div style="display:flex;gap:.8rem">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="course.php?id=<?= $id ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div></div>
</div>
</body>
</html>
