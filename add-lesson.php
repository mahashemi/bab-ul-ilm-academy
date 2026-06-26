<?php
require_once __DIR__ . '/db.php';
$teacherId = requireTeacherOrSupport();
$user = auth();

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ? AND teacher_id = ?');
$stmt->execute([$courseId, $teacherId]);
$course = $stmt->fetch();

if (!$course) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:3rem;text-align:center">Course not found or not yours. <a href="dashboard.php">Go back</a></p>');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_section'])) {
    verifyCsrf();
    $oldSection = trim($_POST['old_section'] ?? '');
    $newSection = trim($_POST['new_section'] ?? '');
    if ($newSection !== '') {
        if ($oldSection === '') {
            $pdo->prepare('UPDATE lessons SET section_title = ? WHERE course_id = ? AND (section_title IS NULL OR section_title = \'\')')
                ->execute([$newSection, $courseId]);
        } else {
            $pdo->prepare('UPDATE lessons SET section_title = ? WHERE course_id = ? AND section_title = ?')
                ->execute([$newSection, $courseId, $oldSection]);
        }
        flash('success', 'Section renamed.');
    }
    redirect('add-lesson.php?course_id=' . $courseId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_lesson'])) {
    verifyCsrf();
    $lid = (int) $_POST['move_lesson'];
    $dir = $_POST['direction'] ?? '';
    $current = $pdo->prepare('SELECT id, sort_order FROM lessons WHERE id = ? AND course_id = ?');
    $current->execute([$lid, $courseId]);
    $current = $current->fetch();
    if ($current) {
        $neighbor = $pdo->prepare(
            $dir === 'up'
                ? 'SELECT id, sort_order FROM lessons WHERE course_id = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1'
                : 'SELECT id, sort_order FROM lessons WHERE course_id = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1'
        );
        $neighbor->execute([$courseId, $current['sort_order']]);
        $neighbor = $neighbor->fetch();
        if ($neighbor) {
            $pdo->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$neighbor['sort_order'], $current['id']]);
            $pdo->prepare('UPDATE lessons SET sort_order = ? WHERE id = ?')->execute([$current['sort_order'], $neighbor['id']]);
        }
    }
    redirect('add-lesson.php?course_id=' . $courseId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'])) {
    verifyCsrf();
    $sectionTitle = trim($_POST['section_title'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $content  = sanitizeLessonHtml($_POST['content'] ?? '');
    $videoUrl = trim($_POST['video_url'] ?? '');
    $duration = (int) ($_POST['duration_minutes'] ?? 0);
    $isPreview = isset($_POST['is_preview']) ? 1 : 0;

    if (mb_strlen($title) < 3) $errors[] = 'Lesson title must be at least 3 characters.';

    $slidesUrl = null;
    $slides = handleAttachmentUpload('slides_file', 'lesson-slides');
    if ($slides) {
        if ($slides['type'] !== 'file') {
            $errors[] = 'Slides must be uploaded as a PDF file.';
        } else {
            $slidesUrl = $slides['path'];
        }
    }

    if (!$errors) {
        $maxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) m FROM lessons WHERE course_id = ?');
        $maxOrder->execute([$courseId]);
        $next = (int) $maxOrder->fetch()['m'] + 1;

        $stmt = $pdo->prepare('INSERT INTO lessons (course_id, section_title, title, content, video_url, slides_url, duration_minutes, is_preview, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$courseId, $sectionTitle ?: null, $title, $content, $videoUrl, $slidesUrl, $duration, $isPreview, $next]);
        if ($teacherId !== (int) $user['id']) {
            logActivity($pdo, $user['id'], 'Added a lesson to course #' . $courseId . ' on behalf of teacher #' . $teacherId);
        }
        flash('success', 'Lesson added!');
        redirect('add-lesson.php?course_id=' . $courseId);
    }
}

$lessons = $pdo->prepare('SELECT * FROM lessons WHERE course_id = ? ORDER BY sort_order ASC');
$lessons->execute([$courseId]);
$lessons = $lessons->fetchAll();

$existingSections = $pdo->prepare('SELECT DISTINCT section_title FROM lessons WHERE course_id = ? AND section_title IS NOT NULL AND section_title != \'\' ORDER BY sort_order ASC');
$existingSections->execute([$courseId]);
$existingSections = $existingSections->fetchAll(PDO::FETCH_COLUMN);
// Nudge teachers toward a Week-by-Week curriculum structure (Udemy-style)
// by pre-filling the next logical week number — still just a suggestion,
// editable/clearable like any other field.
$suggestedSection = 'Week ' . (count($existingSections) + 1);

// Group lessons into their sections, preserving sort order, for the tree view below.
$sections = [];
foreach ($lessons as $l) {
    $key = $l['section_title'] ?: '';
    $sections[$key][] = $l;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Curriculum — <?= e($course['title']) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="assets/favicon-16.png">
<link rel="apple-touch-icon" sizes="180x180" href="assets/icon-green-180.png">
<link rel="manifest" href="assets/site.webmanifest">
<meta name="theme-color" content="#0a3d1f">
<link rel="stylesheet" href="style.css">
<link href="https://unpkg.com/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
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
            <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php">+ New Course</a><?php endif; ?>
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
                    <?php if (($user['role'] ?? '') === 'teacher'): ?><a href="add-course.php"><i data-lucide="plus" class="lucide-icon"></i> New Course</a><?php endif; ?>
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

<div class="dashboard-wrap">
    <div class="dashboard-header"><h2><i data-lucide="clipboard-list" class="lucide-icon"></i> Curriculum — <?= e($course['title']) ?></h2><p>Create your course in sections, each focused on a single learning objective. Then add video, article, or slide content to each lecture.</p></div>

    <?= renderActingAsBanner($pdo) ?>

    <?php if (flash('success')): ?><div class="alert alert-success"><?= e(flash('success')) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-error"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

    <?php if (!$lessons): ?>
    <div class="card" style="margin-bottom:1.5rem;border-color:var(--gold)"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:.6rem"><i data-lucide="sparkles" class="lucide-icon"></i> Don't want to type out every lesson by hand?</h3>
        <p style="font-size:.88rem;margin-bottom:.8rem">Copy this prompt into ChatGPT, Claude, DeepSeek, or any AI assistant. It already knows this course's title and description — just tell it how many lessons you want, and it'll write a complete lesson plan as a CSV you can upload directly below.</p>

        <button type="button" class="curriculum-add-btn" style="margin-bottom:1rem" onclick="document.getElementById('pacingPanel').classList.toggle('open')"><i data-lucide="calendar-clock" class="lucide-icon"></i> Add a Schedule (optional)</button>
        <div id="pacingPanel" class="pacing-panel<?= lessonScheduleNote() ? ' open' : '' ?>">
            <form method="get">
                <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Number of days</label>
                        <input type="number" name="days" class="form-control" min="1" value="<?= (int) ($_GET['days'] ?? '') ?: '' ?>" placeholder="e.g. 14">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Minutes of lessons per day</label>
                        <input type="number" name="minutes_per_day" class="form-control" min="1" value="<?= (int) ($_GET['minutes_per_day'] ?? '') ?: '' ?>" placeholder="e.g. 20">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Apply to Prompt</button>
            </form>
        </div>

        <div class="ai-prompt-box">
            <pre id="lessonHelperPrompt"><?= e(renderAiPrompt($pdo, 'course_lessons', [
                'site_name' => SITE_NAME,
                'course_title' => $course['title'],
                'course_description' => $course['description'],
                'textbook' => $course['textbook'] ?: 'None specified — use your general knowledge of the subject.',
                'schedule_note' => lessonScheduleNote(),
            ])) ?></pre>
            <button type="button" class="btn btn-outline btn-sm copy-prompt-btn" data-target="lessonHelperPrompt"><i data-lucide="copy" class="lucide-icon"></i> Copy Prompt</button>
        </div>
        <a href="bulk-lessons.php?course_id=<?= (int) $courseId ?>" class="btn btn-primary btn-sm" style="margin-top:1rem"><i data-lucide="upload" class="lucide-icon"></i> Upload the Resulting CSV</a>
    </div></div>
    <?php endif; ?>

    <h3 style="margin-bottom:1rem;font-size:1.1rem;color:var(--green-deep)">Curriculum (<?= count($lessons) ?> lecture<?= count($lessons) === 1 ? '' : 's' ?>)</h3>

    <?php if (!$lessons): ?>
        <div class="card" style="margin-bottom:1.5rem"><div class="empty-state"><div class="icon"><i data-lucide="notebook-pen" class="lucide-icon"></i></div><h3>No lectures yet</h3><p>Add your first one below.</p></div></div>
    <?php else: ?>
        <?php foreach ($sections as $sectionName => $sectionLessons): ?>
        <div class="curriculum-section">
            <div class="curriculum-section-head">
                <i data-lucide="chevron-down" class="lucide-icon chevron" onclick="this.closest('.curriculum-section').classList.toggle('collapsed')"></i>
                <strong class="section-name-display" onclick="this.closest('.curriculum-section').classList.toggle('collapsed')"><?= e($sectionName ?: 'Untitled Section') ?></strong>
                <form method="post" class="section-rename-form" onclick="event.stopPropagation()" onsubmit="return this.new_section.value.trim() !== ''">
                    <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
                    <input type="hidden" name="old_section" value="<?= e($sectionName) ?>">
                    <input type="text" name="new_section" class="form-control" value="<?= e($sectionName) ?>" placeholder="Section name">
                    <button type="submit" name="rename_section" value="1" class="btn btn-primary btn-sm">Save</button>
                    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('.curriculum-section-head').classList.remove('renaming')">Cancel</button>
                </form>
                <button type="button" class="icon-btn" data-tip="Rename section" aria-label="Rename section" onclick="event.stopPropagation();this.closest('.curriculum-section-head').classList.add('renaming')"><i data-lucide="pencil" class="lucide-icon"></i></button>
                <span style="font-size:.78rem;color:var(--text-light)"><?= count($sectionLessons) ?> lecture<?= count($sectionLessons) === 1 ? '' : 's' ?></span>
            </div>
            <div class="curriculum-section-body">
                <?php foreach ($sectionLessons as $l):
                    $hasContent = $l['content'] || $l['video_url'] || $l['slides_url'];
                    $i = array_search($l, $lessons, true);
                ?>
                <div class="curriculum-lecture">
                    <div class="curriculum-lecture-check <?= $hasContent ? '' : 'empty' ?>"><i data-lucide="<?= $hasContent ? 'check' : 'file-text' ?>" class="lucide-icon" style="width:13px;height:13px"></i></div>
                    <div class="curriculum-lecture-title">
                        <a href="edit-lesson.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a>
                        <?php if ($l['video_url']): ?><i data-lucide="video" class="lucide-icon" style="width:13px;height:13px;vertical-align:-2px;color:var(--text-light)" data-tip="Has video"></i><?php endif; ?>
                        <?php if ($l['slides_url']): ?><i data-lucide="presentation" class="lucide-icon" style="width:13px;height:13px;vertical-align:-2px;color:var(--text-light)" data-tip="Has slides"></i><?php endif; ?>
                    </div>
                    <?php if ((int) $l['duration_minutes'] > 0): ?><span class="curriculum-lecture-meta"><?= (int) $l['duration_minutes'] ?> min</span><?php endif; ?>
                    <?php if ($l['is_preview']): ?><span class="badge badge-free">Preview</span><?php endif; ?>
                    <div class="action-row">
                        <?php if ($i > 0): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="direction" value="up"><button type="submit" name="move_lesson" value="<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Move up" aria-label="Move up"><i data-lucide="chevron-up" class="lucide-icon"></i></button></form>
                        <?php endif; ?>
                        <?php if ($i < count($lessons) - 1): ?>
                        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="direction" value="down"><button type="submit" name="move_lesson" value="<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Move down" aria-label="Move down"><i data-lucide="chevron-down" class="lucide-icon"></i></button></form>
                        <?php endif; ?>
                        <a href="edit-lesson.php?id=<?= (int) $l['id'] ?>" class="icon-btn" data-tip="Edit lecture" aria-label="Edit lecture"><i data-lucide="pencil" class="lucide-icon"></i></a>
                    </div>
                </div>
                <?php endforeach; ?>
                <a href="#addLectureForm" onclick="document.getElementById('sectionTitleInput').value=<?= json_encode($sectionName) ?>" class="curriculum-add-btn"><i data-lucide="plus" class="lucide-icon"></i> Curriculum item</a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <a href="#addLectureForm" onclick="document.getElementById('sectionTitleInput').value=<?= json_encode($suggestedSection) ?>" class="curriculum-add-btn" style="margin-bottom:2rem"><i data-lucide="plus" class="lucide-icon"></i> Section</a>

    <div class="card" id="addLectureForm" style="margin-bottom:1.5rem"><div class="card-body">
        <h3 style="font-size:1rem;margin-bottom:1rem"><i data-lucide="plus-circle" class="lucide-icon"></i> Add a Lecture</h3>
        <form method="post" enctype="multipart/form-data" id="lectureForm">
            <input type="hidden" name="_csrf" value="<?= e(csrf()) ?>">
            <input type="hidden" name="course_id" value="<?= (int) $courseId ?>">
            <input type="hidden" name="content" id="contentHidden">

            <div class="form-group">
                <label class="form-label">Section <span style="font-weight:400;font-size:.78rem;color:var(--text-light)">(groups lectures into a curriculum section — Week 1, Week 2... works well)</span></label>
                <input type="text" name="section_title" id="sectionTitleInput" class="form-control" list="sectionSuggestions" placeholder="e.g. Week 1: Introduction" value="<?= e($suggestedSection) ?>">
                <datalist id="sectionSuggestions">
                    <?php foreach ($existingSections as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div class="form-group">
                <label class="form-label">Lecture Title</label>
                <input type="text" name="title" class="form-control" placeholder="e.g. Introduction to Makharij" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Duration (minutes)</label>
                    <input type="number" name="duration_minutes" class="form-control" min="0" placeholder="e.g. 12">
                </div>
                <div class="form-group" style="display:flex;align-items:flex-end">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                        <input type="checkbox" name="is_preview" value="1" style="width:auto">
                        Free preview — visible without enrolling
                    </label>
                </div>
            </div>

            <div class="content-type-tabs">
                <button type="button" class="content-type-tab active" data-panel="video">Video</button>
                <button type="button" class="content-type-tab" data-panel="article">Article</button>
                <button type="button" class="content-type-tab" data-panel="slides">Slides</button>
            </div>

            <div class="content-type-panel active" data-panel="video">
                <div class="form-group">
                    <label class="form-label">Video URL (YouTube/Vimeo embed link)</label>
                    <input type="text" name="video_url" class="form-control" placeholder="https://www.youtube.com/embed/...">
                    <div class="form-hint">No ads, full control over playback — consider Vimeo over YouTube where possible.</div>
                </div>
                <div class="video-upload-placeholder">
                    <input type="file" disabled>
                    <p style="margin-top:.5rem;font-size:.82rem"><i data-lucide="upload-cloud" class="lucide-icon"></i> Direct video upload is coming soon (no more YouTube ads). For now, paste a video link above.</p>
                </div>
            </div>

            <div class="content-type-panel" data-panel="article">
                <label class="form-label">Article Text</label>
                <div id="editor"></div>
            </div>

            <div class="content-type-panel" data-panel="slides">
                <div class="form-group">
                    <label class="form-label">Slide Deck (PDF)</label>
                    <input type="file" name="slides_file" class="form-control" accept="application/pdf">
                    <div class="form-hint">Students will see a "Download Slides" link on this lecture.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top:1rem">+ Add Lecture</button>
        </form>
    </div></div>

    <p style="margin-top:1.5rem"><a href="course.php?id=<?= (int) $courseId ?>" class="btn btn-outline">View Course Page <i data-lucide="arrow-right" class="lucide-icon"></i></a></p>
</div>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<script src="https://unpkg.com/quill@2.0.2/dist/quill.js"></script>
<script src="app.js" defer></script>
<?= renderFooter($pdo) ?>
<script>
if (window.lucide) lucide.createIcons();

document.querySelectorAll('.content-type-tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('.content-type-tab').forEach(function (t) { t.classList.remove('active'); });
        document.querySelectorAll('.content-type-panel').forEach(function (p) { p.classList.remove('active'); });
        tab.classList.add('active');
        document.querySelector('.content-type-panel[data-panel="' + tab.dataset.panel + '"]').classList.add('active');
    });
});

var quill = new Quill('#editor', {
    theme: 'snow',
    placeholder: 'Write the lecture notes/article here...',
    modules: {
        toolbar: [
            [{ header: [1, 2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            ['link', 'image', 'code-block'],
            ['clean'],
        ],
    },
});
var ImageBlot = Quill.import('formats/image');
var toolbar = quill.getModule('toolbar');
toolbar.addHandler('image', function () {
    var url = window.prompt('Image URL (https://...)');
    if (url) {
        var range = quill.getSelection(true);
        quill.insertEmbed(range.index, 'image', url, 'user');
    }
});

document.getElementById('lectureForm').addEventListener('submit', function () {
    document.getElementById('contentHidden').value = quill.root.innerHTML === '<p><br></p>' ? '' : quill.root.innerHTML;
});
</script>
<script>if (window.lucide) lucide.createIcons();</script>
</body>
</html>
