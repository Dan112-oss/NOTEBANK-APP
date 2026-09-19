<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$student = require_student();
$db = get_db();

$stmt = $db->prepare(
    "SELECT dl.*, d.title, c.code AS course_code
     FROM downloads dl JOIN documents d ON d.id = dl.document_id JOIN courses c ON c.id = d.course_id
     WHERE dl.student_id = :id ORDER BY dl.downloaded_at DESC LIMIT 100"
);
$stmt->execute(['id' => $student['id']]);
$downloads = $stmt->fetchAll();

$pageTitle = 'Download History';
require __DIR__ . '/../includes/partials/student_header.php';
?>

<h1>Download History</h1>

<?php if (!$downloads): ?>
  <div class="nb-empty"><h3>No downloads yet</h3><p>Every file you download from your library is logged here for your records.</p></div>
<?php else: ?>
<div class="nb-card" style="padding:0;">
  <table class="nb-table">
    <thead><tr><th>Document</th><th>Course</th><th>Downloaded</th></tr></thead>
    <tbody>
    <?php foreach ($downloads as $d): ?>
      <tr>
        <td><?= e($d['title']) ?></td>
        <td class="nb-mono"><?= e($d['course_code']) ?></td>
        <td><?= e(human_date($d['downloaded_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/student_footer.php'; ?>
