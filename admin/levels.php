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
        $v->required('department_id', 'Department')->required('name', 'Name')->required('code', 'Code');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $data = [
                'department_id' => (int) $_POST['department_id'],
                'name' => trim($_POST['name']),
                'code' => trim($_POST['code']),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE levels SET department_id=:department_id, name=:name, code=:code, status=:status WHERE id=:id');
                $stmt->execute($data + ['id' => $id]);
                flash_set('success', 'Level updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO levels (department_id, name, code, status) VALUES (:department_id,:name,:code,:status)');
                $stmt->execute($data);
                flash_set('success', 'Level created.');
            }
            log_audit($admin['id'], $id > 0 ? 'update' : 'create', 'level', $id ?: (int) $db->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM courses WHERE level_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: courses still reference this level. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM levels WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'level', $id);
            flash_set('success', 'Level deleted.');
        }
    }
    redirect('admin/levels.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM levels WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$departments = $db->query(
    "SELECT d.id, d.name, f.name AS faculty_name FROM departments d JOIN faculties f ON f.id = d.faculty_id ORDER BY f.name, d.name"
)->fetchAll();

$levels = $db->query(
    "SELECT lv.*, d.name AS department_name, (SELECT COUNT(*) FROM courses c WHERE c.level_id = lv.id) AS course_count
     FROM levels lv JOIN departments d ON d.id = lv.department_id ORDER BY d.name, lv.code + 0"
)->fetchAll();

$pageTitle = 'Levels';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Levels</h3></div>
    <?php if (!$levels): ?>
      <div class="nb-empty"><h3>No levels yet</h3></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Level</th><th>Department</th><th>Courses</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($levels as $lv): ?>
        <tr>
          <td><?= e($lv['name']) ?></td>
          <td><?= e($lv['department_name']) ?></td>
          <td><?= (int) $lv['course_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $lv['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($lv['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $lv['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit Level' : 'Add Level' ?></h3>
    <?php if (!$departments): ?>
      <p class="nb-hint">Create a department before adding levels.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="nb-field">
        <label class="nb-label" for="department_id">Department</label>
        <select class="nb-select" id="department_id" name="department_id" required>
          <option value="">Select department</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) ($editing['department_id'] ?? 0) === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['faculty_name'] . ' — ' . $d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="name">Name</label>
        <input class="nb-input" id="name" name="name" required placeholder="e.g. 300 Level" value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="code">Code</label>
        <input class="nb-input" id="code" name="code" required maxlength="20" placeholder="e.g. 300" value="<?= e($editing['code'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="status">Status</label>
        <select class="nb-select" id="status" name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add Level' ?></button>
      <?php if ($editing): ?>
        <a href="levels.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this level?" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete Level</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
