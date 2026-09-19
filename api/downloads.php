<?php
/**
 * GET /api/downloads.php?id={document}   — authorized, watermarked document download
 * GET /api/downloads.php?invoice={order} — a student's own invoice/receipt PDF
 *
 * Ownership is re-checked here regardless of what the UI already
 * implied (see the guide, section 9: "never expose the original PDF
 * through a predictable URL" and "verify... entitlement where required").
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/watermark.php';

$student = current_student();
if (!$student) {
    http_response_code(401);
    die('Please sign in to download this file.');
}

$db = get_db();

function stream_file(string $path, string $filename, string $mime): never
{
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if (isset($_GET['invoice'])) {
    $orderId = (int) $_GET['invoice'];
    $stmt = $db->prepare(
        'SELECT i.* FROM invoices i JOIN orders o ON o.id = i.order_id WHERE i.order_id = :order_id AND o.student_id = :sid'
    );
    $stmt->execute(['order_id' => $orderId, 'sid' => $student['id']]);
    $invoice = $stmt->fetch();
    if (!$invoice || !is_file($invoice['file_path'])) {
        http_response_code(404);
        die('Invoice not found.');
    }
    stream_file($invoice['file_path'], $invoice['invoice_number'] . '.pdf', 'application/pdf');
}

$documentId = (int) ($_GET['id'] ?? 0);

$entitlement = student_owns_entitlement($student['id'], $documentId);
if (!$entitlement) {
    http_response_code(403);
    die('You do not have access to this document. Purchase it first from the catalogue.');
}

$limit = (int) setting('download_limit_per_entitlement', '0');
if ($limit > 0 && (int) $entitlement['download_count'] >= $limit) {
    http_response_code(429);
    die('You have reached the download limit for this document. Contact support if you need it again.');
}

$stmt = $db->prepare('SELECT * FROM documents WHERE id = :id');
$stmt->execute(['id' => $documentId]);
$document = $stmt->fetch();
if (!$document) {
    http_response_code(404);
    die('Document not found.');
}

try {
    $filePath = ensure_watermarked_download($document, $entitlement, $student['email']);
} catch (\Throwable $e) {
    error_log('NOTE BANK download generation failed: ' . $e->getMessage());
    http_response_code(503);
    if (!IS_PRODUCTION) {
        // Local/dev only — see api/preview.php for why.
        die('Download generation failed: ' . htmlspecialchars($e->getMessage()));
    }
    die('Your download could not be prepared right now. Please try again shortly.');
}

// Log the download and bump counters — every successful download is
// audited (guide, section 9).
$db->prepare(
    'INSERT INTO downloads (entitlement_id, student_id, document_id, ip_address, user_agent, downloaded_at)
     VALUES (:eid, :sid, :did, :ip, :ua, NOW())'
)->execute([
    'eid' => $entitlement['id'],
    'sid' => $student['id'],
    'did' => $documentId,
    'ip' => client_ip(),
    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
]);
$db->prepare('UPDATE entitlements SET download_count = download_count + 1, last_download_at = NOW() WHERE id = :id')
   ->execute(['id' => $entitlement['id']]);

$safeName = preg_replace('/[^A-Za-z0-9 _.-]/', '', $document['title']) ?: 'document';
stream_file($filePath, $safeName . '.pdf', 'application/pdf');
