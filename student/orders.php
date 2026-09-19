<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$stmt = $db->prepare('SELECT * FROM orders WHERE student_id = :id ORDER BY created_at DESC LIMIT 100');
$stmt->execute(['id' => $student['id']]);
$orders = $stmt->fetchAll();

$viewId = isset($_GET['view']) ? (int) $_GET['view'] : null;
$viewOrder = null;
$viewItems = [];
$viewInvoice = null;
if ($viewId) {
    $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND student_id = :sid');
    $stmt->execute(['id' => $viewId, 'sid' => $student['id']]);
    $viewOrder = $stmt->fetch() ?: null;
    if ($viewOrder) {
        $itemsStmt = $db->prepare('SELECT oi.*, d.title FROM order_items oi JOIN documents d ON d.id = oi.document_id WHERE oi.order_id = :id');
        $itemsStmt->execute(['id' => $viewId]);
        $viewItems = $itemsStmt->fetchAll();
        $invStmt = $db->prepare('SELECT * FROM invoices WHERE order_id = :id');
        $invStmt->execute(['id' => $viewId]);
        $viewInvoice = $invStmt->fetch() ?: null;
    }
}

function order_stamp_class(string $status): string
{
    return match ($status) {
        'APPROVED' => 'approved',
        'REJECTED', 'CANCELLED' => 'rejected',
        'PAYMENT_SUBMITTED' => 'submitted',
        default => 'pending',
    };
}

$pageTitle = 'My Orders';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>My Orders</h1>

<?php if ($viewOrder): ?>
<div class="nb-card" style="padding:22px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
    <h3 style="margin:0;">Order <span class="nb-mono"><?= e($viewOrder['order_number']) ?></span></h3>
    <span class="nb-stamp nb-stamp--<?= order_stamp_class($viewOrder['status']) ?> nb-stamp--rotated"><?= e(str_replace('_', ' ', $viewOrder['status'])) ?></span>
  </div>
  <table class="nb-table" style="margin:14px 0;">
    <tbody>
    <?php foreach ($viewItems as $it): ?>
      <tr><td><?= e($it['title']) ?></td><td class="nb-mono"><?= e(format_money($it['unit_price'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><strong>Total: <?= e(format_money($viewOrder['total'], $viewOrder['currency'])) ?></strong></p>

  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <?php if (in_array($viewOrder['status'], ['PENDING_PAYMENT', 'REJECTED'], true)): ?>
      <a href="payment.php?order=<?= (int) $viewOrder['id'] ?>" class="nb-btn nb-btn--primary"><?= $viewOrder['status'] === 'REJECTED' ? 'Resubmit Payment' : 'Complete Payment' ?></a>
    <?php elseif ($viewOrder['status'] === 'PAYMENT_SUBMITTED'): ?>
      <span class="nb-hint">Awaiting admin verification.</span>
    <?php endif; ?>
    <?php if ($viewInvoice): ?>
      <a href="../api/downloads.php?invoice=<?= (int) $viewOrder['id'] ?>" class="nb-btn nb-btn--ghost">Download Invoice</a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!$orders): ?>
  <div class="nb-empty"><h3>No orders yet</h3><p>Your purchase history will show up here.</p></div>
<?php else: ?>
<div class="nb-card" style="padding:0;">
  <table class="nb-table">
    <thead><tr><th>Order</th><th>Total</th><th>Status</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $o): ?>
      <tr>
        <td class="nb-mono"><?= e($o['order_number']) ?></td>
        <td><?= e(format_money($o['total'], $o['currency'])) ?></td>
        <td><span class="nb-stamp nb-stamp--<?= order_stamp_class($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span></td>
        <td><?= e(human_date($o['created_at'])) ?></td>
        <td><a href="?view=<?= (int) $o['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">View</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
