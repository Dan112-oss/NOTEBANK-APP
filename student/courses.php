<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$facultyId = (int) ($_GET['faculty_id'] ?? 0);
$departmentId = (int) ($_GET['department_id'] ?? 0);
$levelId = (int) ($_GET['level_id'] ?? 0);
$semesterId = (int) ($_GET['semester_id'] ?? 0);
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT c.*, d.name AS department_name, lv.name AS level_name, sm.name AS semester_name,
        (SELECT COUNT(*) FROM documents doc WHERE doc.course_id = c.id AND doc.status = 'ACTIVE') AS document_count
        FROM courses c
        JOIN departments d ON d.id = c.department_id
        JOIN faculties f ON f.id = d.faculty_id
        JOIN levels lv ON lv.id = c.level_id
        JOIN semesters sm ON sm.id = c.semester_id
        WHERE c.status = 'active' AND c.university_id = :university_id";
$params = ['university_id' => $student['university_id']];

if ($facultyId) { $sql .= ' AND f.id = :faculty_id'; $params['faculty_id'] = $facultyId; }
if ($departmentId) { $sql .= ' AND c.department_id = :department_id'; $params['department_id'] = $departmentId; }
if ($levelId) { $sql .= ' AND c.level_id = :level_id'; $params['level_id'] = $levelId; }
if ($semesterId) { $sql .= ' AND c.semester_id = :semester_id'; $params['semester_id'] = $semesterId; }
if ($search !== '') {
    $sql .= ' AND (c.title LIKE :q1 OR c.code LIKE :q2)';
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
}
$sql .= ' ORDER BY c.code LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll();

$faculties = $db->prepare("SELECT id, name FROM faculties WHERE university_id = ? AND status='active' ORDER BY name");
$faculties->execute([$student['university_id']]);
$faculties = $faculties->fetchAll();

$departments = $db->query("SELECT id, name, faculty_id FROM departments WHERE status='active' ORDER BY name")->fetchAll();
$levels = $db->query("SELECT id, name, department_id FROM levels WHERE status='active' ORDER BY code + 0")->fetchAll();
$semesters = $db->query("SELECT id, name FROM semesters WHERE status='active' ORDER BY name")->fetchAll();

$pageTitle = 'Browse Courses';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<div class="nb-page-head">
  <h1>Browse Courses</h1>
  <p class="nb-subtitle">Find notes and past questions for your courses.</p>
</div>

<form method="get" class="nb-catalog-filters">
  <div class="nb-field">
    <label class="nb-label">Search</label>
    <input class="nb-input" type="search" name="q" placeholder="Course code or title" value="<?= e($search) ?>">
  </div>
  <div class="nb-field">
    <label class="nb-label">Faculty</label>
    <select class="nb-select" name="faculty_id">
      <option value="">All faculties</option>
      <?php foreach ($faculties as $f): ?>
        <option value="<?= (int) $f['id'] ?>" <?= $facultyId === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="nb-field">
    <label class="nb-label">Department</label>
    <select class="nb-select" name="department_id">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= (int) $d['id'] ?>" <?= $departmentId === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="nb-field">
    <label class="nb-label">Level</label>
    <select class="nb-select" name="level_id">
      <option value="">All levels</option>
      <?php foreach ($levels as $lv): ?>
        <option value="<?= (int) $lv['id'] ?>" <?= $levelId === (int) $lv['id'] ? 'selected' : '' ?>><?= e($lv['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="nb-field">
    <label class="nb-label">Semester</label>
    <select class="nb-select" name="semester_id">
      <option value="">All semesters</option>
      <?php foreach ($semesters as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $semesterId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="nb-field">
    <button type="submit" class="nb-btn nb-btn--primary nb-btn--block">Apply Filters</button>
  </div>
</form>

<?php if (!$courses): ?>
  <div class="nb-empty">
    <h3>No courses match those filters</h3>
    <p>Try clearing a filter, or check back once your department's catalogue grows.</p>
  </div>
<?php else: ?>
<div class="nb-doc-grid">
  <?php foreach ($courses as $c): ?>
    <a href="course.php?id=<?= (int) $c['id'] ?>" class="nb-doc-card">
      <span class="nb-doc-card__type nb-doc-card__type--note nb-mono"><?= e($c['code']) ?></span>
      <div class="nb-doc-card__title"><?= e($c['title']) ?></div>
      <div class="nb-doc-card__meta"><?= e($c['department_name']) ?> · <?= e($c['level_name']) ?> · <?= e($c['semester_name']) ?></div>
      <div class="nb-doc-card__footer">
        <span class="nb-hint"><?= (int) $c['document_count'] ?> document<?= (int) $c['document_count'] === 1 ? '' : 's' ?></span>
        <span class="nb-btn nb-btn--ghost nb-btn--sm">View →</span>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
