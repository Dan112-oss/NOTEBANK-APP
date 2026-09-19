<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$courseId = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare(
    "SELECT c.*, d.name AS department_name, lv.name AS level_name, sm.name AS semester_name
     FROM courses c JOIN departments d ON d.id = c.department_id JOIN levels lv ON lv.id = c.level_id JOIN semesters sm ON sm.id = c.semester_id
     WHERE c.id = :id AND c.status = 'active'"
);
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch();

if (!$course) {
    flash_set('error', 'That course could not be found.');
    redirect('student/courses.php');
}

$docsStmt = $db->prepare(
    "SELECT d.*, (SELECT COUNT(*) FROM entitlements e WHERE e.document_id = d.id AND e.student_id = :student_id) AS owned,
            (SELECT COUNT(*) FROM cart_items ci WHERE ci.document_id = d.id AND ci.student_id = :student_id2) AS in_cart
     FROM documents d WHERE d.course_id = :course_id AND d.status = 'ACTIVE' ORDER BY d.type, d.title"
);
$docsStmt->execute(['student_id' => $student['id'], 'student_id2' => $student['id'], 'course_id' => $courseId]);
$allDocs = $docsStmt->fetchAll();
$notes = array_filter($allDocs, fn($d) => $d['type'] === 'NOTE');
$pastQuestions = array_filter($allDocs, fn($d) => $d['type'] === 'PAST_QUESTION');

$pageTitle = $course['code'];
require __DIR__ . '/../includes/partials/student_header.php';

function render_doc_card(array $d): void
{
    $typeClass = $d['type'] === 'NOTE' ? 'note' : 'past-question';
    $typeLabel = $d['type'] === 'NOTE' ? 'Note' : 'Past Question';
    ?>
    <div class="nb-doc-card">
      <span class="nb-doc-card__type nb-doc-card__type--<?= $typeClass ?>"><?= $typeLabel ?></span>
      <div class="nb-doc-card__title"><?= e($d['title']) ?></div>
      <?php if ($d['description']): ?><div class="nb-doc-card__meta"><?= e(mb_strimwidth($d['description'], 0, 90, '…')) ?></div><?php endif; ?>
      <?php if ($d['page_count']): ?><div class="nb-doc-card__meta"><?= (int) $d['page_count'] ?> pages</div><?php endif; ?>
      <div class="nb-doc-card__footer">
        <span class="nb-doc-card__price"><?= e(format_money($d['price'])) ?></span>
        <?php if ($d['owned']): ?>
          <a href="library.php" class="nb-btn nb-btn--navy nb-btn--sm">In Library</a>
        <?php elseif ($d['in_cart']): ?>
          <a href="cart.php" class="nb-btn nb-btn--ghost nb-btn--sm">In Cart</a>
        <?php else: ?>
          <button class="nb-btn nb-btn--primary nb-btn--sm" data-add-to-cart="<?= (int) $d['id'] ?>">Add to Cart</button>
        <?php endif; ?>
      </div>
      <a href="preview.php?id=<?= (int) $d['id'] ?>" class="nb-hint" style="text-align:center;">Preview first <?= e(setting('preview_max_pages', '3')) ?> pages →</a>
    </div>
    <?php
}
?>

<div class="nb-breadcrumbs"><a href="courses.php">← Browse Courses</a></div>
<h1><?= e($course['code']) ?> — <?= e($course['title']) ?></h1>
<p style="color:var(--nb-muted); margin-top:-8px;"><?= e($course['department_name']) ?> · <?= e($course['level_name']) ?> · <?= e($course['semester_name']) ?></p>
<?php if ($course['description']): ?><p><?= e($course['description']) ?></p><?php endif; ?>

<h2>Notes</h2>
<?php if (!$notes): ?>
  <div class="nb-empty"><h3>No notes yet</h3><p>Check back soon, or browse past questions below.</p></div>
<?php else: ?>
  <div class="nb-doc-grid"><?php foreach ($notes as $d) { render_doc_card($d); } ?></div>
<?php endif; ?>

<h2 style="margin-top:32px;">Past Questions</h2>
<?php if (!$pastQuestions): ?>
  <div class="nb-empty"><h3>No past questions yet</h3></div>
<?php else: ?>
  <div class="nb-doc-grid"><?php foreach ($pastQuestions as $d) { render_doc_card($d); } ?></div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
