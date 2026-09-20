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

$profileStmt = $db->prepare(
    "SELECT u.name AS university_name, d.name AS department_name, l.name AS level_name
     FROM students s
     LEFT JOIN universities u ON u.id = s.university_id
     LEFT JOIN departments d ON d.id = s.department_id
     LEFT JOIN levels l ON l.id = s.level_id
     WHERE s.id = ?"
);
$profileStmt->execute([$student['id']]);
$profileInfo = $profileStmt->fetch() ?: [];

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<div class="nb-page-head">
  <h1>Welcome back, <?= e(explode(' ', $student['full_name'])[0]) ?></h1>
  <p class="nb-subtitle">Here's what's happening in your account.</p>
</div>

<div class="nb-stat-grid">
  <a href="library.php" class="nb-card nb-stat-card nb-stat-card--mint">
    <div class="nb-stat-card__icon"><?= nb_icon('folder') ?></div>
    <div class="nb-stat-card__label">Library</div>
    <div class="nb-stat-card__value"><?= $libraryCount ?></div>
    <div class="nb-stat-card__hint">documents unlocked</div>
  </a>
  <a href="orders.php" class="nb-card nb-stat-card nb-stat-card--gold">
    <div class="nb-stat-card__icon"><?= nb_icon('card') ?></div>
    <div class="nb-stat-card__label">Pending Orders</div>
    <div class="nb-stat-card__value nb-stat-card__value--accent"><?= $pendingOrders ?></div>
    <div class="nb-stat-card__hint">awaiting payment/verification</div>
  </a>
  <a href="courses.php" class="nb-card nb-stat-card nb-stat-card--plum">
    <div class="nb-stat-card__icon"><?= nb_icon('search') ?></div>
    <div class="nb-stat-card__label">Catalogue</div>
    <div class="nb-stat-card__value">Browse</div>
    <div class="nb-stat-card__hint">find notes &amp; past questions</div>
  </a>
</div>

<div class="nb-dash-bottom">
  <div class="nb-dash-bottom__main">
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
  </div>

  <aside class="nb-dash-bottom__side">
    <div class="nb-card nb-account-card">
      <h3>Account overview</h3>
      <div class="nb-account-card__row"><?= nb_icon('building') ?><span><?= e($profileInfo['university_name'] ?? 'Not set') ?></span></div>
      <div class="nb-account-card__row"><?= nb_icon('building2') ?><span><?= e($profileInfo['department_name'] ?? 'Department not set') ?></span></div>
      <div class="nb-account-card__row"><?= nb_icon('chart') ?><span><?= e($profileInfo['level_name'] ?? 'Level not set') ?></span></div>
      <div class="nb-account-card__row"><?= nb_icon('user') ?><span><?= $student['email_verified'] ? 'Email verified' : 'Email not verified yet' ?></span></div>
      <a href="profile.php" class="nb-btn nb-btn--ghost nb-btn--sm nb-btn--block" style="margin-top:14px;">Edit profile</a>
    </div>
    <div class="nb-card nb-account-card">
      <h3>Quick links</h3>
      <a href="courses.php" class="nb-quick-link"><?= nb_icon('search') ?><span>Browse catalogue</span></a>
      <a href="cart.php" class="nb-quick-link"><?= nb_icon('cart') ?><span>View cart</span></a>
      <a href="orders.php" class="nb-quick-link"><?= nb_icon('list') ?><span>Order history</span></a>
    </div>
  </aside>
</div>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
