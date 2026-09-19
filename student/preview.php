<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$documentId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare(
    "SELECT d.*, c.code AS course_code, c.title AS course_title FROM documents d JOIN courses c ON c.id = d.course_id WHERE d.id = :id AND d.status = 'ACTIVE'"
);
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();

if (!$document) {
    flash_set('error', 'That document is not available for preview.');
    redirect('student/courses.php');
}

$ttlMinutes = (int) setting('preview_session_minutes', '20');
$token = sign_token(['type' => 'preview', 'document_id' => $documentId, 'student_id' => $student['id']], $ttlMinutes * 60);

$owned = student_owns_entitlement($student['id'], $documentId) !== null;

$pageTitle = 'Preview: ' . $document['title'];
require __DIR__ . '/../includes/partials/student_header.php';
?>

<div class="nb-breadcrumbs"><a href="course.php?id=<?= (int) $document['course_id'] ?>">← Back to <?= e($document['course_code']) ?></a></div>
<h1><?= e($document['title']) ?></h1>

<div class="nb-preview-shell">
  <iframe src="../api/preview.php?token=<?= urlencode($token) ?>" title="Document preview" loading="lazy"></iframe>
  <p class="nb-preview-notice">
    Showing the first <?= e(setting('preview_max_pages', '3')) ?> pages, watermarked with your account details. This preview link expires in <?= $ttlMinutes ?> minutes.
  </p>
</div>

<div style="margin-top:20px; display:flex; gap:10px; align-items:center;">
  <span class="nb-doc-card__price" style="font-size:1.3rem;"><?= e(format_money($document['price'])) ?></span>
  <?php if ($owned): ?>
    <a href="library.php" class="nb-btn nb-btn--navy">Go to Library</a>
  <?php else: ?>
    <button class="nb-btn nb-btn--primary" data-add-to-cart="<?= (int) $document['id'] ?>">Add to Cart</button>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
