<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/invoice.php';
require_once __DIR__ . '/../config/mail.php';

$admin = require_admin();
$db = get_db();

/**
 * Approve a payment proof: mark it approved, mark the order APPROVED,
 * and create exactly one entitlement per order item — all inside a
 * single transaction so a crash never leaves a half-unlocked order.
 */
function approve_payment(PDO $db, int $proofId, int $adminId): array
{
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT * FROM payment_proofs WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $proofId]);
        $proof = $stmt->fetch();
        if (!$proof) {
            throw new RuntimeException('Payment proof not found.');
        }
        if ($proof['status'] === 'APPROVED') {
            $db->rollBack();
            return [true, null]; // already approved — idempotent no-op
        }

        $orderStmt = $db->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $orderStmt->execute(['id' => $proof['order_id']]);
        $order = $orderStmt->fetch();
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $db->prepare('UPDATE payment_proofs SET status = "APPROVED", reviewed_by = :admin_id, reviewed_at = NOW() WHERE id = :id')
           ->execute(['admin_id' => $adminId, 'id' => $proofId]);

        $db->prepare('UPDATE orders SET status = "APPROVED", payment_status = "APPROVED", approved_at = NOW() WHERE id = :id')
           ->execute(['id' => $order['id']]);

        $items = $db->prepare('SELECT * FROM order_items WHERE order_id = :id');
        $items->execute(['id' => $order['id']]);

        $entitlementStmt = $db->prepare(
            'INSERT INTO entitlements (student_id, document_id, order_id, granted_at)
             VALUES (:student_id, :document_id, :order_id, NOW())
             ON DUPLICATE KEY UPDATE order_id = VALUES(order_id)' // exactly one entitlement per (student, document) — re-purchase just relinks it
        );
        foreach ($items->fetchAll() as $item) {
            $entitlementStmt->execute([
                'student_id' => $order['student_id'],
                'document_id' => $item['document_id'],
                'order_id' => $order['id'],
            ]);
        }

        log_audit($adminId, 'approve_payment', 'payment_proof', $proofId, 'Order ' . $order['order_number']);
        $db->commit();
    } catch (\Throwable $e) {
        $db->rollBack();
        return [false, $e->getMessage()];
    }

    // Post-commit side effects: invoice + email. Failure here must not
    // roll back the already-committed unlock.
    try {
        generate_invoice_for_order((int) $order['id']);
    } catch (\Throwable $e) {
        error_log('NOTE BANK invoice generation failed for order ' . $order['id'] . ': ' . $e->getMessage());
    }

    try {
        $studentStmt = $db->prepare('SELECT full_name, email FROM students WHERE id = :id');
        $studentStmt->execute(['id' => $order['student_id']]);
        $student = $studentStmt->fetch();
        if ($student) {
            $html = '<p>Hi ' . e($student['full_name']) . ',</p>'
                . '<p>Your payment for order <strong>' . e($order['order_number']) . '</strong> has been verified and approved.</p>'
                . '<p>Your documents are now available in your Note Bank library.</p>'
                . '<p><a href="' . base_url('student/library.php') . '">Open my library</a></p>';
            send_mail($student['email'], 'Payment approved — ' . $order['order_number'], $html, 'payment_approved', (int) $order['student_id']);
        }
    } catch (\Throwable $e) {
        error_log('NOTE BANK approval email failed: ' . $e->getMessage());
    }

    return [true, null];
}

