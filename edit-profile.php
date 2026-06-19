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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $name          = trim($_POST['name'] ?? '');
    $country       = trim($_POST['country'] ?? '');
    $bio           = trim($_POST['bio'] ?? '');
    $qualification = trim($_POST['qualification'] ?? '');
    $dialCodeIn    = trim($_POST['dial_code'] ?? '');
    $phoneDigits   = preg_replace('/\D/', '', $_POST['phone_number'] ?? '');
    $currentPass   = $_POST['current_password'] ?? '';
    $newPass       = $_POST['new_password'] ?? '';

    if ($name === '' || mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if ($country === '') $errors[] = 'Please select your country.';
    if ($phoneDigits !== '' || $dialCodeIn !== '') {
        if (!preg_match('/^\+\d{1,4}$/', $dialCodeIn)) $errors[] = 'Please select a valid country code.';
        if (!preg_match('/^\d{10}$/', $phoneDigits)) $errors[] = 'Phone number must be exactly 10 digits (without the leading 0 or country code).';
    }
    $phone = $phoneDigits !== '' ? $dialCodeIn . ' ' . $phoneDigits : '';

    if ($me['role'] === 'teacher' && mb_strlen($qualification) < 5) $errors[] = 'Please describe your teaching qualification (min 5 characters).';

    if ($newPass !== '') {
        if (!password_verify($currentPass, $me['password'])) $errors[] = 'Current password is incorrect.';
        if (mb_strlen($newPass) < 6) $errors[] = 'New password must be at least 6 characters.';
    }

    if (!$errors) {
        $qualToSave = $me['role'] === 'teacher' ? $qualification : $me['qualification'];
        if ($newPass !== '') {
            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET name=?, country=?, bio=?, phone=?, qualification=?, password=? WHERE id=?')
                ->execute([$name, $country, $bio, $phone, $qualToSave, $hash, $user['id']]);
        } else {
            $pdo->prepare('UPDATE users SET name=?, country=?, bio=?, phone=?, qualification=? WHERE id=?')
                ->execute([$name, $country, $bio, $phone, $qualToSave, $user['id']]);
        }

        $_SESSION['user']['name'] = $name;
        $success = true;
        $me['name'] = $name; $me['country'] = $country; $me['bio'] = $bio; $me['qualification'] = $qualToSave;
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
</script>
</head>
<body>
<nav class="navbar">
    <div class="nav-brand">🕌 <?= e(SITE_NAME) ?></div>
    <div class="nav-links">
        <a href="courses.php">Courses</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="logout.php" class="nav-btn">Logout</a>
    </div>
</nav>

<div class="dashboard-wrap" style="max-width:640px">
    <div class="dashboard-header">
        <h2>⚙️ Edit Profile</h2>
        <p>Update your account details.</p>
    </div>

    <?php if ($success): ?><div class="alert alert-success">Profile updated successfully.</div><?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">

            <div class="form-group">
                <label class="form-label">Full Name</label>
                <input type="text" name="name" class="form-control" value="<?= e($me['name']) ?>" required>
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

            <?php if ($me['role'] === 'teacher'): ?>
            <div class="form-group">
                <label class="form-label">Teaching Qualification</label>
                <textarea name="qualification" class="form-control"><?= e($me['qualification'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>

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
</body>
</html>
