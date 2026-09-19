<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$db = get_db();

$statusFilter = $_GET['status'] ?? '';
$search = trim((string) ($_GET['q'] ?? ''));
$from = $_GET['from'] ?? '';
$to = $_GET['to'] ?? '';
$viewId = isset($_GET['view']) ? (int) $_GET['view'] : null;

$sql = "SELECT o.*, s.full_name AS student_name, s.email AS student_email
        FROM orders o JOIN students s ON s.id = o.student_id WHERE 1=1";
$params = [];
if (in_array($statusFilter, ['PENDING_PAYMENT', 'PAYMENT_SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'], true)) {
    $sql .= ' AND o.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $sql .= ' AND (o.order_number LIKE :q OR s.full_name LIKE :q OR s.email LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if ($from !== '') { $sql .= ' AND o.created_at >= :from'; $params['from'] = $from . ' 00:00:00'; }
if ($to !== '') { $sql .= ' AND o.created_at <= :to'; $params['to'] = $to . ' 23:59:59'; }
$sql .= ' ORDER BY o.created_at DESC LIMIT 300';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$viewOrder = null;
$viewItems = [];
$viewProofs = [];
$viewInvoice = null;
if ($viewId) {
    $stmt = $db->prepare('SELECT o.*, s.full_name AS student_name, s.email AS student_email FROM orders o JOIN students s ON s.id = o.student_id WHERE o.id = :id');
    $stmt->execute(['id' => $viewId]);
    $viewOrder = $stmt->fetch() ?: null;
    if ($viewOrder) {
        $itemsStmt = $db->prepare('SELECT oi.*, d.title FROM order_items oi JOIN documents d ON d.id = oi.document_id WHERE oi.order_id = :id');
        $itemsStmt->execute(['id' => $viewId]);
        $viewItems = $itemsStmt->fetchAll();

        $proofsStmt = $db->prepare('SELECT * FROM payment_proofs WHERE order_id = :id ORDER BY created_at DESC');
        $proofsStmt->execute(['id' => $viewId]);
        $viewProofs = $proofsStmt->fetchAll();

        $invStmt = $db->prepare('SELECT * FROM invoices WHERE order_id = :id');
        $invStmt->execute(['id' => $viewId]);
        $viewInvoice = $invStmt->fetch() ?: null;
    }
}

$pageTitle = 'Orders';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<form method="get" class="nb-toolbar">
  <input class="nb-input" type="search" name="q" placeholder="Order #, student name or email" value="<?= e($search) ?>">
  <select class="nb-select" name="status">
    <option value="">Any status</option>
    <?php foreach (['PENDING_PAYMENT', 'PAYMENT_SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'] as $s): ?>
      <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
    <?php endforeach; ?>
  </select>
  <input class="nb-input" type="date" name="from" value="<?= e($from) ?>">
  <input class="nb-input" type="date" name="to" value="<?= e($to) ?>">
  <button type="submit" class="nb-btn nb-btn--ghost">Filter</button>
</form>

<?php if ($viewOrder): ?>
<div class="nb-card" style="padding:22px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <h3 style="margin-bottom:4px;">Order <span class="nb-mono"><?= e($viewOrder['order_number']) ?></span></h3>
      <p style="color:var(--nb-muted); margin:0;"><?= e($viewOrder['student_name']) ?> · <?= e($viewOrder['student_email']) ?></p>
    </div>
    <span class="nb-stamp nb-stamp--<?= strtolower($viewOrder['status']) === 'approved' ? 'approved' : (strtolower($viewOrder['status']) === 'rejected' ? 'rejected' : 'pending') ?> nb-stamp--rotated"><?= e(str_replace('_', ' ', $viewOrder['status'])) ?></span>
  </div>
  <table class="nb-table" style="margin:16px 0;">
    <thead><tr><th>Document</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
    <tbody>
    <?php foreach ($viewItems as $it): ?>
      <tr><td><?= e($it['title']) ?></td><td><?= (int) $it['quantity'] ?></td><td><?= e(format_money($it['unit_price'])) ?></td><td><?= e(format_money($it['total'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><strong>Total: <?= e(format_money($viewOrder['total'], $viewOrder['currency'])) ?></strong></p>

  <?php if ($viewInvoice): ?>
    <p><a href="invoice.php?order_id=<?= (int) $viewOrder['id'] ?>" target="_blank" class="nb-btn nb-btn--ghost nb-btn--sm">View Invoice ↗</a></p>
  <?php endif; ?>

  <?php if ($viewProofs): ?>
    <h4>Payment History</h4>
    <table class="nb-table">
      <thead><tr><th>Method</th><th>Reference</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($viewProofs as $p): ?>
        <tr>
          <td><?= $p['method'] === 'MTN_MOMO' ? 'MTN MoMo' : 'Orange Money' ?></td>
          <td class="nb-mono"><?= e($p['reference'] ?: '—') ?></td>
          <td><?= e(format_money($p['amount'])) ?></td>
          <td><span class="nb-stamp nb-stamp--<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td>
          <td><?= e(human_date($p['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="nb-card" style="padding:0;">
  <?php if (!$orders): ?>
    <div class="nb-empty"><h3>No orders match</h3></div>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Order</th><th>Student</th><th>Amount</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td class="nb-mono"><?= e($o['order_number']) ?></td>
        <td><?= e($o['student_name']) ?></td>
        <td><?= e(format_money($o['total'], $o['currency'])) ?></td>
        <td><span class="nb-stamp nb-stamp--<?= strtolower($o['status']) === 'approved' ? 'approved' : (strtolower($o['status']) === 'rejected' ? 'rejected' : 'pending') ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
        <td><?= e(human_date($o['created_at'])) ?></td>
        <td><a href="?view=<?= (int) $o['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
