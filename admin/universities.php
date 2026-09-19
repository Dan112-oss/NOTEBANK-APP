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
        $v->required('name', 'Name')->maxLength('name', 190, 'Name')
          ->required('code', 'Code')->maxLength('code', 20, 'Code')
          ->required('country', 'Country');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $data = [
                'name' => trim($_POST['name']),
                'code' => strtoupper(trim($_POST['code'])),
                'country' => trim($_POST['country']),
                'status' => $_POST['status'] === 'inactive' ? 'inactive' : 'active',
            ];
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE universities SET name=:name, code=:code, country=:country, status=:status WHERE id=:id');
                $stmt->execute($data + ['id' => $id]);
                log_audit($admin['id'], 'update', 'university', $id);
                flash_set('success', 'University updated.');
            } else {
                $stmt = $db->prepare('INSERT INTO universities (name, code, country, status) VALUES (:name,:code,:country,:status)');
                $stmt->execute($data);
                log_audit($admin['id'], 'create', 'university', (int) $db->lastInsertId());
                flash_set('success', 'University created.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) $_POST['id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM faculties WHERE university_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: this university still has faculties. Deactivate it instead.');
        } else {
            $db->prepare('DELETE FROM universities WHERE id = ?')->execute([$id]);
            log_audit($admin['id'], 'delete', 'university', $id);
            flash_set('success', 'University deleted.');
        }
    }
    redirect('admin/universities.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM universities WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$universities = $db->query(
    "SELECT u.*, (SELECT COUNT(*) FROM faculties f WHERE f.university_id = u.id) AS faculty_count
     FROM universities u ORDER BY u.name"
)->fetchAll();

$pageTitle = 'Universities';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">All Universities</h3></div>
    <?php if (!$universities): ?>
      <div class="nb-empty"><h3>No universities yet</h3><p>Add Landmark University's peers here as Note Bank expands (see the guide, section 19 — no code changes required).</p></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Name</th><th>Code</th><th>Country</th><th>Faculties</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($universities as $u): ?>
        <tr>
          <td><?= e($u['name']) ?></td>
          <td class="nb-mono"><?= e($u['code']) ?></td>
          <td><?= e($u['country']) ?></td>
          <td><?= (int) $u['faculty_count'] ?></td>
          <td><span class="nb-stamp nb-stamp--<?= $u['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($u['status']) ?></span></td>
          <td><a href="?edit=<?= (int) $u['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:20px;">
    <h3><?= $editing ? 'Edit University' : 'Add University' ?></h3>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
      <div class="nb-field">
        <label class="nb-label" for="name">Name</label>
        <input class="nb-input" id="name" name="name" required value="<?= e($editing['name'] ?? '') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="code">Code</label>
        <input class="nb-input" id="code" name="code" required maxlength="20" value="<?= e($editing['code'] ?? '') ?>">
        <p class="nb-hint">Short unique identifier, e.g. LMUI.</p>
      </div>
      <div class="nb-field">
        <label class="nb-label" for="country">Country</label>
        <input class="nb-input" id="country" name="country" required value="<?= e($editing['country'] ?? 'Cameroon') ?>">
      </div>
      <div class="nb-field">
        <label class="nb-label" for="status">Status</label>
        <select class="nb-select" id="status" name="status">
          <option value="active" <?= ($editing['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($editing['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block"><?= $editing ? 'Save Changes' : 'Add University' ?></button>
      <?php if ($editing): ?>
        <a href="universities.php" class="nb-btn nb-btn--ghost nb-btn--block" style="margin-top:8px;">Cancel</a>
      <?php endif; ?>
    </form>

    <?php if ($editing): ?>
    <form method="post" data-confirm="Delete this university? This cannot be undone." style="margin-top:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--danger nb-btn--block nb-btn--sm">Delete University</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
