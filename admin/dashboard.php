<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$db = get_db();

$stats = [
    'students' => (int) $db->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'courses' => (int) $db->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'documents' => (int) $db->query('SELECT COUNT(*) FROM documents')->fetchColumn(),
    'pending_payments' => (int) $db->query("SELECT COUNT(*) FROM payment_proofs WHERE status = 'PENDING'")->fetchColumn(),
    'approved_orders' => (int) $db->query("SELECT COUNT(*) FROM orders WHERE status = 'APPROVED'")->fetchColumn(),
    'revenue' => (float) $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status = 'APPROVED'")->fetchColumn(),
];

$recentOrders = $db->query(
    "SELECT o.id, o.order_number, o.total, o.currency, o.status, o.created_at, s.full_name AS student_name
     FROM orders o JOIN students s ON s.id = o.student_id
     ORDER BY o.created_at DESC LIMIT 8"
)->fetchAll();

$recentPayments = $db->query(
    "SELECT p.id, p.method, p.amount, p.status, p.created_at, o.order_number, s.full_name AS student_name
     FROM payment_proofs p
     JOIN orders o ON o.id = p.order_id
     JOIN students s ON s.id = o.student_id
     ORDER BY p.created_at DESC LIMIT 8"
)->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div class="nb-kpi-grid">
  <a href="students.php" class="nb-kpi">
    <div class="nb-kpi__label">Students</div>
    <div class="nb-kpi__value"><?= number_format($stats['students']) ?></div>
  </a>
  <a href="courses.php" class="nb-kpi">
    <div class="nb-kpi__label">Courses</div>
    <div class="nb-kpi__value"><?= number_format($stats['courses']) ?></div>
  </a>
  <a href="documents.php" class="nb-kpi">
    <div class="nb-kpi__label">Documents</div>
    <div class="nb-kpi__value"><?= number_format($stats['documents']) ?></div>
  </a>
  <a href="payments.php" class="nb-kpi">
    <div class="nb-kpi__label">Pending Payments</div>
    <div class="nb-kpi__value nb-kpi__value--orange"><?= number_format($stats['pending_payments']) ?></div>
  </a>
  <a href="orders.php" class="nb-kpi">
    <div class="nb-kpi__label">Approved Orders</div>
    <div class="nb-kpi__value"><?= number_format($stats['approved_orders']) ?></div>
  </a>
  <a href="reports.php" class="nb-kpi">
    <div class="nb-kpi__label">Revenue (Approved)</div>
    <div class="nb-kpi__value nb-kpi__value--orange"><?= e(format_money($stats['revenue'])) ?></div>
  </a>
</div>

<div style="display:grid; grid-template-columns: 1.3fr 1fr; gap: 20px; align-items:start;">
  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0;">Recent Orders</h3>
      <a href="orders.php" class="nb-btn nb-btn--ghost nb-btn--sm">View all</a>
    </div>
    <?php if (!$recentOrders): ?>
      <div class="nb-empty"><h3>No orders yet</h3><p>Orders will appear here as students check out.</p></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Order</th><th>Student</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php foreach ($recentOrders as $o): ?>
        <tr>
          <td><a href="orders.php?view=<?= (int) $o['id'] ?>" class="nb-mono"><?= e($o['order_number']) ?></a></td>
          <td><?= e($o['student_name']) ?></td>
          <td class="nb-mono"><?= e(format_money($o['total'], $o['currency'])) ?></td>
          <td><span class="nb-stamp nb-stamp--<?= e(strtolower($o['status']) === 'approved' ? 'approved' : (strtolower($o['status']) === 'rejected' ? 'rejected' : (strtolower($o['status']) === 'payment_submitted' ? 'submitted' : 'pending'))) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
          <td><?= e(human_date($o['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <div class="nb-card" style="padding:0;">
    <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line); display:flex; justify-content:space-between; align-items:center;">
      <h3 style="margin:0;">Recent Payment Proofs</h3>
      <a href="payments.php" class="nb-btn nb-btn--ghost nb-btn--sm">View all</a>
    </div>
    <?php if (!$recentPayments): ?>
      <div class="nb-empty"><h3>Nothing submitted yet</h3></div>
    <?php else: ?>
    <table class="nb-table">
      <thead><tr><th>Order</th><th>Method</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($recentPayments as $p): ?>
        <tr>
          <td><a href="payments.php?view=<?= (int) $p['id'] ?>" class="nb-mono"><?= e($p['order_number']) ?></a></td>
          <td><?= e($p['method'] === 'MTN_MOMO' ? 'MTN MoMo' : 'Orange Money') ?></td>
          <td><span class="nb-stamp nb-stamp--<?= e(strtolower($p['status'])) ?>"><?= e($p['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
