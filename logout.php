<?php
require_once __DIR__ . '/db.php';
$user = auth();
if ($user) logActivity($pdo, (int) $user['id'], 'Logged out');
session_destroy();
header('Location: login.php');
exit;
