<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

$admin = require_admin();
$db = get_db();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
$fromDt = $from . ' 00:00:00';
$toDt = $to . ' 23:59:59';

$summary = $db->prepare(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'APPROVED' THEN 1 ELSE 0 END) AS approved_orders,
        SUM(CASE WHEN status = 'REJECTED' THEN 1 ELSE 0 END) AS rejected_orders,
        COALESCE(SUM(CASE WHEN status = 'APPROVED' THEN total ELSE 0 END), 0) AS revenue
     FROM orders WHERE created_at BETWEEN :from AND :to"
);
$summary->execute(['from' => $fromDt, 'to' => $toDt]);
$summaryRow = $summary->fetch();

$byCourse = $db->prepare(
    "SELECT c.code, c.title, COUNT(oi.id) AS units_sold, COALESCE(SUM(oi.total),0) AS revenue
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     JOIN documents d ON d.id = oi.document_id
     JOIN courses c ON c.id = d.course_id
     WHERE o.status = 'APPROVED' AND o.created_at BETWEEN :from AND :to
     GROUP BY c.id ORDER BY revenue DESC LIMIT 15"
);
$byCourse->execute(['from' => $fromDt, 'to' => $toDt]);
$byCourseRows = $byCourse->fetchAll();

$downloadCount = $db->prepare('SELECT COUNT(*) FROM downloads WHERE downloaded_at BETWEEN :from AND :to');
$downloadCount->execute(['from' => $fromDt, 'to' => $toDt]);
$downloads = (int) $downloadCount->fetchColumn();

$newStudents = $db->prepare('SELECT COUNT(*) FROM students WHERE created_at BETWEEN :from AND :to');
$newStudents->execute(['from' => $fromDt, 'to' => $toDt]);
$newStudentsCount = (int) $newStudents->fetchColumn();

$pageTitle = 'Reports';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<form method="get" class="nb-toolbar">
  <label class="nb-hint">From <input class="nb-input" type="date" name="from" value="<?= e($from) ?>"></label>
  <label class="nb-hint">To <input class="nb-input" type="date" name="to" value="<?= e($to) ?>"></label>
  <button type="submit" class="nb-btn nb-btn--ghost">Update</button>
</form>

<div class="nb-kpi-grid">
  <div class="nb-kpi"><div class="nb-kpi__label">Orders</div><div class="nb-kpi__value"><?= (int) $summaryRow['total_orders'] ?></div></div>
  <div class="nb-kpi"><div class="nb-kpi__label">Approved</div><div class="nb-kpi__value"><?= (int) $summaryRow['approved_orders'] ?></div></div>
  <div class="nb-kpi"><div class="nb-kpi__label">Rejected</div><div class="nb-kpi__value"><?= (int) $summaryRow['rejected_orders'] ?></div></div>
  <div class="nb-kpi"><div class="nb-kpi__label">Revenue</div><div class="nb-kpi__value nb-kpi__value--orange"><?= e(format_money($summaryRow['revenue'])) ?></div></div>
  <div class="nb-kpi"><div class="nb-kpi__label">New Students</div><div class="nb-kpi__value"><?= $newStudentsCount ?></div></div>
  <div class="nb-kpi"><div class="nb-kpi__label">Downloads</div><div class="nb-kpi__value"><?= $downloads ?></div></div>
</div>

<div class="nb-card" style="padding:0;">
  <div style="padding:16px 20px; border-bottom:1px solid var(--nb-line);"><h3 style="margin:0;">Sales by Course</h3></div>
  <?php if (!$byCourseRows): ?>
    <div class="nb-empty"><h3>No approved sales in this period</h3></div>
  <?php else: ?>
  <table class="nb-table">
    <thead><tr><th>Course</th><th>Units Sold</th><th>Revenue</th></tr></thead>
    <tbody>
    <?php foreach ($byCourseRows as $r): ?>
      <tr><td><span class="nb-mono"><?= e($r['code']) ?></span> — <?= e($r['title']) ?></td><td><?= (int) $r['units_sold'] ?></td><td><?= e(format_money($r['revenue'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<p class="nb-hint" style="margin-top:16px;">CSV export can be layered onto these same queries once the core reporting is confirmed against real data (see the guide, section 7.6).</p>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
