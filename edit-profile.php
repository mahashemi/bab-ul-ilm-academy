<?php
require_once __DIR__ . '/db.php';
requireAuth();
$user = auth();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

$dialCode = '';
$phoneNumber = '';
if ($me['phone'] && preg_match('/^(\+\d{1,4})\s+(\d+)$/', $me['phone'], $m)) {
    $dialCode = $m[1];
    $phoneNumber = $m[2];
}

$errors = [];
$success = false;

$myStmt = $pdo->prepare('SELECT s.name FROM skills s JOIN user_skills us ON us.skill_id = s.id WHERE us.user_id = ? ORDER BY s.name');
$myStmt->execute([$user['id']]);
$mySkills = $myStmt->fetchAll(PDO::FETCH_COLUMN);
$allSkills = $pdo->query('SELECT name FROM skills ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name          = trim($_POST['name'] ?? '');
    $displayName   = trim($_POST['display_name'] ?? '');
    $country       = trim($_POST['country'] ?? '');
    $bio           = trim($_POST['bio'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $headline      = trim($_POST['headline'] ?? '');
    $dialCodeIn    = trim($_POST['dial_code'] ?? '');
    $phoneDigits   = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
    $currentPass   = $_POST['current_password'] ?? '';
    $newPass       = $_POST['new_password'] ?? '';
    $skillsCsv     = trim($_POST['skills_csv'] ?? '');
    $gender        = $_POST['gender'] ?? $me['gender'] ?? 'unspecified';
    $dob           = trim($_POST['date_of_birth'] ?? '');
    $preferredLang = trim($_POST['preferred_language'] ?? '');
    $educationLevel = trim($_POST['education_level'] ?? '');

    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if ($displayName !== '' && mb_strlen($displayName) < 2) $errors[] = 'Display name must be at least 2 characters.';
    if ($country === '') $errors[] = 'Please select your country.';
    if ($phoneDigits !== '' || $dialCodeIn !== '') {
        if (!preg_match('/^\+\d{1,4}$/', $dialCodeIn)) $errors[] = 'Please select a valid country code.';
        if (!preg_match('/^\d{10}$/', $phoneDigits)) $errors[] = 'Phone number must be exactly 10 digits (without the leading 0 or country code).';
    }
    $phone = $phoneDigits !== '' ? $dialCodeIn . ' ' . $phoneDigits : '';

    if (!in_array($gender, ['male', 'female', 'unspecified'], true)) $gender = 'unspecified';
    if ($dob !== '') {
        $dobTime = strtotime($dob);
        if (!$dobTime || $dobTime > time()) $errors[] = 'Please enter a valid date of birth.';
    }

    if (isApprovedTeacher($me) && mb_strlen($qualification) < 5) $errors[] = 'Please describe your teaching qualification (min 5 characters).';

    if ($newPass !== '') {
        if (!password_verify($currentPass, $me['password'])) $errors[] = 'Current password is incorrect.';
        if (mb_strlen($newPass) < 6) $errors[] = 'New password must be at least 6 characters.';
    }

    if (!$errors) {
        $qualToSave = isApprovedTeacher($me) ? $qualification : $me['qualification'];
        $headlineToSave = isApprovedTeacher($me) ? ($headline ?: null) : $me['headline'];
        $educationToSave = in_array($me['role'], ['student', 'parent'], true) ? ($educationLevel ?: null) : $me['education_level'];
        $avatarPath = handleImageUpload('avatar', 'avatars') ?? $me['avatar'];
        $displayNameToSave = $displayName ?: null;
        if ($newPass !== '') {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET name=?, display_name=?, country=?, bio=?, phone=?, qualification=?, headline=?, gender=?, date_of_birth=?, preferred_language=?, education_level=?, avatar=?, password=? WHERE id=?')
                ->execute([$name, $displayNameToSave, $country, $bio, $phone, $qualToSave, $headlineToSave, $gender, $dob ?: null, $preferredLang ?: null, $educationToSave, $avatarPath, $hash, $user['id']]);
            logActivity($pdo, (int) $user['id'], 'Password changed');
        } else {
            $pdo->prepare('UPDATE users SET name=?, display_name=?, country=?, bio=?, phone=?, qualification=?, headline=?, gender=?, date_of_birth=?, preferred_language=?, education_level=?, avatar=? WHERE id=?')
                ->execute([$name, $displayNameToSave, $country, $bio, $phone, $qualToSave, $headlineToSave, $gender, $dob ?: null, $preferredLang ?: null, $educationToSave, $avatarPath, $user['id']]);
        }

        // Sync skills: reuse existing skill rows by name, create any new ones,
        // so the same skill is shared (not duplicated) across all users.
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
        $mySkills = $names;

        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['display_name'] = $displayNameToSave;
        $_SESSION['user']['avatar'] = $avatarPath;
        $success = true;
        $me['name'] = $name; $me['display_name'] = $displayNameToSave; $me['country'] = $country; $me['bio'] = $bio; $me['qualification'] = $qualToSave; $me['headline'] = $headlineToSave;
        $me['gender'] = $gender; $me['date_of_birth'] = $dob; $me['preferred_language'] = $preferredLang; $me['education_level'] = $educationToSave;
        $me['avatar'] = $avatarPath;
        $dialCode = $dialCodeIn; $phoneNumber = $phoneDigits;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit Profile — <?= e(SITE_NAME) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
<script>
const COUNTRIES = [
    {name:"Pakistan", dial:"+92"}, {name:"India", dial:"+91"}, {name:"Bangladesh", dial:"+880"},
    {name:"Saudi Arabia", dial:"+966"}, {name:"United Arab Emirates", dial:"+971"}, {name:"Qatar", dial:"+974"},
    {name:"Kuwait", dial:"+965"}, {name:"Bahrain", dial:"+973"}, {name:"Oman", dial:"+968"},
    {name:"Turkey", dial:"+90"}, {name:"Egypt", dial:"+20"}, {name:"Indonesia", dial:"+62"},
    {name:"Malaysia", dial:"+60"}, {name:"Afghanistan", dial:"+93"}, {name:"Iran", dial:"+98"},
    {name:"Iraq", dial:"+964"}, {name:"Jordan", dial:"+962"}, {name:"Lebanon", dial:"+961"},
    {name:"Morocco", dial:"+212"}, {name:"Tunisia", dial:"+216"}, {name:"Algeria", dial:"+213"},
    {name:"Nigeria", dial:"+234"}, {name:"South Africa", dial:"+27"}, {name:"Sri Lanka", dial:"+94"},
    {name:"United Kingdom", dial:"+44"}, {name:"United States", dial:"+1"}, {name:"Canada", dial:"+1"},
    {name:"Australia", dial:"+61"}, {name:"Germany", dial:"+49"}, {name:"France", dial:"+33"},
    {name:"Other", dial:""}
];
function updateDialCode() {
    const sel = document.getElementById('countrySelect');
    const c = COUNTRIES.find(c => c.name === sel.value);
    document.getElementById('dialCode').value = c ? c.dial : '';
}
function cleanPhoneInput(el) {
    el.value = el.value.replace(/\D/g, '').slice(0, 10);
}

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
</head>
<body>
<nav class="navbar">
    <a class="nav-brand" href="index.php"><img src="assets/lockup-gold.svg" alt="<?= e(SITE_NAME) ?>" class="nav-logo"></a>
    <button class="nav-toggle" onclick="toggleNav()" aria-label="Menu"><i data-lucide="menu" class="lucide-icon"></i></button>
    <div class="nav-scrim" onclick="toggleNav()"></div>
    <form class="nav-search" action="courses.php" method="get">
        <i data-lucide="search" class="lucide-icon"></i>
        <input type="text" name="q" placeholder="Search for courses, teachers, subjects...">
    </form>
    <div class="nav-links">
        <a href="index.php">Home</a>
        <a href="courses.php">Courses</a>
        <a href="about.php">About</a>
        <a href="feedback.php">Feedback</a>
        <?php if ($user): ?>
            <a href="chat.php">Messages</a>
            <?php if (isApprovedTeacher($user)): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
            <?= renderCartIcon($pdo, $user) ?>
            <div class="nav-account">
                <button class="nav-account-trigger" type="button" onclick="toggleAccountMenu(event)" aria-label="Account menu">
                    <?= renderAvatar($user) ?>
                    <i data-lucide="chevron-down" class="lucide-icon"></i>
                </button>
                <div class="nav-account-menu">
                    <div class="nav-account-header">
                        <?= renderAvatar($user) ?>
                        <div>
                            <div class="nav-account-name"><?= e(displayNameOf($user)) ?></div>
                            <div class="nav-account-email"><?= e($user['email']) ?></div>
                        </div>
                    </div>
                    <div class="nav-menu-divider"></div>
                    <a href="dashboard.php"><i data-lucide="layout-dashboard" class="lucide-icon"></i> Dashboard</a>
                    <a href="chat.php"><i data-lucide="message-circle" class="lucide-icon"></i> Messages</a>
                    <?php if (isApprovedTeacher($user)): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
                    <?php if (!isApprovedTeacher($user) && ($user['teacher_status'] ?? 'none') !== 'pending'): ?><a href="become-instructor.php"><i data-lucide="presentation" class="lucide-icon"></i> Become an Instructor</a><?php endif; ?>
                    <div class="nav-menu-divider"></div>
                    <a href="edit-profile.php"><i data-lucide="user-cog" class="lucide-icon"></i> Edit Profile</a>
                    <a href="activity-log.php"><i data-lucide="shield-check" class="lucide-icon"></i> Account Activity</a>
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

<div class="dashboard-wrap" style="max-width:640px">
    <div class="dashboard-header">
        <h2><i data-lucide="settings" class="lucide-icon"></i> Edit Profile</h2>
        <p>Update your account details.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success">Profile updated successfully.</div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Profile Photo</label>
                <div style="display:flex;align-items:center;gap:1.2rem">
                    <?php if ($me['avatar']): ?>
                        <img src="<?= e($me['avatar']) ?>" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--gold)">
                    <?php else: ?>
                        <span class="nav-avatar" style="width:72px;height:72px;font-size:1.8rem"><?= e(mb_substr($me['name'], 0, 1)) ?></span>
                    <?php endif; ?>
                    <input type="file" name="avatar" class="form-control" accept="image/jpeg,image/png,image/webp" style="flex:1">
                </div>
                <div class="form-hint">JPG, PNG, or WEBP. Max 5MB. Square images look best.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?= e($me['name']) ?>" required>
            </div>

            <div class="form-group">
                <label class="form-label">Display Name <span style="font-weight:400;font-size:.78rem;color:var(--text-light)">(optional)</span></label>
                <input type="text" name="display_name" class="form-control" placeholder="e.g. Ahmad K." value="<?= e($me['display_name'] ?? '') ?>" maxlength="100">
                <div class="form-hint">Shown instead of your full name in class chat, reviews, and as an instructor — your full name is never shown to other students/teachers unless you choose to.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" class="form-control" value="<?= e($me['email']) ?>" disabled>
                <div class="form-hint">Email cannot be changed here. Contact support if you need to update it.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Country</label>
                <select name="country" id="countrySelect" class="form-control" onchange="updateDialCode()" required>
                    <option value="">Select country</option>
                    <?php foreach (['Pakistan','India','Bangladesh','Saudi Arabia','United Arab Emirates','Qatar','Kuwait','Bahrain','Oman','Turkey','Egypt','Indonesia','Malaysia','Afghanistan','Iran','Iraq','Jordan','Lebanon','Morocco','Tunisia','Algeria','Nigeria','South Africa','Sri Lanka','United Kingdom','United States','Canada','Australia','Germany','France','Other'] as $c): ?>
                        <option value="<?= e($c) ?>" <?= ($me['country'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Phone Number</label>
                <div style="display:grid;grid-template-columns:90px 1fr;gap:.6rem">
                    <input type="text" id="dialCode" name="dial_code" class="form-control" value="<?= e($dialCode) ?>">
                    <input type="text" name="phone_number" class="form-control" maxlength="10" inputmode="numeric" oninput="cleanPhoneInput(this)" value="<?= e($phoneNumber) ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Bio</label>
                <textarea name="bio" class="form-control" placeholder="A little about yourself..."><?= e($me['bio'] ?? '') ?></textarea>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:1.2rem 0">
            <h3 style="font-size:1rem;margin-bottom:.8rem">More About You <span style="font-weight:400;font-size:.78rem;color:var(--text-light)">(optional)</span></h3>

            <div class="form-group">
                <label class="form-label">Gender</label>
                <div class="gender-pill-row">
                    <?php foreach (['male' => 'Male', 'female' => 'Female', 'unspecified' => 'Prefer not to say'] as $val => $label): ?>
                    <label class="gender-pill">
                        <input type="radio" name="gender" value="<?= $val ?>" <?= ($me['gender'] ?? 'unspecified') === $val ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($me['date_of_birth'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Preferred Language</label>
                    <select name="preferred_language" class="form-control">
                        <option value="">Select language</option>
                        <?php foreach (['English','Arabic','Urdu','Persian/Farsi','Turkish','Indonesian/Malay','French','Other'] as $l): ?>
                            <option value="<?= e($l) ?>" <?= ($me['preferred_language'] ?? '') === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (in_array($me['role'], ['student', 'parent'], true)): ?>
            <div class="form-group">
                <label class="form-label">Education Level</label>
                <select name="education_level" class="form-control">
                    <option value="">Select level</option>
                    <?php foreach (['Primary School','Secondary / High School','Undergraduate','Graduate','Postgraduate','Other'] as $lvl): ?>
                        <option value="<?= e($lvl) ?>" <?= ($me['education_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= e($lvl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <p style="font-size:.85rem"><a href="personalize.php"><i data-lucide="sparkles" class="lucide-icon"></i> Set your learning interests</a> — helps us recommend courses for you.</p>

            <?php if (isApprovedTeacher($me)): ?>
            <div class="form-group">
                <label class="form-label">Professional Headline</label>
                <input type="text" name="headline" class="form-control" placeholder="e.g. Developer and Lead Instructor" maxlength="150" value="<?= e($me['headline'] ?? '') ?>">
                <div class="form-hint">Shown under your name on your courses — like "Dr. Angela Yu, Developer and Lead Instructor."</div>
            </div>
            <div class="form-group">
                <label class="form-label">Teaching Qualification</label>
                <textarea name="qualification" class="form-control"><?= e($me['qualification'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label class="form-label">Skills</label>
                <div id="skillChips" style="display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.5rem"></div>
                <input type="text" id="skillInput" class="form-control" list="skillSuggestions" placeholder="Type a skill and press Enter (e.g. Public Speaking, Arabic Grammar)">
                <datalist id="skillSuggestions">
                    <?php foreach ($allSkills as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                </datalist>
                <input type="hidden" name="skills_csv" id="skillsCsv" value="<?= e(implode(',', $mySkills)) ?>">
                <div class="form-hint">Skills are shared across all users — start typing to reuse an existing one, or add a new one.</div>
            </div>

            <hr style="border:none;border-top:1px solid var(--border);margin:1.2rem 0">
            <h3 style="font-size:1rem;margin-bottom:.8rem">Change Password (optional)</h3>

            <div class="form-group">
                <label class="form-label">Current Password</label>
                <input type="password" name="current_password" class="form-control" placeholder="Required only if changing password">
            </div>
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" placeholder="Leave blank to keep current password">
            </div>

            <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
        </form>
    </div></div>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
