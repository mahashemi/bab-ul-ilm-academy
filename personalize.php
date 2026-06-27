<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

$fields = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();

$myFieldsStmt = $pdo->prepare('SELECT field_of_study_id FROM user_learning_fields WHERE user_id = ?');
$myFieldsStmt->execute([$user['id']]);
$myFieldIds = array_map('intval', $myFieldsStmt->fetchAll(PDO::FETCH_COLUMN));

$myStmt = $pdo->prepare('SELECT s.name FROM skills s JOIN user_skills us ON us.skill_id = s.id WHERE us.user_id = ? ORDER BY s.name');
$myStmt->execute([$user['id']]);
$mySkills = $myStmt->fetchAll(PDO::FETCH_COLUMN);
$allSkills = $pdo->query('SELECT name FROM skills ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $occupation = trim($_POST['occupation'] ?? '');
    $fieldIds   = array_filter(array_map('intval', $_POST['learning_field_ids'] ?? []));
    $skillsCsv  = trim($_POST['skills_csv'] ?? '');

    $pdo->prepare('UPDATE users SET occupation = ? WHERE id = ?')->execute([$occupation ?: null, $user['id']]);

    $pdo->prepare('DELETE FROM user_learning_fields WHERE user_id = ?')->execute([$user['id']]);
    if ($fieldIds) {
        $values = implode(',', array_fill(0, count($fieldIds), '(?, ?)'));
        $params = [];
        foreach ($fieldIds as $fid) { $params[] = $user['id']; $params[] = $fid; }
        $pdo->prepare("INSERT INTO user_learning_fields (user_id, field_of_study_id) VALUES $values")->execute($params);
    }

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
<html lang="<?= currentLocale() ?>" dir="<?= isRtl(currentLocale()) ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Personalize Your Experience — <?= e(SITE_NAME) ?></title>
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
        <input type="text" name="q" placeholder="<?= e(t('nav_search_placeholder')) ?>">
    </form>
    <div class="nav-links">
        <a href="index.php"><?= t('nav_home') ?></a>
        <a href="courses.php"><?= t('nav_courses') ?></a>
        <a href="about.php"><?= t('nav_about') ?></a>
        <a href="feedback.php"><?= t('nav_feedback') ?></a>
        <div class="nav-account">
            <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="<?= e(t('nav_language')) ?>">
                <i data-lucide="globe" class="lucide-icon"></i>
            </button>
            <div class="nav-account-menu">
                <a href="set-language.php?lang=en&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">English</a>
                <a href="set-language.php?lang=ur&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">اردو</a>
                <a href="set-language.php?lang=fa&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">فارسی</a>
                <a href="set-language.php?lang=ar&return=<?= e(urlencode($_SERVER['REQUEST_URI'] ?? 'index.php')) ?>">العربية</a>
            </div>
        </div>

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
                <label class="form-label">What field(s) are you learning for?</label>
                <div class="form-hint" style="margin-top:-.3rem;margin-bottom:.6rem">Select as many as apply.</div>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.8rem">
                    <?php foreach ($fields as $f): ?>
                    <label class="card" style="cursor:pointer;text-align:center;padding:1.1rem .6rem;margin:0">
                        <input type="checkbox" name="learning_field_ids[]" value="<?= (int) $f['id'] ?>" style="width:auto;margin-bottom:.5rem" <?= in_array((int) $f['id'], $myFieldIds, true) ? 'checked' : '' ?>>
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
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
