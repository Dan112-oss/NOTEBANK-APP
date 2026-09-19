<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$student = require_student();
$db = get_db();

$stmt = $db->prepare(
    "SELECT ci.*, d.title, d.price AS current_price, d.status AS document_status
     FROM cart_items ci JOIN documents d ON d.id = ci.document_id WHERE ci.student_id = :id"
);
$stmt->execute(['id' => $student['id']]);
$cartItems = $stmt->fetchAll();
$cartItems = array_values(array_filter($cartItems, fn($i) => $i['document_status'] === 'ACTIVE'));

if (!$cartItems) {
    flash_set('error', 'Your cart is empty.');
    redirect('student/cart.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    // Prices are re-read from documents (never trusted from the
    // client or even from the stale cart_items snapshot) at the
    // moment the order is created.
    $db->beginTransaction();
    try {
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $subtotal += (float) $item['current_price'] * (int) $item['quantity'];
        }

        $orderNumber = generate_order_number();
        $orderStmt = $db->prepare(
            'INSERT INTO orders (student_id, order_number, subtotal, total, currency, status, payment_status, created_at)
             VALUES (:student_id, :order_number, :subtotal, :total, :currency, "PENDING_PAYMENT", "NOT_SUBMITTED", NOW())'
        );
        $orderStmt->execute([
            'student_id' => $student['id'],
            'order_number' => $orderNumber,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'currency' => setting('default_currency', 'XAF'),
        ]);
        $orderId = (int) $db->lastInsertId();

        $itemStmt = $db->prepare(
            'INSERT INTO order_items (order_id, document_id, quantity, unit_price, total) VALUES (:order_id, :document_id, :quantity, :unit_price, :total)'
        );
        foreach ($cartItems as $item) {
            $lineTotal = (float) $item['current_price'] * (int) $item['quantity'];
            $itemStmt->execute([
                'order_id' => $orderId,
                'document_id' => $item['document_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['current_price'],
                'total' => $lineTotal,
            ]);
        }

        $db->prepare('DELETE FROM cart_items WHERE student_id = :id')->execute(['id' => $student['id']]);
        $db->commit();

        redirect('student/payment.php?order=' . $orderId);
    } catch (\Throwable $e) {
        $db->rollBack();
        error_log('NOTE BANK checkout failed: ' . $e->getMessage());
        flash_set('error', 'Could not create your order. Please try again.');
        redirect('student/cart.php');
    }
}

$total = 0.0;
foreach ($cartItems as $item) {
    $total += (float) $item['current_price'] * (int) $item['quantity'];
}

$pageTitle = 'Checkout';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>Review Your Order</h1>

<div style="display:grid; grid-template-columns: 1fr 320px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0 20px;">
    <?php foreach ($cartItems as $item): ?>
      <div class="nb-cart-item">
        <div style="flex:1;"><div class="nb-cart-item__title"><?= e($item['title']) ?></div></div>
        <div class="nb-mono" style="font-weight:700;"><?= e(format_money($item['current_price'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="nb-cart-summary">
    <div class="nb-cart-summary__row nb-cart-summary__row--total"><span>Total Due</span><span><?= e(format_money($total)) ?></span></div>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit" class="nb-btn nb-btn--primary nb-btn--block" style="margin-top:14px;" data-loading-text="Placing order…">Place Order</button>
    </form>
    <p class="nb-hint" style="margin-top:10px;">You'll pay via MTN Mobile Money or Orange Money on the next screen, then upload your payment evidence for verification.</p>
  </div>
</div>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
