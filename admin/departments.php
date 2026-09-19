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
        $v->required('faculty_id', 'Faculty')->required('name', 'Name')->required('code', 'Code');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $data = [
                'faculty_id' => (int) $_POST['faculty_id'],
                'name' => trim($_POST['name']),
                'code' => strtoupper(trim($_POST['code'])),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE departments SET faculty_id=:faculty_id, name=:name, code=:code, status=:status WHERE id=:id');
                $stmt->execute($data + ['id' => $id]);
                flash_set('success', 'Department updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO departments (faculty_id, name, code, status) VALUES (:faculty_id,:name,:code,:status)');
                $stmt->execute($data);
                flash_set('success', 'Department created.');
            }
            log_audit($admin['id'], $id > 0 ? 'update' : 'create', 'department', $id ?: (int) $db->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM levels WHERE department_id = ?');
        $stmt->execute([$id]);
        $stmt2 = $db->prepare('SELECT COUNT(*) FROM courses WHERE department_id = ?');
        $stmt2->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0 || (int) $stmt2->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: this department still has levels or courses. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'department', $id);
            flash_set('success', 'Department deleted.');
        }
    }
    redirect('admin/departments.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM departments WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$faculties = $db->query(
    "SELECT f.id, f.name, u.name AS university_name FROM faculties f JOIN universities u ON u.id = f.university_id ORDER BY u.name, f.name"
)->fetchAll();

$departments = $db->query(
    "SELECT d.*, f.name AS faculty_name, u.name AS university_name,
            (SELECT COUNT(*) FROM levels l WHERE l.department_id = d.id) AS level_count,
            (SELECT COUNT(*) FROM courses c WHERE c.department_id = d.id) AS course_count
     FROM departments d
     JOIN faculties f ON f.id = d.faculty_id
     JOIN universities u ON u.id = f.university_id
     ORDER BY u.name, f.name, d.name"
)->fetchAll();

$pageTitle = 'Departments';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Departments</h3></div>
    <?php if (!$departments): ?>
      <div class="nb-empty"><h3>No departments yet</h3></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Department</th><th>Faculty</th><th>Levels</th><th>Courses</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td><?= e($d['name']) ?> <span class="nb-mono" style="color:var(--nb-muted); font-size:0.78rem;">(<?= e($d['code']) ?>)</span></td>
          <td><?= e($d['faculty_name']) ?> · <?= e($d['university_name']) ?></td>
          <td><?= (int) $d['level_count'] ?></td>
          <td><?= (int) $d['course_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $d['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($d['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $d['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit Department' : 'Add Department' ?></h3>
    <?php if (!$faculties): ?>
      <p class="nb-hint">Create a faculty before adding departments.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="nb-field">
        <label class="nb-label" for="faculty_id">Faculty</label>
        <select class="nb-select" id="faculty_id" name="faculty_id" required>
          <option value="">Select faculty</option>
          <?php foreach ($faculties as $f): ?>
            <option value="<?= (int) $f['id'] ?>" <?= (int) ($editing['faculty_id'] ?? 0) === (int) $f['id'] ? 'selected' : '' ?>><?= e($f['university_name'] . ' — ' . $f['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="name">Name</label>
        <input class="nb-input" id="name" name="name" required value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="code">Code</label>
        <input class="nb-input" id="code" name="code" required maxlength="20" value="<?= e($editing['code'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="status">Status</label>
        <select class="nb-select" id="status" name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add Department' ?></button>
      <?php if ($editing): ?>
        <a href="departments.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this department?" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete Department</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
