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
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'update') {
        $v = new Validator($_POST);
        $v->required('title', 'Title')->required('price', 'Price')->numeric('price', 'Price')->min('price', 0, 'Price');

        if ($v->fails()) {
            flash_set('error', $v->firstError());
        } else {
            $stmt = $db->prepare(
                'UPDATE documents SET title=:title, description=:description, price=:price, status=:status WHERE id=:id'
            );
            $stmt->execute([
                'title' => trim($_POST['title']),
                'description' => trim($_POST['description'] ?? ''),
                'price' => (float) $_POST['price'],
                'status' => in_array($_POST['status'], ['ACTIVE', 'INACTIVE', 'DRAFT'], true) ? $_POST['status'] : 'DRAFT',
                'id' => $id,
            ]);
            log_audit($admin['id'], 'update', 'document', $id);
            flash_set('success', 'Document updated.');
        }
    } elseif ($action === 'delete') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM order_items WHERE document_id = ?');
        $stmt->execute([$id]);
        if ((int) $stmt->fetchColumn() > 0) {
            flash_set('error', 'Cannot delete: this document has been purchased. Set it to Inactive instead.');
        } else {
            $doc = $db->prepare('SELECT file_path, preview_path FROM documents WHERE id = ?');
            $doc->execute([$id]);
            $row = $doc->fetch();
            $db->prepare('DELETE FROM documents WHERE id = ?')->execute([$id]);
            if ($row) {
                if (is_file($row['file_path'])) { @unlink($row['file_path']); }
                if ($row['preview_path'] && is_file($row['preview_path'])) { @unlink($row['preview_path']); }
            }
            log_audit($admin['id'], 'delete', 'document', $id);
            flash_set('success', 'Document deleted.');
        }
    }
    redirect('admin/documents.php');
}

$editing = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM documents WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editing = $stmt->fetch() ?: null;
}

$courseFilter = (int) ($_GET['course_id'] ?? 0);
$statusFilter = $_GET['status'] ?? '';
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT doc.*, c.code AS course_code, c.title AS course_title,
               (SELECT COUNT(*) FROM entitlements en WHERE en.document_id = doc.id) AS entitlement_count
        FROM documents doc JOIN courses c ON c.id = doc.course_id WHERE 1=1";
$params = [];
if ($courseFilter > 0) { $sql .= ' AND doc.course_id = :course_id'; $params['course_id'] = $courseFilter; }
if (in_array($statusFilter, ['ACTIVE', 'INACTIVE', 'DRAFT'], true)) { $sql .= ' AND doc.status = :status'; $params['status'] = $statusFilter; }
if ($search !== '') { $sql .= ' AND (doc.title LIKE :q OR c.code LIKE :q)'; $params['q'] = '%' . $search . '%'; }
$sql .= ' ORDER BY doc.created_at DESC LIMIT 200';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$courses = $db->query('SELECT id, code, title FROM courses ORDER BY code')->fetchAll();

$pageTitle = 'Documents';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div class="nb-toolbar">
  <form method="get" style="display:flex; gap:10px; flex-wrap:wrap;">
    <input class="nb-input" type="search" name="q" placeholder="Search title or course code" value="<?= e($search) ?>">
    <select class="nb-select" name="course_id">
      <option value="">All courses</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= $courseFilter === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
      <?php endforeach; ?>
    </select>
    <select class="nb-select" name="status">
      <option value="">Any status</option>
      <?php foreach (['ACTIVE', 'INACTIVE', 'DRAFT'] as $s): ?>
        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="nb-btn nb-btn--ghost">Filter</button>
  </form>
  <a href="uploads.php" class="nb-btn nb-btn--primary" style="margin-left:auto;">+ Upload Document</a>
</div>

<div class="nb-card" style="padding:0;">
  <?php if (!$documents): ?>
    <div class="nb-empty"><h3>No documents found</h3><p>Try a different filter, or upload the first document for this course.</p></div>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Title</th><th>Course</th><th>Type</th><th>Price</th><th>Owners</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= e($d['title']) ?></td>
        <td class="nb-mono"><?= e($d['course_code']) ?></td>
        <td><?= $d['type'] === 'NOTE' ? 'Note' : 'Past Question' ?></td>
        <td class="nb-mono"><?= e(format_money($d['price'])) ?></td>
        <td><?= (int) $d['entitlement_count'] ?></td>
        <td><span class="nb-stamp nb-stamp--<?= $d['status'] === 'ACTIVE' ? 'approved' : ($d['status'] === 'DRAFT' ? 'pending' : 'rejected') ?>"><?= e($d['status']) ?></span></td>
        <td><a href="?edit=<?= (int) $d['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php if ($editing): ?>
<div class="nb-card" style="padding:20px; margin-top:20px; max-width:520px;">
  <h3>Edit Document</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <div class="nb-field">
      <label class="nb-label" for="title">Title</label>
      <input class="nb-input" id="title" name="title" required value="<?= e($editing['title']) ?>">
    </div>
    <div class="nb-field">
      <label class="nb-label" for="description">Description</label>
      <textarea class="nb-textarea" id="description" name="description"><?= e($editing['description'] ?? '') ?></textarea>
    </div>
    <div class="nb-field">
      <label class="nb-label" for="price">Price (<?= e(setting('default_currency', 'XAF')) ?>)</label>
      <input class="nb-input" id="price" name="price" type="number" step="0.01" min="0" required value="<?= e((string) $editing['price']) ?>">
    </div>
    <div class="nb-field">
      <label class="nb-label" for="status">Status</label>
      <select class="nb-select" id="status" name="status">
        <?php foreach (['ACTIVE', 'INACTIVE', 'DRAFT'] as $s): ?>
          <option value="<?= $s ?>" <?= $editing['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="nb-btn nb-btn--primary">Save Changes</button>
    <a href="documents.php" class="nb-btn nb-btn--ghost">Cancel</a>
  </form>
  <form method="post" data-confirm="Delete this document? This cannot be undone." style="margin-top:14px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <button type="submit" class="nb-btn nb-btn--danger nb-btn--sm">Delete Document</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
