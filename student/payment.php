<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../config/mail.php';

$student = require_student();
$db = get_db();

$orderId = (int) ($_GET['order'] ?? $_POST['order_id'] ?? 0);
$stmt = $db->prepare('SELECT * FROM orders WHERE id = :id AND student_id = :sid');
$stmt->execute(['id' => $orderId, 'sid' => $student['id']]);
$order = $stmt->fetch();

if (!$order) {
    flash_set('error', 'Order not found.');
    redirect('student/orders.php');
}

if ($order['status'] === 'APPROVED') {
    flash_set('success', 'This order is already approved — your documents are in your library.');
    redirect('student/library.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $v = new Validator($_POST);
    $v->required('method', 'Payment method')->in('method', ['MTN_MOMO', 'ORANGE_MONEY'], 'Payment method');

    $uploadError = null;
    if (empty($_FILES['proof']) || ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $uploadError = 'Please attach your payment evidence (screenshot or PDF).';
    } else {
        $uploadError = validate_upload($_FILES['proof'], ALLOWED_PROOF_MIME, MAX_PROOF_UPLOAD_BYTES);
    }

    if ($v->fails() || $uploadError) {
        flash_set('error', $v->firstError() ?? $uploadError);
    } else {
        $ext = pathinfo($_FILES['proof']['name'], PATHINFO_EXTENSION) ?: 'dat';
        $destName = random_filename($ext);
        $destPath = STORAGE_RECEIPTS . '/' . $destName;

        if (!move_uploaded_file($_FILES['proof']['tmp_name'], $destPath)) {
            flash_set('error', 'Could not save your upload. Please try again.');
        } else {
            @chmod($destPath, 0640);
            $db->prepare(
                'INSERT INTO payment_proofs (order_id, method, reference, amount, proof_path, status, created_at)
                 VALUES (:order_id, :method, :reference, :amount, :proof_path, "PENDING", NOW())'
            )->execute([
                'order_id' => $order['id'],
                'method' => $_POST['method'],
                'reference' => trim($_POST['reference'] ?? '') ?: null,
                'amount' => $order['total'],
                'proof_path' => $destPath,
            ]);

            $db->prepare('UPDATE orders SET status = "PAYMENT_SUBMITTED", payment_status = "SUBMITTED" WHERE id = :id')
               ->execute(['id' => $order['id']]);

            $html = '<p>Hi ' . e($student['full_name']) . ',</p>'
                . '<p>We received your payment evidence for order <strong>' . e($order['order_number']) . '</strong>.</p>'
                . '<p>An admin will verify it shortly. You will get another email once it is approved.</p>';
            send_mail($student['email'], 'Payment submitted — ' . $order['order_number'], $html, 'payment_submitted', $student['id']);

            flash_set('success', 'Payment evidence submitted. We will verify it shortly.');
            redirect('student/orders.php?view=' . $order['id']);
        }
    }
}

$itemsStmt = $db->prepare('SELECT oi.*, d.title FROM order_items oi JOIN documents d ON d.id = oi.document_id WHERE oi.order_id = :id');
$itemsStmt->execute(['id' => $order['id']]);
$items = $itemsStmt->fetchAll();

$pageTitle = 'Complete Payment';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<div class="nb-breadcrumbs"><a href="orders.php">← My Orders</a></div>
<h1>Complete Payment</h1>
<p style="color:var(--nb-muted); margin-top:-8px;">Order <span class="nb-mono"><?= e($order['order_number']) ?></span> · <?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?></p>

<div style="display:grid; grid-template-columns: 1fr 340px; gap:20px; align-items:start;">
  <div>
    <div class="nb-card" style="padding:20px; margin-bottom:20px;">
      <h3>1. Send Payment</h3>
      <p>Send <strong><?= e(format_money($order['total'], $order['currency'])) ?></strong> using one of the accounts below, using your order number <span class="nb-mono"><?= e($order['order_number']) ?></span> as the reference where possible.</p>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:14px; margin-top:14px;">
        <div class="nb-card" style="padding:14px; background:var(--nb-paper);">
          <div class="nb-hint">MTN Mobile Money</div>
          <div style="font-weight:700;"><?= e(setting('mtn_momo_name')) ?></div>
          <div class="nb-mono"><?= e(setting('mtn_momo_number')) ?></div>
        </div>
        <div class="nb-card" style="padding:14px; background:var(--nb-paper);">
          <div class="nb-hint">Orange Money</div>
          <div style="font-weight:700;"><?= e(setting('orange_money_name')) ?></div>
          <div class="nb-mono"><?= e(setting('orange_money_number')) ?></div>
        </div>
      </div>
    </div>

    <div class="nb-card" style="padding:20px;">
      <h3>2. Submit Evidence</h3>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
        <div class="nb-field">
          <label class="nb-label">Payment Method</label>
          <div class="nb-pay-methods">
            <label class="nb-pay-method"><input type="radio" name="method" value="MTN_MOMO" checked> MTN Mobile Money</label>
            <label class="nb-pay-method"><input type="radio" name="method" value="ORANGE_MONEY"> Orange Money</label>
          </div>
        </div>
        <div class="nb-field">
          <label class="nb-label" for="reference">Transaction Reference (optional)</label>
          <input class="nb-input" id="reference" name="reference" placeholder="e.g. MP240915.1234">
        </div>
        <div class="nb-field">
          <label class="nb-label" for="proof">Payment Evidence</label>
          <input type="file" id="proof" name="proof" accept="application/pdf,image/jpeg,image/png" required data-max-mb="8">
          <p class="nb-hint" data-file-label-for="proof">Screenshot (JPG/PNG) or PDF, up to 8 MB.</p>
        </div>
        <button type="submit" class="nb-btn nb-btn--primary nb-btn--block" data-loading-text="Submitting…">Submit for Verification</button>
      </form>
    </div>
  </div>

  <div class="nb-cart-summary">
    <h3 style="margin-top:0;">Order Summary</h3>
    <?php foreach ($items as $it): ?>
      <div class="nb-cart-summary__row"><span><?= e($it['title']) ?></span><span><?= e(format_money($it['unit_price'])) ?></span></div>
    <?php endforeach; ?>
    <div class="nb-cart-summary__row nb-cart-summary__row--total"><span>Total</span><span><?= e(format_money($order['total'], $order['currency'])) ?></span></div>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
