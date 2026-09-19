<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';

$admin = require_admin();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0);
        $v = new Validator($_POST);
        $v->required('department_id', 'Department')->required('level_id', 'Level')
          ->required('semester_id', 'Semester')->required('code', 'Course code')
          ->required('title', 'Title')->maxLength('code', 30, 'Course code');

        // Never trust a submitted university_id — derive it from the
        // department's real faculty/university chain server-side so a
        // course can never be attached to a mismatched hierarchy.
        $university = null;
        if ($v->passes()) {
            $stmt = $db->prepare(
                'SELECT f.university_id FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE d.id = ?'
            );
            $stmt->execute([(int) $_POST['department_id']]);
            $university = $stmt->fetchColumn();
            if (!$university) {
                flash_set('error', 'Selected department is invalid.');
            }
            // Guard: the chosen level must belong to the same department.
            $levelCheck = $db->prepare('SELECT COUNT(*) FROM levels WHERE id = ? AND department_id = ?');
            $levelCheck->execute([(int) $_POST['level_id'], (int) $_POST['department_id']]);
            if ((int) $levelCheck->fetchColumn() === 0) {
                flash_set('error', 'The selected level does not belong to the selected department.');
                $university = null;
            }
        }

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } elseif ($university) {
            $data = [
                'university_id' => (int) $university,
                'department_id' => (int) $_POST['department_id'],
                'level_id' => (int) $_POST['level_id'],
                'semester_id' => (int) $_POST['semester_id'],
                'code' => strtoupper(trim($_POST['code'])),
                'title' => trim($_POST['title']),
                'description' => trim($_POST['description'] ?? ''),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            try {
                if ($id > 0) {
                    $stmt = $db->prepare(
                        'UPDATE courses SET university_id=:university_id, department_id=:department_id, level_id=:level_id,
                         semester_id=:semester_id, code=:code, title=:title, description=:description, status=:status
                         WHERE id=:id'
                    );
                    $stmt->execute($data + ['id' => $id]);
                    flash_set('success', 'Course updated.');
                } else {
                    $stmt = $db->prepare(
                        'INSERT INTO courses (university_id, department_id, level_id, semester_id, code, title, description, status)
                         VALUES (:university_id,:department_id,:level_id,:semester_id,:code,:title,:description,:status)'
                    );
                    $stmt->execute($data);
                    flash_set('success', 'Course created.');
                }
                log_audit($admin['id'], $id > 0 ? 'update' : 'create', 'course', $id ?: (int) $db->lastInsertId());
            } catch (PDOException $e) {
                flash_set('error', str_contains($e->getMessage(), 'uq_courses_scope_code')
                    ? 'That course code already exists for this department.'
                    : 'Could not save the course.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM documents WHERE course_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: documents exist for this course. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM courses WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'course', $id);
            flash_set('success', 'Course deleted.');
        }
    }
    redirect('admin/courses.php');
}

$editing = null;
$editingChain = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
    if ($editing) {
        $chainStmt = $db->prepare('SELECT f.id AS faculty_id FROM departments d JOIN faculties f ON f.id = d.faculty_id WHERE d.id = ?');
        $chainStmt->execute([$editing['department_id']]);
        $editingChain = $chainStmt->fetch();
    }
}

// Build the hierarchy tree for the cascading dropdowns.
$tree = ['universities' => []];
$universities = $db->query('SELECT id, name FROM universities ORDER BY name')->fetchAll();
$faculties = $db->query('SELECT id, university_id, name FROM faculties ORDER BY name')->fetchAll();
$departments = $db->query('SELECT id, faculty_id, name FROM departments ORDER BY name')->fetchAll();
$levelsAll = $db->query('SELECT id, department_id, name FROM levels ORDER BY code + 0')->fetchAll();

