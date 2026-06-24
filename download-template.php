<?php
require_once __DIR__ . '/db.php';
requireRole('teacher');

$type = $_GET['type'] ?? '';

// Example subject names are looked up live rather than hardcoded, so the
// template never goes stale (and fails its own validation) if an admin
// later renames or removes a subject from the taxonomy.
$allSubjects = $pdo->query('SELECT name FROM subjects ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
$findSubject = function (string $needle, array $pool, string $fallback) {
    foreach ($pool as $name) {
        if (mb_stripos($name, $needle) !== false) return $name;
    }
    return $fallback;
};
$compSciSubject = $findSubject('computer', $allSubjects, $allSubjects[0] ?? '');
$arabicSubject  = $findSubject('arabic', $allSubjects, $allSubjects[1] ?? ($allSubjects[0] ?? ''));

$templates = [
    'courses' => [
        'filename' => 'bab-ul-ilm-courses-template.csv',
        'header' => ['title', 'description', 'subject', 'level', 'language', 'price', 'learning_objectives', 'requirements'],
        'rows' => [
            ['Introduction to Python Programming', 'A beginner-friendly course covering Python syntax, data types, loops, functions, and simple projects.', $compSciSubject, 'beginner', 'English', '0', 'Write basic Python scripts; Understand variables, loops, and functions; Build a small command-line project', 'A computer with internet access. No prior programming experience needed.'],
            ['Advanced Arabic Grammar (Nahw)', 'An in-depth study of Arabic sentence structure for students who have completed basic Arabic.', $arabicSubject, 'advanced', 'English', '25', 'Parse complex Arabic sentences; Identify case endings; Read classical texts with confidence', 'Completion of a beginner Arabic course'],
        ],
    ],
    'lessons' => [
        'filename' => 'bab-ul-ilm-lessons-template.csv',
        'header' => ['section_title', 'title', 'content', 'video_url', 'duration_minutes'],
        'rows' => [
            ['Week 1', 'What is Python?', "Python is a high-level, general-purpose programming language...\n\nIn this lesson we cover why Python is popular, how to install it, and how to run your first script.", 'https://www.youtube.com/embed/VIDEO_ID', '15'],
            ['Week 1', 'Variables and Data Types', 'In this lesson, we explore how Python stores data using variables, and the core data types: strings, integers, floats, and booleans.', '', '20'],
        ],
    ],
    'quizzes' => [
        'filename' => 'bab-ul-ilm-quiz-template.csv',
        'header' => ['quiz_title', 'passing_score', 'question', 'option_1', 'option_2', 'option_3', 'option_4', 'correct_option'],
        'rows' => [
            ['Week 1 Quiz', '70', 'What does "print()" do in Python?', 'Deletes a variable', 'Displays output to the screen', 'Creates a loop', 'Imports a library', '2'],
            ['Week 1 Quiz', '70', 'Which of these is a valid Python variable name?', '2name', 'my-name', 'my_name', 'my name', '3'],
        ],
    ],
    'assignments' => [
        'filename' => 'bab-ul-ilm-assignments-template.csv',
        'header' => ['title', 'description', 'due_date'],
        'rows' => [
            ['Build a Simple Calculator', 'Write a Python script that takes two numbers and an operator (+, -, *, /) from the user and prints the result. Submit your .py file or paste your code.', '2026-08-01'],
        ],
    ],
];

if (!isset($templates[$type])) {
    http_response_code(404);
    die('Unknown template type.');
}

$t = $templates[$type];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $t['filename'] . '"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens non-ASCII text (Arabic/Urdu/Persian course content) correctly

$out = fopen('php://output', 'w');
fputcsv($out, $t['header']);
foreach ($t['rows'] as $row) {
    fputcsv($out, $row);
}
fclose($out);
