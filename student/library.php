<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$search = trim((string) ($_GET['q'] ?? ''));

$sql = "SELECT e.*, d.title, d.type, d.price, c.code AS course_code
        FROM entitlements e JOIN documents d ON d.id = e.document_id JOIN courses c ON c.id = d.course_id
        WHERE e.student_id = :id";
$params = ['id' => $student['id']];
if ($search !== '') {
    $sql .= ' AND (d.title LIKE :q OR c.code LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$sql .= ' ORDER BY e.granted_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll();

$pageTitle = 'My Library';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>My Library</h1>

<form method="get" style="margin-bottom:18px;">
  <input class="nb-input" type="search" name="q" placeholder="Search your library" value="<?= e($search) ?>" style="max-width:320px;">
</form>

<?php if (!$docs): ?>
  <div class="nb-empty">
    <h3>Nothing unlocked yet</h3>
    <p>Once your payment is approved, purchased documents appear here automatically.</p>
    <a href="courses.php" class="nb-btn nb-btn--primary" style="margin-top:10px;">Browse Courses</a>
  </div>
<?php else: ?>
<div class="nb-doc-grid">
  <?php foreach ($docs as $d): ?>
    <div class="nb-doc-card">
      <span class="nb-doc-card__type nb-doc-card__type--<?= $d['type'] === 'NOTE' ? 'note' : 'past-question' ?>"><?= $d['type'] === 'NOTE' ? 'Note' : 'Past Question' ?></span>
      <div class="nb-doc-card__title"><?= e($d['title']) ?></div>
      <div class="nb-doc-card__meta"><?= e($d['course_code']) ?> · Unlocked <?= e(human_date($d['granted_at'])) ?></div>
      <?php if ($d['download_count'] > 0): ?>
        <div class="nb-hint">Downloaded <?= (int) $d['download_count'] ?> time<?= $d['download_count'] === 1 ? '' : 's' ?></div>
      <?php endif; ?>
      <div class="nb-doc-card__footer nb-library-card__actions">
        <a href="../api/downloads.php?id=<?= (int) $d['document_id'] ?>" class="nb-btn nb-btn--primary nb-btn--sm">Download</a>
        <a href="preview.php?id=<?= (int) $d['document_id'] ?>" class="nb-btn nb-btn--ghost nb-btn--sm">Preview</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
