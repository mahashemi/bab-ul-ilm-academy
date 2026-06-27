<?php
require_once __DIR__ . '/db.php';

$lang = $_GET['lang'] ?? '';
$return = $_GET['return'] ?? 'index.php';

// Only ever redirect back to a same-site relative path -- never let this
// endpoint be used as an open redirect to an external URL.
if (!preg_match('#^/?[a-zA-Z0-9_\-./]+\.php(\?[^\s]*)?$#', $return) || str_contains($return, '://')) {
    $return = 'index.php';
}

if (in_array($lang, SUPPORTED_LOCALES, true)) {
    $_SESSION['locale'] = $lang;
    $user = auth();
    if ($user) {
        $pdo->prepare('UPDATE users SET ui_locale = ? WHERE id = ?')->execute([$lang, $user['id']]);
        $_SESSION['user']['ui_locale'] = $lang;
    }
}

redirect($return);