function reject_payment(PDO $db, int $proofId, int $adminId, string $reason): array
{
    $stmt = $db->prepare('SELECT * FROM payment_proofs WHERE id = :id');
    $stmt->execute(['id' => $proofId]);
    $proof = $stmt->fetch();
    if (!$proof) {
        return [false, 'Payment proof not found.'];
    }

    $db->prepare('UPDATE payment_proofs SET status = "REJECTED", reviewed_by = :admin_id, reviewed_at = NOW(), rejection_reason = :reason WHERE id = :id')
       ->execute(['admin_id' => $adminId, 'reason' => $reason, 'id' => $proofId]);
    $db->prepare('UPDATE orders SET status = "REJECTED", payment_status = "REJECTED" WHERE id = :id')
       ->execute(['id' => $proof['order_id']]);

    log_audit($adminId, 'reject_payment', 'payment_proof', $proofId, $reason);

    $orderStmt = $db->prepare(
        'SELECT o.order_number, s.full_name, s.email, s.id AS student_id FROM orders o JOIN students s ON s.id = o.student_id WHERE o.id = :id'
    );
    $orderStmt->execute(['id' => $proof['order_id']]);
    $order = $orderStmt->fetch();
    if ($order) {
        $html = '<p>Hi ' . e($order['full_name']) . ',</p>'
            . '<p>We could not verify the payment for order <strong>' . e($order['order_number']) . '</strong>.</p>'
            . '<p>Reason: ' . e($reason ?: 'Evidence did not match the expected payment.') . '</p>'
            . '<p>You can resubmit your payment proof from your order history.</p>';
        send_mail($order['email'], 'Payment could not be verified — ' . $order['order_number'], $html, 'payment_rejected', (int) $order['student_id']);
    }

    return [true, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $proofId = (int) ($_POST['proof_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        [$ok, $err] = approve_payment($db, $proofId, $admin['id']);
        flash_set($ok ? 'success' : 'error', $ok ? 'Payment approved — documents unlocked.' : ('Approval failed: ' . $err));
    } elseif ($action === 'reject') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        [$ok, $err] = reject_payment($db, $proofId, $admin['id'], $reason);
        flash_set($ok ? 'success' : 'error', $ok ? 'Payment rejected.' : ('Rejection failed: ' . $err));
    }
    redirect('admin/payments.php');
}

$statusFilter = $_GET['status'] ?? 'PENDING';
$viewId = isset($_GET['view']) ? (int) $_GET['view'] : null;

$sql = "SELECT p.*, o.order_number, o.total, o.currency, s.full_name AS student_name, s.email AS student_email
        FROM payment_proofs p
        JOIN orders o ON o.id = p.order_id
        JOIN students s ON s.id = o.student_id";
$params = [];
if (in_array($statusFilter, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
    $sql .= ' WHERE p.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' ORDER BY p.created_at DESC LIMIT 200';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$proofs = $stmt->fetchAll();

$viewProof = null;
$viewItems = [];
if ($viewId) {
    $stmt = $db->prepare(
        "SELECT p.*, o.order_number, o.total, o.currency, o.id AS order_id, s.full_name AS student_name, s.email AS student_email
         FROM payment_proofs p JOIN orders o ON o.id = p.order_id JOIN students s ON s.id = o.student_id WHERE p.id = :id"
    );
    $stmt->execute(['id' => $viewId]);
    $viewProof = $stmt->fetch() ?: null;
    if ($viewProof) {
        $itemsStmt = $db->prepare(
            'SELECT oi.*, d.title FROM order_items oi JOIN documents d ON d.id = oi.document_id WHERE oi.order_id = :order_id'
        );
        $itemsStmt->execute(['order_id' => $viewProof['order_id']]);
        $viewItems = $itemsStmt->fetchAll();
    }
}

$pageTitle = 'Payment Queue';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div class="nb-toolbar">
  <a href="?status=PENDING" class="nb-btn nb-btn--sm <?= $statusFilter === 'PENDING' ? 'nb-btn--navy' : 'nb-btn--ghost' ?>">Pending</a>
  <a href="?status=APPROVED" class="nb-btn nb-btn--sm <?= $statusFilter === 'APPROVED' ? 'nb-btn--navy' : 'nb-btn--ghost' ?>">Approved</a>
  <a href="?status=REJECTED" class="nb-btn nb-btn--sm <?= $statusFilter === 'REJECTED' ? 'nb-btn--navy' : 'nb-btn--ghost' ?>">Rejected</a>
</div>

<?php if ($viewProof): ?>
<div class="nb-card" style="padding:22px; margin-bottom:20px;">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:20px; flex-wrap:wrap;">
    <div>
      <h3 style="margin-bottom:4px;">Order <span class="nb-mono"><?= e($viewProof['order_number']) ?></span></h3>
      <p style="color:var(--nb-muted); margin:0;"><?= e($viewProof['student_name']) ?> · <?= e($viewProof['student_email']) ?></p>
    </div>
    <span class="nb-stamp nb-stamp--<?= strtolower($viewProof['status']) ?> nb-stamp--rotated"><?= e($viewProof['status']) ?></span>
  </div>

  <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:16px; margin:18px 0;">
    <div><div class="nb-hint">Method</div><strong><?= $viewProof['method'] === 'MTN_MOMO' ? 'MTN Mobile Money' : 'Orange Money' ?></strong></div>
    <div><div class="nb-hint">Reference</div><strong class="nb-mono"><?= e($viewProof['reference'] ?: '—') ?></strong></div>
    <div><div class="nb-hint">Amount Claimed</div><strong><?= e(format_money($viewProof['amount'], $viewProof['currency'])) ?></strong></div>
    <div><div class="nb-hint">Order Total</div><strong><?= e(format_money($viewProof['total'], $viewProof['currency'])) ?></strong></div>
    <div><div class="nb-hint">Submitted</div><strong><?= e(human_date($viewProof['created_at'])) ?></strong></div>
  </div>

  <div class="nb-hint" style="margin-bottom:6px;">Expected account: <?= $viewProof['method'] === 'MTN_MOMO' ? e(setting('mtn_momo_name') . ' — ' . setting('mtn_momo_number')) : e(setting('orange_money_name') . ' — ' . setting('orange_money_number')) ?></div>

  <p><a href="view-proof.php?id=<?= (int) $viewProof['id'] ?>" target="_blank" rel="noopener" class="nb-btn nb-btn--ghost nb-btn--sm">View submitted evidence ↗</a></p>

  <table class="nb-table" style="margin:14px 0;">
    <thead><tr><th>Document</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
    <tbody>
    <?php foreach ($viewItems as $it): ?>
      <tr><td><?= e($it['title']) ?></td><td><?= (int) $it['quantity'] ?></td><td><?= e(format_money($it['unit_price'])) ?></td><td><?= e(format_money($it['total'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($viewProof['status'] === 'PENDING'): ?>
  <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <form method="post" data-confirm="Approve this payment and unlock the purchased documents?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="proof_id" value="<?= (int) $viewProof['id'] ?>">
      <button type="submit" class="nb-btn nb-btn--primary">Approve &amp; Unlock</button>
    </form>
    <form method="post" style="display:flex; gap:8px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="proof_id" value="<?= (int) $viewProof['id'] ?>">
      <input class="nb-input" name="reason" placeholder="Rejection reason" style="min-width:220px;">
      <button type="submit" class="nb-btn nb-btn--danger">Reject</button>
    </form>
  </div>
  <?php elseif ($viewProof['rejection_reason']): ?>
    <p class="nb-hint">Rejection reason: <?= e($viewProof['rejection_reason']) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="nb-card" style="padding:0;">
  <?php if (!$proofs): ?>
    <div class="nb-empty"><h3>Nothing here</h3><p>No payment proofs with this status.</p></div>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Order</th><th>Student</th><th>Method</th><th>Amount</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($proofs as $p): ?>
      <tr>
        <td class="nb-mono"><?= e($p['order_number']) ?></td>
        <td><?= e($p['student_name']) ?></td>
        <td><?= $p['method'] === 'MTN_MOMO' ? 'MTN MoMo' : 'Orange Money' ?></td>
        <td><?= e(format_money($p['amount'], $p['currency'])) ?></td>
        <td><?= e(human_date($p['created_at'])) ?></td>
        <td><span class="nb-stamp nb-stamp--<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td><a href="?status=<?= e($statusFilter) ?>&view=<?= (int) $p['id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Review</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
