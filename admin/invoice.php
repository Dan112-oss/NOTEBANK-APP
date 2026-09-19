<?php
/** Streams an order's invoice PDF to an authenticated admin. */
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$orderId = (int) ($_GET['order_id'] ?? 0);
$stmt = get_db()->prepare('SELECT file_path, invoice_number FROM invoices WHERE order_id = :id');
$stmt->execute(['id' => $orderId]);
$invoice = $stmt->fetch();

if (!$invoice || !is_file($invoice['file_path'])) {
    http_response_code(404);
    die('Invoice not found.');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($invoice['file_path']));
header('Content-Disposition: inline; filename="' . $invoice['invoice_number'] . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($invoice['file_path']);
