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
        $v->required('name', 'Name')->required('code', 'Code');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $data = [
                'name' => trim($_POST['name']),
                'code' => strtoupper(trim($_POST['code'])),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE semesters SET name=:name, code=:code, status=:status WHERE id=:id');
                $stmt->execute($data + ['id' => $id]);
                flash_set('success', 'Semester updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO semesters (name, code, status) VALUES (:name,:code,:status)');
                $stmt->execute($data);
                flash_set('success', 'Semester created.');
            }
            log_audit($admin['id'], $id > 0 ? 'update' : 'create', 'semester', $id ?: (int) $db->lastInsertId());
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM courses WHERE semester_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: courses still use this semester. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM semesters WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'semester', $id);
            flash_set('success', 'Semester deleted.');
        }
    }
    redirect('admin/semesters.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM semesters WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$semesters = $db->query(
    "SELECT s.*, (SELECT COUNT(*) FROM courses c WHERE c.semester_id = s.id) AS course_count FROM semesters s ORDER BY s.name"
)->fetchAll();

$pageTitle = 'Semesters';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Semesters</h3></div>
    <?php if (!$semesters): ?>
      <div class="nb-empty"><h3>No semesters yet</h3></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Name</th><th>Code</th><th>Courses</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($semesters as $s): ?>
        <tr>
          <td><?= e($s['name']) ?></td>
          <td class="nb-mono"><?= e($s['code']) ?></td>
          <td><?= (int) $s['course_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $s['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($s['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $s['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit Semester' : 'Add Semester' ?></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="nb-field">
        <label class="nb-label" for="name">Name</label>
        <input class="nb-input" id="name" name="name" required placeholder="e.g. First Semester" value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="code">Code</label>
        <input class="nb-input" id="code" name="code" required maxlength="20" placeholder="e.g. FIRST" value="<?= e($editing['code'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="status">Status</label>
        <select class="nb-select" id="status" name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add Semester' ?></button>
      <?php if ($editing): ?>
        <a href="semesters.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this semester?" style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete Semester</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
