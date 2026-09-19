<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$admin = require_admin();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_status') {
        $stmt = $db->prepare('SELECT status FROM students WHERE id = ?');
        $stmt->execute([$id]);
        $current = $stmt->fetchColumn();
        $new = $current === 'active' ? 'suspended' : 'active';
        $db->prepare('UPDATE students SET status = ? WHERE id = ?')->execute([$new, $id]);
        log_audit($admin['id'], 'update_status', 'student', $id, $new);
        flash_set('success', 'Student status updated.');
    }
    redirect('admin/students.php' . (isset($_GET['view']) ? '?view=' . (int) $_GET['view'] : ''));
}

$search = trim((string) ($_GET['q'] ?? ''));
$viewId = isset($_GET['view']) ? (int) $_GET['view'] : null;

$sql = "SELECT s.*, u.name AS university_name,
        (SELECT COUNT(*) FROM orders o WHERE o.student_id = s.id AND o.status = 'APPROVED') AS approved_orders,
        (SELECT COUNT(*) FROM entitlements e WHERE e.student_id = s.id) AS entitlement_count
        FROM students s JOIN universities u ON u.id = s.university_id WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= ' AND (s.full_name LIKE :q OR s.email LIKE :q OR s.student_number LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY s.created_at DESC LIMIT 300';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$viewStudent = null;
$viewOrders = [];
if ($viewId) {
    $stmt = $db->prepare('SELECT s.*, u.name AS university_name FROM students s JOIN universities u ON u.id = s.university_id WHERE s.id = :id');
    $stmt->execute(['id' => $viewId]);
    $viewStudent = $stmt->fetch() ?: null;
    if ($viewStudent) {
        $ordersStmt = $db->prepare('SELECT * FROM orders WHERE student_id = :id ORDER BY created_at DESC LIMIT 20');
        $ordersStmt->execute(['id' => $viewId]);
        $viewOrders = $ordersStmt->fetchAll();
    }
}

$pageTitle = 'Students';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<form method="get" class="nb-toolbar">
  <input class="nb-input" type="search" name="q" placeholder="Name, email or student number" value="<?= e($search) ?>">
  <button type="submit" class="nb-btn nb-btn--ghost">Search</button>
</form>

<?php if ($viewStudent): ?>
<div class="nb-card" style="padding:22px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <h3 style="margin-bottom:4px;"><?= e($viewStudent['full_name']) ?></h3>
      <p style="color:var(--nb-muted); margin:0;"><?= e($viewStudent['email']) ?> · <?= e($viewStudent['university_name']) ?><?= $viewStudent['student_number'] ? ' · ' . e($viewStudent['student_number']) : '' ?></p>
    </div>
    <span class="nb-stamp nb-stamp--<?= $viewStudent['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($viewStudent['status']) ?></span>
  </div>
  <form method="post" style="margin-top:14px;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="id" value="<?= (int) $viewStudent['id'] ?>">
    <button type="submit" class="nb-btn nb-btn--sm <?= $viewStudent['status'] === 'active' ? 'nb-btn--danger' : 'nb-btn--primary' ?>">
      <?= $viewStudent['status'] === 'active' ? 'Suspend Account' : 'Reactivate Account' ?>
    </button>
  </form>

  <h4 style="margin-top:20px;">Recent Orders</h4>
  <?php if (!$viewOrders): ?>
    <p class="nb-hint">No orders yet.</p>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Order</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($viewOrders as $o): ?>
      <tr>
        <td class="nb-mono"><a href="orders.php?view=<?= (int) $o['id'] ?>"><?= e($o['order_number']) ?></a></td>
        <td><?= e(format_money($o['total'], $o['currency'])) ?></td>
        <td><span class="nb-stamp nb-stamp--<?= strtolower($o['status']) === 'approved' ? 'approved' : 'pending' ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
        <td><?= e(human_date($o['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="nb-card" style="padding:0;">
  <?php if (!$students): ?>
    <div class="nb-empty"><h3>No students found</h3></div>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Name</th><th>University</th><th>Approved Orders</th><th>Library Items</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($students as $s): ?>
      <tr>
        <td><?= e($s['full_name']) ?><br><span class="nb-hint"><?= e($s['email']) ?></span></td>
        <td><?= e($s['university_name']) ?></td>
        <td><?= (int) $s['approved_orders'] ?></td>
        <td><?= (int) $s['entitlement_count'] ?></td>
        <td><span class="nb-stamp nb-stamp--<?= $s['status'] === 'active' ? 'approved' : 'rejected' ?>"><?= e($s['status']) ?></span></td>
        <td><a href="?view=<?= (int) $s['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
