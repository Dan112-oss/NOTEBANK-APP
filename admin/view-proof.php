<?php
/**
 * Streams a payment proof file to an authenticated admin only.
 * storage/ is not web-accessible directly (see storage/.htaccess) —
 * this is the one gate through which reviewers can see evidence.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = get_db()->prepare('SELECT proof_path FROM payment_proofs WHERE id = :id');
$stmt->execute(['id' => $id]);
$path = $stmt->fetchColumn();

if (!$path || !is_file($path)) {
    http_response_code(404);
    die('Payment evidence not found.');
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($path) ?: 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