foreach ($universities as $u) {
    $uniNode = ['id' => (int) $u['id'], 'name' => $u['name'], 'faculties' => []];
    foreach ($faculties as $f) {
        if ((int) $f['university_id'] !== (int) $u['id']) {
            continue;
        }
        $facNode = ['id' => (int) $f['id'], 'name' => $f['name'], 'departments' => []];
        foreach ($departments as $d) {
            if ((int) $d['faculty_id'] !== (int) $f['id']) {
                continue;
            }
            $deptNode = ['id' => (int) $d['id'], 'name' => $d['name'], 'levels' => []];
            foreach ($levelsAll as $lv) {
                if ((int) $lv['department_id'] === (int) $d['id']) {
                    $deptNode['levels'][] = ['id' => (int) $lv['id'], 'name' => $lv['name']];
                }
            }
            $facNode['departments'][] = $deptNode;
        }
        $uniNode['faculties'][] = $facNode;
    }
    $tree['universities'][] = $uniNode;
}

$semesters = $db->query("SELECT * FROM semesters WHERE status = 'active' ORDER BY name")->fetchAll();

$courses = $db->query(
    "SELECT c.*, d.name AS department_name, lv.name AS level_name, sm.name AS semester_name,
            (SELECT COUNT(*) FROM documents doc WHERE doc.course_id = c.id) AS document_count
     FROM courses c
     JOIN departments d ON d.id = c.department_id
     JOIN levels lv ON lv.id = c.level_id
     JOIN semesters sm ON sm.id = c.semester_id
     ORDER BY c.created_at DESC"
)->fetchAll();

$pageTitle = 'Courses';
require __DIR__ . '/../includes/partials/admin_header.php';
?>
<script>window.NB_HIERARCHY = <?= json_encode($tree, JSON_UNESCAPED_SLASHES) ?>;</script>

<div style="display:grid; grid-template-columns: 1fr 360px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Courses</h3></div>
    <?php if (!$courses): ?>
      <div class="nb-empty"><h3>No courses yet</h3><p>Add your first course using the form on the right.</p></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Code</th><th>Title</th><th>Department</th><th>Level</th><th>Semester</th><th>Docs</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($courses as $c): ?>
        <tr>
          <td class="nb-mono"><?= e($c['code']) ?></td>
          <td><?= e($c['title']) ?></td>
          <td><?= e($c['department_name']) ?></td>
          <td><?= e($c['level_name']) ?></td>
          <td><?= e($c['semester_name']) ?></td>
          <td><?= (int) $c['document_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $c['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($c['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $c['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit Course' : 'Add Course' ?></h3>
    <?php if (!$universities): ?>
      <p class="nb-hint">Build the academic structure (university → faculty → department → level) before adding courses.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

      <div class="nb-field">
        <label class="nb-label" for="nbUniversity">University</label>
        <select class="nb-select" id="nbUniversity">
          <option value="">Select university</option>
          <?php foreach ($universities as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($editing['university_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="nbFaculty">Faculty</label>
        <select class="nb-select" id="nbFaculty" data-selected="<?= (int) ($editingChain['faculty_id'] ?? 0) ?>" disabled>
          <option value="">Select university first</option>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="nbDepartment">Department</label>
        <select class="nb-select" id="nbDepartment" name="department_id" data-selected="<?= (int) ($editing['department_id'] ?? 0) ?>" required disabled>
          <option value="">Select faculty first</option>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="nbLevel">Level</label>
        <select class="nb-select" id="nbLevel" name="level_id" data-selected="<?= (int) ($editing['level_id'] ?? 0) ?>" required disabled>
          <option value="">Select department first</option>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="semester_id">Semester</label>
        <select class="nb-select" id="semester_id" name="semester_id" required>
          <option value="">Select semester</option>
          <?php foreach ($semesters as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (int) ($editing['semester_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="code">Course Code</label>
        <input class="nb-input" id="code" name="code" required maxlength="30" placeholder="e.g. CSC301" value="<?= e($editing['code'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="title">Title</label>
        <input class="nb-input" id="title" name="title" required value="<?= e($editing['title'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="description">Description</label>
        <textarea class="nb-textarea" id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="status">Status</label>
        <select class="nb-select" id="status" name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add Course' ?></button>
      <?php if ($editing): ?>
        <a href="courses.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this course?" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete Course</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
