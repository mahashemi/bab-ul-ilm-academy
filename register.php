<?php
require_once __DIR__ . '/db.php';

if (auth()) redirect('dashboard.php');

$errors = [];
$role = $_POST['role'] ?? 'student';
if (!in_array($role, ['student', 'teacher', 'parent', 'institution'], true)) $role = 'student';

$fieldsOfStudy = $pdo->query('SELECT * FROM fields_of_study ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name           = trim($_POST['name'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $gender         = $_POST['gender'] ?? 'unspecified';
    $country        = trim($_POST['country'] ?? '');
    $dialCode       = trim($_POST['dial_code'] ?? '');
    $phoneDigits    = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
    $preferredLang  = trim($_POST['preferred_language'] ?? '');
    $dob            = trim($_POST['date_of_birth'] ?? '');
    $educationLevel = trim($_POST['education_level'] ?? '');
    $qualification  = trim($_POST['qualification'] ?? '');
    $organization   = trim($_POST['organization_name'] ?? '');
    $fieldIds       = array_filter(array_map('intval', $_POST['learning_field_ids'] ?? []));
    $agreed         = isset($_POST['agree_terms']);

    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';

    if (mb_strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain both letters and numbers.';
    if ($password !== $passwordConfirm) $errors[] = 'Passwords do not match.';

    if (!in_array($gender, ['male', 'female', 'unspecified'], true)) $gender = 'unspecified';
    if ($country === '') $errors[] = 'Please select your country.';
    if ($phoneDigits !== '' || $dialCode !== '') {
        if (!preg_match('/^\+\d{1,4}$/', $dialCode)) $errors[] = 'Please select a valid country code.';
        if (!preg_match('/^\d{10}$/', $phoneDigits)) $errors[] = 'Phone number must be exactly 10 digits (without the leading 0 or country code).';
    }
    $phone = $phoneDigits !== '' ? $dialCode . ' ' . $phoneDigits : '';

    if ($dob !== '') {
        $dobTime = strtotime($dob);
        if (!$dobTime || $dobTime > time()) $errors[] = 'Please enter a valid date of birth.';
    }

    if ($role === 'teacher' && mb_strlen($qualification) < 5) $errors[] = 'Please describe your teaching qualification (min 5 characters).';
    if ($role === 'institution' && mb_strlen($organization) < 2) $errors[] = 'Please enter your institution\'s name.';
    if (!$agreed) $errors[] = 'Please confirm you agree to the community guidelines to continue.';

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'An account with this email already exists.';
    }

    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $token = generateVerificationToken();
        $stmt = $pdo->prepare(
            'INSERT INTO users (name, email, password, role, gender, country, phone, preferred_language, date_of_birth,
                                 education_level, qualification, organization_name, verification_token, verification_expires)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
        );
        $stmt->execute([
            $name, $email, $hash, $role, $gender, $country, $phone,
            $preferredLang ?: null, $dob ?: null,
            in_array($role, ['student', 'parent'], true) ? ($educationLevel ?: null) : null,
            $role === 'teacher' ? $qualification : null,
            $role === 'institution' ? $organization : null,
            $token,
        ]);
        $newUserId = (int) $pdo->lastInsertId();

        if ($fieldIds) {
            $values = implode(',', array_fill(0, count($fieldIds), '(?, ?)'));
            $params = [];
            foreach ($fieldIds as $fid) { $params[] = $newUserId; $params[] = $fid; }
            $pdo->prepare("INSERT INTO user_learning_fields (user_id, field_of_study_id) VALUES $values")->execute($params);
        }

        sendVerificationEmail($email, $name, $token);
        $devParam = DEV_SHOW_VERIFY_LINK ? '&token=' . $token : '';
        redirect('verify-pending.php?email=' . urlencode($email) . $devParam);
    }
}

