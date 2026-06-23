<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

$fields = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();

$myStmt = $pdo->prepare('SELECT s.name FROM skills s JOIN user_skills us ON us.skill_id = s.id WHERE us.user_id = ? ORDER BY s.name');
$myStmt->execute([$user['id']]);
$mySkills = $myStmt->fetchAll(PDO::FETCH_COLUMN);
$allSkills = $pdo->query('SELECT name FROM skills ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $occupation = trim($_POST['occupation'] ?? '');
    $fieldId    = (int) ($_POST['learning_field_id'] ?? 0) ?: null;
    $skillsCsv  = trim($_POST['skills_csv'] ?? '');

    $pdo->prepare('UPDATE users SET occupation = ?, learning_field_id = ? WHERE id = ?')
        ->execute([$occupation ?: null, $fieldId, $user['id']]);

    // Sync interests using the same shared skills/user_skills mechanism as Edit Profile.
    $names = array_filter(array_unique(array_map('trim', explode(',', $skillsCsv))));
    $skillIds = [];
    foreach ($names as $skillName) {
        if ($skillName === '') continue;
        $pdo->prepare('INSERT INTO skills (name) VALUES (?) ON DUPLICATE KEY UPDATE id = id')->execute([$skillName]);
        $idStmt = $pdo->prepare('SELECT id FROM skills WHERE name = ?');
        $idStmt->execute([$skillName]);
        $skillIds[] = (int) $idStmt->fetchColumn();
    }
    $pdo->prepare('DELETE FROM user_skills WHERE user_id = ?')->execute([$user['id']]);
    if ($skillIds) {
        $values = implode(',', array_fill(0, count($skillIds), '(?, ?)'));
        $params = [];
        foreach ($skillIds as $sid) { $params[] = $user['id']; $params[] = $sid; }
        $pdo->prepare("INSERT INTO user_skills (user_id, skill_id) VALUES $values")->execute($params);
    }

    flash('success', 'Thanks! We will use this to recommend more relevant courses.');
    redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Personalize Your Experience — <?= e(SITE_NAME) ?></title>
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
        <a href="dashboard.php">Dashboard</a>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:680px">
    <div class="dashboard-header">
        <h2><i data-lucide="sparkles" class="lucide-icon"></i> Personalize Your Experience</h2>
        <p>Answer a few questions so we can recommend more relevant courses.</p>
    </div>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">What field are you learning for?</label>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.8rem">
                    <?php foreach ($fields as $f): ?>
                    <label class="card" style="cursor:pointer;text-align:center;padding:1.1rem .6rem;margin:0">
                        <input type="radio" name="learning_field_id" value="<?= (int) $f['id'] ?>" style="width:auto;margin-bottom:.5rem" <?= (int) $me['learning_field_id'] === (int) $f['id'] ? 'checked' : '' ?>>
                        <div style="font-size:1.5rem;color:var(--green-deep)"><?= catIcon($f['icon']) ?></div>
                        <div style="font-weight:600;font-size:.85rem;margin-top:.3rem"><?= e($f['name']) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">What is your occupation?</label>
                <input type="text" name="occupation" class="form-control" placeholder="e.g. Software Engineer, Imam, Student" value="<?= e($me['occupation'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label">What are you interested in?</label>
                <div id="skillChips" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem"></div>
                <input type="text" id="skillInput" class="form-control" list="skillSuggestions" placeholder="Type an interest and press Enter (e.g. Tajweed, Mathematics)">
                <datalist id="skillSuggestions">
                    <?php foreach ($allSkills as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                </datalist>
                <input type="hidden" name="skills_csv" id="skillsCsv" value="<?= e(implode(',', $mySkills)) ?>">
                <div class="form-hint">These help us recommend courses you'll actually want to take.</div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Save & Get Recommendations</button>
        </form>
    </div></div>
</div>

<script>
let skills = <?= json_encode(array_values($mySkills)) ?>;
function renderSkillChips() {
    const wrap = document.getElementById('skillChips');
    wrap.innerHTML = '';
    skills.forEach((s, i) => {
        const chip = document.createElement('span');
        chip.className = 'cat-chip';
        chip.style.cursor = 'default';
        chip.innerHTML = s.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + ' <span style="cursor:pointer;font-weight:700;margin-left:.3rem" onclick="removeSkill(' + i + ')">×</span>';
        wrap.appendChild(chip);
    });
    document.getElementById('skillsCsv').value = skills.join(',');
}
function removeSkill(i) {
    skills.splice(i, 1);
    renderSkillChips();
}
document.addEventListener('DOMContentLoaded', function () {
    renderSkillChips();
    const input = document.getElementById('skillInput');
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const val = input.value.trim();
            if (val && !skills.includes(val)) {
                skills.push(val);
                renderSkillChips();
            }
            input.value = '';
        }
    });
});
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
