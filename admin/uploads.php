<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/watermark.php';

$admin = require_admin();
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $v = new Validator($_POST);
    $v->required('course_id', 'Course')->required('type', 'Document type')->in('type', ['NOTE', 'PAST_QUESTION'], 'Document type')
      ->required('title', 'Title')->maxLength('title', 190, 'Title')
      ->required('price', 'Price')->numeric('price', 'Price')->min('price', 0, 'Price');

    $uploadError = null;
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $uploadError = 'Please choose a PDF file to upload.';
    } else {
        $uploadError = validate_upload($_FILES['file'], ALLOWED_DOCUMENT_MIME, MAX_UPLOAD_BYTES);
    }

    if ($v->fails() || $uploadError) {
        flash_set('error', $v->firstError() ?? $uploadError);
    } else {
        // Random server-side filename — never trust or reuse the
        // client's filename, and never use the future DB id.
        $destName = random_filename('pdf');
        $destPath = STORAGE_ORIGINAL . '/' . $destName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $destPath)) {
            flash_set('error', 'Could not store the uploaded file. Please try again.');
        } else {
            @chmod($destPath, 0640);
            $pageCount = null;
            try {
                $pageCount = pdf_page_count($destPath);
            } catch (\Throwable $e) {
                error_log('NOTE BANK: page count failed for ' . $destPath . ': ' . $e->getMessage());
            }

            $stmt = $db->prepare(
                'INSERT INTO documents (course_id, type, title, description, file_path, page_count, price, status, created_by)
                 VALUES (:course_id, :type, :title, :description, :file_path, :page_count, :price, :status, :created_by)'
            );
            $stmt->execute([
                'course_id' => (int) $_POST['course_id'],
                'type' => $_POST['type'],
                'title' => trim($_POST['title']),
                'description' => trim($_POST['description'] ?? ''),
                'file_path' => $destPath,
                'page_count' => $pageCount,
                'price' => (float) $_POST['price'],
                'status' => isset($_POST['publish']) ? 'ACTIVE' : 'DRAFT',
                'created_by' => $admin['id'],
            ]);
            $newId = (int) $db->lastInsertId();
            log_audit($admin['id'], 'create', 'document', $newId, 'Uploaded ' . $_POST['title']);
            flash_set('success', 'Document uploaded' . (isset($_POST['publish']) ? ' and published.' : '. It is saved as a draft until you publish it.'));
            redirect('admin/documents.php?edit=' . $newId);
        }
    }
}

$courses = $db->query(
    "SELECT c.id, c.code, c.title, u.name AS university_name FROM courses c JOIN universities u ON u.id = c.university_id WHERE c.status = 'active' ORDER BY c.code"
)->fetchAll();

$pageTitle = 'Upload Document';
require __DIR__ . '/../includes/partials/admin_header.php';
?>

<div class="nb-card" style="padding:24px; max-width:640px;">
  <?php if (!$courses): ?>
    <div class="nb-empty"><h3>No active courses yet</h3><p>Create a course first (Academic Structure → Courses), then come back to upload notes or past questions.</p></div>
  <?php else: ?>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <div class="nb-field">
      <label class="nb-label" for="course_id">Course</label>
      <select class="nb-select" id="course_id" name="course_id" required>
        <option value="">Select course</option>
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['code'] . ' — ' . $c['title'] . ' (' . $c['university_name'] . ')') ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="nb-field">
      <label class="nb-label" for="type">Document Type</label>
      <select class="nb-select" id="type" name="type" required>
        <option value="NOTE">Note</option>
        <option value="PAST_QUESTION">Past Question</option>
      </select>
    </div>
    <div class="nb-field">
      <label class="nb-label" for="title">Title</label>
      <input class="nb-input" id="title" name="title" required placeholder="e.g. Complete Lecture Note — Units 1 to 8">
    </div>
    <div class="nb-field">
      <label class="nb-label" for="description">Description</label>
      <textarea class="nb-textarea" id="description" name="description" placeholder="What this document covers, page count, exam relevance, etc."></textarea>
    </div>
    <div class="nb-field">
      <label class="nb-label" for="price">Price (<?= e(setting('default_currency', 'XAF')) ?>)</label>
      <input class="nb-input" id="price" name="price" type="number" step="0.01" min="0" required placeholder="1500.00">
    </div>
    <div class="nb-field">
      <label class="nb-label" for="file">PDF File</label>
      <input type="file" id="file" name="file" accept="application/pdf" required data-max-mb="25">
      <p class="nb-hint" data-file-label-for="file">PDF only, up to 25 MB. Stored outside the public web root with a randomized filename.</p>
    </div>
    <div class="nb-field">
      <label style="display:flex; align-items:center; gap:8px; font-weight:600; font-size:0.9rem;">
        <input type="checkbox" name="publish" value="1" checked style="accent-color:var(--nb-orange-500);">
        Publish immediately (uncheck to save as a draft)
      </label>
    </div>
    <button type="submit" class="nb-btn nb-btn--primary nb-btn--block" data-loading-text="Uploading…">Upload Document</button>
  </form>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/partials/admin_footer.php'; ?>
