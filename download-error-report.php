<?php
require_once __DIR__ . '/db.php';
requireAuth();
if (!in_array(auth()['role'] ?? '', ['teacher', 'admin', 'customer_service'], true)) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method not allowed.');
}

$reportType = preg_replace('/[^a-z]/', '', $_POST['report_type'] ?? 'data');
$header = json_decode($_POST['header'] ?? '[]', true);
$rows   = json_decode($_POST['rows'] ?? '[]', true);
$errors = json_decode($_POST['errors'] ?? '[]', true);

if (!is_array($header) || !is_array($rows) || !is_array($errors)) {
    http_response_code(400);
    die('Malformed report data.');
}

$csv = buildAnnotatedCsv($header, $rows, $errors);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $reportType . '-errors.csv"');
echo "\xEF\xBB\xBF" . $csv;