$ROLES = [
    'student'     => ['icon' => 'graduation-cap', 'label' => 'Student',     'desc' => 'I want to learn'],
    'teacher'     => ['icon' => 'book-open',       'label' => 'Teacher',    'desc' => 'I want to teach'],
    'parent'      => ['icon' => 'users',           'label' => 'Parent',     'desc' => 'I support a learner'],
    'institution' => ['icon' => 'landmark',        'label' => 'Institution','desc' => 'We teach as an org'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — <?= e(SITE_NAME) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%95%8C%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="auth-wrap">
    <div class="auth-box" style="max-width:640px">
        <div class="auth-logo">
            <h2><i data-lucide="landmark" class="lucide-icon"></i> <?= e(SITE_NAME) ?></h2>
            <p><?= e(SITE_TAGLINE) ?></p>
            <p style="font-size:1rem;font-weight:600;color:var(--green-mid);margin-top:.3rem"><?= e(SITE_AFFILIATION) ?></p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" autocomplete="off" id="regForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="role" id="roleInput" value="<?= e($role) ?>">

            <h3 class="reg-section-title">I am joining as a...</h3>
            <div class="role-card-grid">
                <?php foreach ($ROLES as $key => $r): ?>
                <div class="role-card <?= $role === $key ? 'active' : '' ?>" id="role-<?= e($key) ?>" onclick="setRole('<?= e($key) ?>')">
                    <?= catIcon($r['icon']) ?>
                    <div class="role-card-label"><?= e($r['label']) ?></div>
                    <div class="role-card-desc"><?= e($r['desc']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <h3 class="reg-section-title">Account Details</h3>
            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. Ahmad Hassan" value="<?= e($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required>
                <div class="form-hint">We'll send a verification link here before your account is active.</div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div style="position:relative">
                        <input type="password" name="password" id="pwInput" class="form-control" placeholder="At least 8 characters" oninput="checkStrength()" required>
                        <button type="button" onclick="togglePw('pwInput',this)" class="pw-toggle" aria-label="Show password"><i data-lucide="eye" class="lucide-icon"></i></button>
                    </div>
                    <div class="pw-strength-track"><div class="pw-strength-fill" id="pwStrengthFill"></div></div>
                    <div class="form-hint" id="pwStrengthLabel">Use 8+ characters with letters and numbers.</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Confirm Password</label>
                    <div style="position:relative">
                        <input type="password" name="password_confirm" id="pwConfirm" class="form-control" placeholder="Re-enter password" required>
                        <button type="button" onclick="togglePw('pwConfirm',this)" class="pw-toggle" aria-label="Show password"><i data-lucide="eye" class="lucide-icon"></i></button>
                    </div>
                </div>
            </div>

            <h3 class="reg-section-title">Profile Details</h3>
            <div class="form-group">
                <label class="form-label">Gender</label>
                <div class="gender-pill-row">
                    <?php foreach (['male' => 'Male', 'female' => 'Female', 'unspecified' => 'Prefer not to say'] as $val => $label): ?>
                    <label class="gender-pill">
                        <input type="radio" name="gender" value="<?= $val ?>" <?= ($_POST['gender'] ?? 'unspecified') === $val ? 'checked' : '' ?>>
                        <span><?= e($label) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Country</label>
                    <select name="country" id="countrySelect" class="form-control" onchange="updateDialCode()" required>
                        <option value="">Select country</option>
                        <?php foreach (['Pakistan','India','Bangladesh','Saudi Arabia','United Arab Emirates','Qatar','Kuwait','Bahrain','Oman','Turkey','Egypt','Indonesia','Malaysia','Afghanistan','Iran','Iraq','Jordan','Lebanon','Morocco','Tunisia','Algeria','Nigeria','South Africa','Sri Lanka','United Kingdom','United States','Canada','Australia','Germany','France','Other'] as $c): ?>
                            <option value="<?= e($c) ?>" <?= ($_POST['country'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Preferred Language</label>
                    <select name="preferred_language" class="form-control">
                        <option value="">Select language</option>
                        <?php foreach (['English','Arabic','Urdu','Persian/Farsi','Turkish','Indonesian/Malay','French','Other'] as $l): ?>
                            <option value="<?= e($l) ?>" <?= ($_POST['preferred_language'] ?? '') === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Phone Number (optional)</label>
                <div style="display:grid;grid-template-columns:90px 1fr;gap:.6rem">
                    <input type="text" id="dialCode" name="dial_code" class="form-control" placeholder="+92" value="<?= e($_POST['dial_code'] ?? '') ?>">
                    <input type="text" name="phone_number" class="form-control" placeholder="3001234567" maxlength="10" inputmode="numeric" oninput="cleanPhoneInput(this)" value="<?= e($_POST['phone_number'] ?? '') ?>">
                </div>
                <div class="form-hint">Select your country above to auto-fill the code, then enter your 10-digit number without the leading 0.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Date of Birth (optional)</label>
                <input type="date" name="date_of_birth" class="form-control" max="<?= date('Y-m-d') ?>" value="<?= e($_POST['date_of_birth'] ?? '') ?>">
            </div>

            <div id="studentParentFields" style="display:<?= in_array($role, ['student','parent'], true) ? 'block' : 'none' ?>">
                <div class="form-group">
                    <label class="form-label">Education Level</label>
                    <select name="education_level" class="form-control">
                        <option value="">Select level</option>
                        <?php foreach (['Primary School','Secondary / High School','Undergraduate','Graduate','Postgraduate','Other'] as $lvl): ?>
                            <option value="<?= e($lvl) ?>" <?= ($_POST['education_level'] ?? '') === $lvl ? 'selected' : '' ?>><?= e($lvl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="teacherFields" style="display:<?= $role === 'teacher' ? 'block' : 'none' ?>">
                <div class="form-group">
                    <label class="form-label">Teaching Qualification</label>
                    <textarea name="qualification" class="form-control" placeholder="e.g. MA Islamic Studies, Hafiz-ul-Quran, 5 years teaching Tajweed"><?= e($_POST['qualification'] ?? '') ?></textarea>
                </div>
            </div>

            <div id="institutionFields" style="display:<?= $role === 'institution' ? 'block' : 'none' ?>">
                <div class="form-group">
                    <label class="form-label">Institution Name</label>
                    <input type="text" name="organization_name" class="form-control" placeholder="e.g. Al-Huda Islamic Center" value="<?= e($_POST['organization_name'] ?? '') ?>">
                </div>
            </div>

            <h3 class="reg-section-title">Learning Interests <span style="font-weight:400;font-size:.78rem;color:var(--text-light)">(optional — helps us recommend courses)</span></h3>
            <div class="form-group">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.6rem">
                    <?php foreach ($fieldsOfStudy as $f): ?>
                    <label class="card" style="cursor:pointer;text-align:center;padding:.9rem .5rem;margin:0">
                        <input type="checkbox" name="learning_field_ids[]" value="<?= (int) $f['id'] ?>" style="width:auto;margin-bottom:.4rem">
                        <div style="font-size:1.3rem;color:var(--green-deep)"><?= catIcon($f['icon']) ?></div>
                        <div style="font-weight:600;font-size:.8rem;margin-top:.25rem"><?= e($f['name']) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <label style="display:flex;align-items:flex-start;gap:.6rem;cursor:pointer;margin:1.2rem 0;font-size:.85rem;color:var(--text-mid)">
                <input type="checkbox" name="agree_terms" value="1" style="width:auto;margin-top:.2rem" required>
                <span>I confirm the information above is accurate and I agree to <?= e(SITE_NAME) ?>'s community guidelines.</span>
            </label>

            <button type="submit" class="btn btn-primary btn-full">Create My Account</button>
        </form>

        <p style="text-align:center;margin-top:1.2rem;font-size:.88rem;color:var(--text-light)">
            Already have an account? <a href="login.php">Log in</a>
        </p>
    </div>
</div>
<script>
function setRole(r) {
    document.getElementById('roleInput').value = r;
    document.querySelectorAll('.role-card').forEach(function (el) {
        el.classList.toggle('active', el.id === 'role-' + r);
    });
    document.getElementById('teacherFields').style.display = (r === 'teacher') ? 'block' : 'none';
    document.getElementById('institutionFields').style.display = (r === 'institution') ? 'block' : 'none';
    document.getElementById('studentParentFields').style.display = (r === 'student' || r === 'parent') ? 'block' : 'none';
}
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
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.innerHTML = '<i data-lucide="' + (showing ? 'eye' : 'eye-off') + '" class="lucide-icon"></i>';
    if (window.lucide) lucide.createIcons();
}
function checkStrength() {
    const val = document.getElementById('pwInput').value;
    const fill = document.getElementById('pwStrengthFill');
    const label = document.getElementById('pwStrengthLabel');
    let score = 0;
    if (val.length >= 8) score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%', c:'#ccc', t:'Use 8+ characters with letters and numbers.'},
        {w:'20%', c:'#e53935', t:'Very weak'},
        {w:'40%', c:'#fb8c00', t:'Weak'},
        {w:'60%', c:'#fdd835', t:'Fair'},
        {w:'80%', c:'#7cb342', t:'Strong'},
        {w:'100%', c:'#2e7d32', t:'Very strong'},
    ];
    const lv = val.length === 0 ? levels[0] : levels[Math.min(score, 5)];
    fill.style.width = lv.w;
    fill.style.background = lv.c;
    label.textContent = lv.t;
}
</script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="app.js" defer></script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
