<?php
/**
 * Invoice/receipt generation. Called once, right after an admin
 * approves a payment (see admin/payments.php), and is idempotent —
 * calling it again for the same order reuses the existing invoice
 * number and simply regenerates the PDF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/pdfwriter.php';

const NOTEBANK_NAVY = [11, 37, 89];
const NOTEBANK_ORANGE = [249, 117, 9];
const NOTEBANK_INK = [30, 35, 45];
const NOTEBANK_MUTED = [110, 118, 132];

/**
 * Build (or rebuild) the invoice PDF for one order and record it in
 * the invoices table.
 *
 * @return array{invoice_number: string, file_path: string}
 */
function generate_invoice_for_order(int $orderId): array
{
    $db = get_db();

    $orderStmt = $db->prepare(
        'SELECT o.*, s.full_name AS student_name, s.email AS student_email, s.student_number,
                u.name AS university_name
         FROM orders o
         JOIN students s ON s.id = o.student_id
         JOIN universities u ON u.id = s.university_id
         WHERE o.id = :id LIMIT 1'
    );
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch();
    if (!$order) {
        throw new RuntimeException("Order {$orderId} not found.");
    }

    $itemsStmt = $db->prepare(
        'SELECT oi.*, d.title AS document_title, d.type AS document_type, c.code AS course_code
         FROM order_items oi
         JOIN documents d ON d.id = oi.document_id
         JOIN courses c ON c.id = d.course_id
         WHERE oi.order_id = :id'
    );
    $itemsStmt->execute(['id' => $orderId]);
    $items = $itemsStmt->fetchAll();

    $existing = $db->prepare('SELECT invoice_number FROM invoices WHERE order_id = :id LIMIT 1');
    $existing->execute(['id' => $orderId]);
    $existingRow = $existing->fetch();
    $invoiceNumber = $existingRow['invoice_number'] ?? generate_invoice_number();

    $fileName = 'invoice_' . $invoiceNumber . '.pdf';
    $filePath = STORAGE_RECEIPTS . '/' . $fileName;

    render_invoice_pdf($order, $items, $invoiceNumber, $filePath);

    $upsert = $db->prepare(
        'INSERT INTO invoices (order_id, invoice_number, file_path, issued_at)
         VALUES (:order_id, :invoice_number, :file_path, NOW())
         ON DUPLICATE KEY UPDATE file_path = VALUES(file_path)'
    );
    $upsert->execute([
        'order_id' => $orderId,
        'invoice_number' => $invoiceNumber,
        'file_path' => $filePath,
    ]);

    return ['invoice_number' => $invoiceNumber, 'file_path' => $filePath];
}

/** Right-align a short line of text ending at $rightEdge, using the same average-glyph-width heuristic as SimplePdf::wrap(). */
function pdf_text_right(SimplePdf $pdf, float $rightEdge, float $yFromTop, string $text, float $size, bool $bold, array $rgb): void
{
    $estimatedWidth = mb_strlen($text) * $size * ($bold ? 0.62 : 0.55);
    $pdf->text($rightEdge - $estimatedWidth, $yFromTop, $text, $size, $bold, $rgb);
}

