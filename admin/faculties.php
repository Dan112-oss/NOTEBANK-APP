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
        $v->required('university_id', 'University')->required('name', 'Name')->required('code', 'Code');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $data = [
                'university_id' => (int) $_POST['university_id'],
                'name' => trim($_POST['name']),
                'code' => strtoupper(trim($_POST['code'])),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE faculties SET university_id=:university_id, name=:name, code=:code, status=:status WHERE id=:id');
                $stmt->execute($data + ['id' => $id]);
                flash_set('success', 'Faculty updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO faculties (university_id, name, code, status) VALUES (:university_id,:name,:code,:status)');
                $stmt->execute($data);
                flash_set('success', 'Faculty created.');
            }
            log_audit($admin['id'], $id > 0 ? 'update' : 'create', 'faculty', $id ?: (int) $db->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM departments WHERE faculty_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: this faculty still has departments. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM faculties WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'faculty', $id);
            flash_set('success', 'Faculty deleted.');
        }
    }
    redirect('admin/faculties.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM faculties WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$universities = $db->query('SELECT id, name FROM universities ORDER BY name')->fetchAll();
$faculties = $db->query(
    "SELECT f.*, u.name AS university_name, (SELECT COUNT(*) FROM departments d WHERE d.faculty_id = f.id) AS department_count
     FROM faculties f JOIN universities u ON u.id = f.university_id ORDER BY u.name, f.name"
)->fetchAll();

$pageTitle = 'Faculties';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Faculties</h3></div>
    <?php if (!$faculties): ?>
      <div class="nb-empty"><h3>No faculties yet</h3><p>Add a university first, then create its faculties here.</p></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Faculty</th><th>University</th><th>Code</th><th>Depts</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($faculties as $f): ?>
        <tr>
          <td><?= e($f['name']) ?></td>
          <td><?= e($f['university_name']) ?></td>
          <td class="nb-mono"><?= e($f['code']) ?></td>
          <td><?= (int) $f['department_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $f['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($f['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $f['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit Faculty' : 'Add Faculty' ?></h3>
    <?php if (!$universities): ?>
      <p class="nb-hint">Create a university before adding faculties.</p>
    <?php else: ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="nb-field">
        <label class="nb-label" for="university_id">University</label>
        <select class="nb-select" id="university_id" name="university_id" required>
          <option value="">Select university</option>
          <?php foreach ($universities as $u): ?>
            <option value="<?= (int) $u['id'] ?>" <?= (int) ($editing['university_id'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
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
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add Faculty' ?></button>
      <?php if ($editing): ?>
        <a href="faculties.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this faculty?" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete Faculty</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
