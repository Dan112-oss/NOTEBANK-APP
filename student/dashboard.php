<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$stmt = $db->prepare('SELECT COUNT(*) FROM entitlements WHERE student_id = ?');
$stmt->execute([$student['id']]);
$libraryCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE student_id = ? AND status IN ('PENDING_PAYMENT','PAYMENT_SUBMITTED')");
$stmt->execute([$student['id']]);
$pendingOrders = (int) $stmt->fetchColumn();

$recentDocsStmt = $db->prepare(
    "SELECT d.id, d.title, d.type, c.code AS course_code
     FROM entitlements e JOIN documents d ON d.id = e.document_id JOIN courses c ON c.id = d.course_id
     WHERE e.student_id = ? ORDER BY e.granted_at DESC LIMIT 6"
);
$recentDocsStmt->execute([$student['id']]);
$recentDocs = $recentDocsStmt->fetchAll();

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<div class="nb-page-head">
  <h1>Welcome back, <?= e(explode(' ', $student['full_name'])[0]) ?></h1>
  <p class="nb-subtitle">Here's what's happening in your account.</p>
</div>

<div class="nb-stat-grid">
  <a href="library.php" class="nb-card nb-stat-card nb-stat-card--mint">
    <div class="nb-stat-card__icon">✓</div>
    <div class="nb-stat-card__label">Library</div>
    <div class="nb-stat-card__value"><?= $libraryCount ?></div>
    <div class="nb-stat-card__hint">documents unlocked</div>
  </a>
  <a href="orders.php" class="nb-card nb-stat-card nb-stat-card--gold">
    <div class="nb-stat-card__icon">!</div>
    <div class="nb-stat-card__label">Pending Orders</div>
    <div class="nb-stat-card__value nb-stat-card__value--accent"><?= $pendingOrders ?></div>
    <div class="nb-stat-card__hint">awaiting payment/verification</div>
  </a>
  <a href="courses.php" class="nb-card nb-stat-card nb-stat-card--plum">
    <div class="nb-stat-card__icon">→</div>
    <div class="nb-stat-card__label">Catalogue</div>
    <div class="nb-stat-card__value">Browse</div>
    <div class="nb-stat-card__hint">find notes &amp; past questions</div>
  </a>
</div>

<div class="nb-section-head">
  <h2>Recently Unlocked</h2>
</div>
<?php if (!$recentDocs): ?>
  <div class="nb-empty">
    <h3>Your library is empty</h3>
    <p>Once a purchase is approved, your documents will appear here.</p>
    <a href="courses.php" class="nb-btn nb-btn--primary" style="margin-top:10px;">Browse the catalogue</a>
  </div>
<?php else: ?>
<div class="nb-doc-grid">
  <?php foreach ($recentDocs as $d): ?>
    <div class="nb-doc-card">
      <span class="nb-doc-card__type nb-doc-card__type--<?= $d['type'] === 'NOTE' ? 'note' : 'past-question' ?>"><?= $d['type'] === 'NOTE' ? 'Note' : 'Past Question' ?></span>
      <div class="nb-doc-card__title"><?= e($d['title']) ?></div>
      <div class="nb-doc-card__meta"><?= e($d['course_code']) ?></div>
      <div class="nb-doc-card__footer">
        <a href="library.php" class="nb-btn nb-btn--ghost nb-btn--sm">Open in Library</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