function render_invoice_pdf(array $order, array $items, string $invoiceNumber, string $destPath): void
{
    $pdf = new SimplePdf(595.28, 841.89); // A4
    $pdf->addPage();

    // Header band.
    $pdf->rectFill(0, 0, 595.28, 110, NOTEBANK_NAVY);
    $logoPath = BASE_PATH . '/assets/images/logo-icon-invoice.jpg';
    if (is_file($logoPath)) {
        $pdf->image($logoPath, 40, 22, 66, 66);
    }
    $pdf->text(118, 50, setting('site_name', 'Note Bank'), 22, true, [255, 255, 255]);
    $pdf->text(118, 72, strtoupper(setting('site_tagline', 'Learn. Access. Succeed.')), 9, false, [220, 228, 245]);
    $pdf->text(400, 50, 'RECEIPT / INVOICE', 12, true, [255, 255, 255]);
    $pdf->text(400, 68, $invoiceNumber, 11, false, [220, 228, 245]);

    $y = 145;
    $pdf->text(40, $y, 'BILLED TO', 9, true, NOTEBANK_MUTED);
    $pdf->text(40, $y + 18, $order['student_name'], 12, true, NOTEBANK_INK);
    $pdf->text(40, $y + 35, $order['student_email'], 10, false, NOTEBANK_MUTED);
    if (!empty($order['student_number'])) {
        $pdf->text(40, $y + 50, 'Student No: ' . $order['student_number'], 10, false, NOTEBANK_MUTED);
    }
    $pdf->text(40, $y + 65, $order['university_name'], 10, false, NOTEBANK_MUTED);

    $pdf->text(360, $y, 'ORDER DETAILS', 9, true, NOTEBANK_MUTED);
    $pdf->text(360, $y + 18, 'Order Number: ' . $order['order_number'], 10, false, NOTEBANK_INK);
    $pdf->text(360, $y + 33, 'Order Date: ' . date('d M Y', strtotime($order['created_at'])), 10, false, NOTEBANK_INK);
    $pdf->text(360, $y + 48, 'Approved: ' . ($order['approved_at'] ? date('d M Y', strtotime($order['approved_at'])) : 'Pending'), 10, false, NOTEBANK_INK);
    $pdf->text(360, $y + 63, 'Status: ' . $order['status'], 10, true, NOTEBANK_INK);

    $tableTop = $y + 100;
    $pdf->rectFill(40, $tableTop, 515, 26, [244, 246, 249]);
    $pdf->text(48, $tableTop + 18, 'DOCUMENT', 9, true, NOTEBANK_MUTED);
    $pdf->text(325, $tableTop + 18, 'TYPE', 9, true, NOTEBANK_MUTED);
    $pdf->text(385, $tableTop + 18, 'QTY', 9, true, NOTEBANK_MUTED);
    pdf_text_right($pdf, 470, $tableTop + 18, 'UNIT PRICE', 9, true, NOTEBANK_MUTED);
    pdf_text_right($pdf, 548, $tableTop + 18, 'TOTAL', 9, true, NOTEBANK_MUTED);

    $rowY = $tableTop + 26;
    foreach ($items as $item) {
        $rowHeight = 30;
        $titleLines = $pdf->wrap($item['course_code'] . ' - ' . $item['document_title'], 260, 10);
        $rowHeight = max($rowHeight, 14 + count($titleLines) * 13);

        foreach ($titleLines as $i => $line) {
            $pdf->text(48, $rowY + 18 + $i * 13, $line, 10, false, NOTEBANK_INK);
        }
        $pdf->text(325, $rowY + 18, $item['document_type'] === 'NOTE' ? 'Note' : 'Past Q.', 10, false, NOTEBANK_INK);
        $pdf->text(385, $rowY + 18, (string) $item['quantity'], 10, false, NOTEBANK_INK);
        pdf_text_right($pdf, 470, $rowY + 18, format_money_pdf($item['unit_price'], $order['currency']), 10, false, NOTEBANK_INK);
        pdf_text_right($pdf, 548, $rowY + 18, format_money_pdf($item['total'], $order['currency']), 10, false, NOTEBANK_INK);
        $pdf->line(40, $rowY + $rowHeight, 555, $rowY + $rowHeight, 0.5, [230, 233, 238]);
        $rowY += $rowHeight;
    }

    $rowY += 20;
    $pdf->text(400, $rowY, 'Subtotal', 10, false, NOTEBANK_MUTED);
    pdf_text_right($pdf, 548, $rowY, format_money_pdf($order['subtotal'], $order['currency']), 10, false, NOTEBANK_INK);
    $rowY += 18;
    $pdf->line(400, $rowY - 6, 555, $rowY - 6, 0.5, [230, 233, 238]);
    $pdf->text(400, $rowY + 10, 'Total', 12, true, NOTEBANK_INK);
    pdf_text_right($pdf, 548, $rowY + 10, format_money_pdf($order['total'], $order['currency']), 12, true, NOTEBANK_ORANGE);

    $footerY = 780;
    $pdf->line(40, $footerY, 555, $footerY, 0.75, [230, 233, 238]);
    $pdf->text(40, $footerY + 18, 'Payment verified and approved by Note Bank administration.', 9, false, NOTEBANK_MUTED);
    $pdf->text(40, $footerY + 32, 'Questions about this receipt? Contact ' . setting('support_email', 'support@notebank.test'), 9, false, NOTEBANK_MUTED);

    $pdf->save($destPath);
}
