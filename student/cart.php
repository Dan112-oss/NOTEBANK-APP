<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$student = require_student();
$db = get_db();

$stmt = $db->prepare(
    "SELECT ci.*, d.title, d.type, d.price AS current_price, d.status AS document_status, c.code AS course_code
     FROM cart_items ci JOIN documents d ON d.id = ci.document_id JOIN courses c ON c.id = d.course_id
     WHERE ci.student_id = :id ORDER BY ci.created_at DESC"
);
$stmt->execute(['id' => $student['id']]);
$items = $stmt->fetchAll();

$total = 0.0;
foreach ($items as $item) {
    $total += (float) $item['current_price'] * (int) $item['quantity'];
}

$pageTitle = 'Your Cart';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>Your Cart</h1>

<?php if (!$items): ?>
  <div class="nb-empty">
    <h3>Your cart is empty</h3>
    <p>Browse the catalogue to find notes and past questions for your courses.</p>
    <a href="courses.php" class="nb-btn nb-btn--primary" style="margin-top:10px;">Browse Courses</a>
  </div>
<?php else: ?>
<div style="display:grid; grid-template-columns: 1fr 320px; gap:20px; align-items:start;">
  <div class="nb-card" style="padding:0 20px;">
    <?php foreach ($items as $item): ?>
      <div class="nb-cart-item" id="cart-row-<?= (int) $item['id'] ?>">
        <div style="flex:1;">
          <div class="nb-cart-item__title"><?= e($item['title']) ?></div>
          <div class="nb-cart-item__meta"><?= e($item['course_code']) ?> · <?= $item['type'] === 'NOTE' ? 'Note' : 'Past Question' ?></div>
          <?php if ($item['document_status'] !== 'ACTIVE'): ?>
            <div class="nb-hint" style="color:var(--nb-danger);">No longer available — will be removed at checkout.</div>
          <?php endif; ?>
        </div>
        <div class="nb-mono" style="font-weight:700;"><?= e(format_money($item['current_price'])) ?></div>
        <button type="button" class="nb-btn nb-btn--ghost nb-btn--sm" data-remove-from-cart="<?= (int) $item['id'] ?>">Remove</button>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="nb-cart-summary">
    <div class="nb-cart-summary__row"><span>Items</span><span><?= count($items) ?></span></div>
    <div class="nb-cart-summary__row nb-cart-summary__row--total"><span>Total</span><span id="nbCartTotal"><?= e(format_money($total)) ?></span></div>
      <a href="checkout.php" class="nb-btn nb-btn--primary nb-btn--block" style="margin-top:14px;">Proceed to Checkout</a>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
