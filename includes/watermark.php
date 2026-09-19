<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/pdfwriter.php';
require_once __DIR__ . '/functions.php';

class WatermarkException extends RuntimeException
{
}

function fpdi_available(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }

    $vendorAutoload = BASE_PATH . '/vendor/autoload.php';
    if (is_file($vendorAutoload)) {
        require_once $vendorAutoload;
    }

    return $available = class_exists(\setasign\Fpdi\Tcpdf\Fpdi::class);
}


function resolve_binary(string $configured, string $defaultName): ?string
{
    $candidate = $configured !== '' ? $configured : $defaultName;

    if (($candidate !== $defaultName) && (is_file($candidate) || is_file($candidate . '.exe'))) {
        return is_file($candidate) ? $candidate : $candidate . '.exe';
    }

    // Hosts like InfinityFree disable shell_exec() entirely (it's not just
    // blocked — the function doesn't exist), so calling it unconditionally
    // is a fatal error, not a graceful "not found". Guard it.
    if (!function_exists('shell_exec') || !is_callable('shell_exec')) {
        return null;
    }

    $isWindows = stripos(PHP_OS_FAMILY ?? PHP_OS, 'WIN') !== false;
    $lookupCmd = ($isWindows ? 'where ' : 'command -v ') . escapeshellarg($candidate);
    $lookupCmd .= $isWindows ? ' 2>NUL' : ' 2>/dev/null';

    $found = trim((string) shell_exec($lookupCmd));
    if ($found === '') {
        return null;
    }
  
    $lines = preg_split('/\r?\n/', $found);
    return trim((string) $lines[0]);
}


function pdf_page_count(string $path): int
{
    if (fpdi_available()) {
        try {
            $fpdi = new \setasign\Fpdi\Tcpdf\Fpdi();
            $count = $fpdi->setSourceFile($path);
            $fpdi->cleanUp();
            return $count;
        } catch (\Throwable $e) {
            throw new WatermarkException('Unable to read PDF metadata: ' . $e->getMessage());
        }
    }

    $bin = resolve_binary(PDFINFO_BIN, 'pdfinfo');
    if ($bin === null) {
        throw new WatermarkException('pdfinfo (poppler-utils) is not installed on the server.');
    }
    if (!function_exists('shell_exec')) {
        throw new WatermarkException('shell_exec() is disabled on this server.');
    }
    $cmd = escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = shell_exec($cmd);
    if ($output === null) {
        throw new WatermarkException('Unable to read PDF metadata.');
    }
    if (preg_match('/^Pages:\s*(\d+)/m', $output, $m)) {
        return (int) $m[1];
    }
    throw new WatermarkException('Unable to determine page count.');
}


/**
 * @return string[]
 */
function rasterize_pdf(string $srcPath, string $outDir, int $maxPages = 0, int $dpi = 150): array
{
    $bin = resolve_binary(PDFTOPPM_BIN, 'pdftoppm');
    if ($bin === null) {
        throw new WatermarkException('pdftoppm (poppler-utils) is not installed on the server.');
    }

    if (!is_dir($outDir)) {
        mkdir($outDir, 0755, true);
    }
    $prefix = $outDir . '/page';

    $totalPages = pdf_page_count($srcPath);
    $lastPage = $maxPages > 0 ? min($maxPages, $totalPages) : $totalPages;

    $cmd = sprintf(
        '%s -png -r %d -f 1 -l %d %s %s 2>&1',
        escapeshellarg($bin),
        $dpi,
        $lastPage,
        escapeshellarg($srcPath),
        escapeshellarg($prefix)
    );
    if (!function_exists('exec')) {
        throw new WatermarkException('exec() is disabled on this server.');
    }
    exec($cmd, $out, $status);
    if ($status !== 0) {
        throw new WatermarkException('PDF rendering failed: ' . implode("\n", $out));
    }

    $files = glob($prefix . '-*.png') ?: glob($prefix . '*.png') ?: [];
    natsort($files);
    return array_values($files);
}

/**
 * Stamp a tiled, rotated, translucent watermark onto a page image and
 * write it out as a JPEG (ready for embedding in the reassembled PDF).
 */
function watermark_image(string $srcImagePath, array $lines, string $destJpegPath, string $mode = 'download'): void
{
    $src = imagecreatefrompng($srcImagePath);
    if ($src === false) {
        $src = imagecreatefromstring((string) file_get_contents($srcImagePath));
    }
    if ($src === false) {
        throw new WatermarkException('Could not open rasterized page image.');
    }

    $w = imagesx($src);
    $h = imagesy($src);

    // Build a transparent overlay the same size, tile the watermark
    // text across it, then rotate the whole overlay for a diagonal
    // stamp — GD's built-in fonts need no bundled TTF file.
    $overlaySize = (int) (max($w, $h) * 1.6);
    $overlay = imagecreatetruecolor($overlaySize, $overlaySize);
    imagesavealpha($overlay, true);
    $transparent = imagecolorallocatealpha($overlay, 0, 0, 0, 127);
    imagefill($overlay, 0, 0, $transparent);

    // Preview: dense and dark enough that a screenshot doesn't give a
    // clean read of the underlying content — that's the point of a
    // free preview. Download: light and sparse, since this is the
    // paying student's actual copy and shouldn't fight the content for
    // attention; the watermark there is for traceability, not to
    // obstruct reading.
    $gdAlpha = $mode === 'preview' ? 60 : 112; // GD alpha: 0 opaque, 127 fully transparent
    $font = 5; // largest built-in GD font (no bundled TTF dependency)
    $lineHeight = imagefontheight($font) + ($mode === 'preview' ? 16 : 22);
    $stepX = $mode === 'preview' ? 230 : 340;

    $ink = imagecolorallocatealpha($overlay, 11, 37, 89, $gdAlpha); // deep navy
    $stepY = $lineHeight * (count($lines) + 2);

    // GD's built-in bitmap fonts only cover ISO-8859-1 — transliterate
    // so characters like em dashes/bullets don't render as mojibake.
    $asciiLines = array_map(static function (string $line): string {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $line);
        return $ascii !== false ? $ascii : preg_replace('/[^\x20-\x7E]/', '-', $line);
    }, $lines);

    for ($y = 0; $y < $overlaySize; $y += $stepY) {
        foreach ($asciiLines as $i => $line) {
            for ($x = 0; $x < $overlaySize; $x += $stepX) {
                $offset = ($i % 2 === 0) ? 0 : (int) ($stepX / 2);
                imagestring($overlay, $font, $x + $offset, $y + $i * $lineHeight, $line, $ink);
            }
        }
    }

    $rotated = imagerotate($overlay, 30, $transparent);
    imagesavealpha($rotated, true);
    $rw = imagesx($rotated);
    $rh = imagesy($rotated);

    // Composite the rotated overlay centered over the page image.
    imagecopy($src, $rotated, -(int) (($rw - $w) / 2), -(int) (($rh - $h) / 2), 0, 0, $rw, $rh);

    imagejpeg($src, $destJpegPath, 88);
    imagedestroy($src);
    imagedestroy($overlay);
    imagedestroy($rotated);
}

/** Read a JPEG's pixel dimensions, needed to size PDF pages correctly. */
function jpeg_size(string $path): array
{
    $info = getimagesize($path);
    if ($info === false) {
        throw new WatermarkException("Cannot read image size: {$path}");
    }
    return [$info[0], $info[1]];
}

/**
 * Full watermarking pipeline for one document. Dispatches to whichever
 * implementation is actually usable on this server — see the file-level
 * doc comment for why there are two.
 */
function build_watermarked_pdf(string $srcPdfPath, string $destPdfPath, array $watermarkLines, int $maxPages = 0, int $dpi = 150, string $mode = 'download'): int
{
    if (fpdi_available()) {
        return build_watermarked_pdf_fpdi($srcPdfPath, $destPdfPath, $watermarkLines, $maxPages, $mode);
    }
    return build_watermarked_pdf_raster($srcPdfPath, $destPdfPath, $watermarkLines, $maxPages, $dpi, $mode);
}

/**
 * PATH A: pure PHP, no shell commands. FPDI imports each source page
 * as a vector template (original text/images stay crisp — nothing is
 * rasterized), TCPDF draws the tiled/rotated/translucent watermark on
 * top of each one. Required on hosts that disable exec()/shell_exec()
 * (e.g. InfinityFree and most free PHP hosts).
 */
function build_watermarked_pdf_fpdi(string $srcPdfPath, string $destPdfPath, array $watermarkLines, int $maxPages = 0, string $mode = 'download'): int
{
    try {
        // 'P'/'A4' are placeholders — every AddPage() below sets the
        // real per-page size, taken from the imported page itself.
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Note Bank');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setImageScale(1);

        // TCPDF unconditionally stamps a "Powered by TCPDF (www.tcpdf.org)"
        // credit line (real drawn text, 1pt tall, plus a tiny clickable
        // link) onto the last page when it closes the document — driven
        // by a protected property with no public setter. Not appropriate
        // for a paid, branded document a student is buying, so switch it
        // off via reflection before Output()/Close() runs.
        try {
            $tcpdflinkProp = new \ReflectionProperty($pdf, 'tcpdflink');
            $tcpdflinkProp->setAccessible(true);
            $tcpdflinkProp->setValue($pdf, false);
        } catch (\ReflectionException $e) {
            // If a future TCPDF release renames/removes this property,
            // fail soft — the watermark itself is unaffected either way.
        }

        $totalPages = $pdf->setSourceFile($srcPdfPath);
        if ($totalPages < 1) {
            throw new WatermarkException('Source PDF has no pages.');
        }
        $lastPage = $maxPages > 0 ? min($maxPages, $totalPages) : $totalPages;

        for ($pageNo = 1; $pageNo <= $lastPage; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $size = $pdf->getTemplateSize($templateId);
            $orientation = ($size['orientation'] ?? 'P') === 'L' ? 'L' : 'P';

            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($templateId, 0, 0, $size['width'], $size['height'], true);

            stamp_watermark_tcpdf($pdf, (float) $size['width'], (float) $size['height'], $watermarkLines, $mode);
        }

        $pdf->Output($destPdfPath, 'F');
        return $lastPage;
    } catch (WatermarkException $e) {
        throw $e;
    } catch (\Throwable $e) {
        throw new WatermarkException('FPDI watermarking failed: ' . $e->getMessage());
    }
}

/**
 * Draw the tiled, rotated, translucent watermark used by the FPDI path
 * directly onto a TCPDF/FPDI page — the vector equivalent of what
 * watermark_image() does with GD on a rasterized page.
 *
 * $mode controls how aggressive the stamp is:
 *   - 'preview'  — big, dense, and dark enough that a screenshot of the
 *                  free preview doesn't give away clean, readable
 *                  content. It's meant to sell the document, not give
 *                  it away for free via a screenshot.
 *   - 'download' — small, sparse, and light. This is the paying
 *                  student's actual copy; the watermark exists for
 *                  traceability if it leaks, not to fight the real
 *                  content for the reader's attention.
 */
function stamp_watermark_tcpdf(\TCPDF $pdf, float $pageWidthPt, float $pageHeightPt, array $lines, string $mode = 'download'): void
{
    if ($mode === 'preview') {
        $alpha = 0.45;
        $fontSize = 30;
        $lineHeight = 48;
        $stepX = 145;
    } else {
        $alpha = 0.07;
        $fontSize = 10;
        $lineHeight = 95;
        $stepX = 360;
    }

    $pdf->SetAlpha($alpha);
    $pdf->SetFont('helvetica', 'B', $fontSize);
    $pdf->SetTextColor(11, 37, 89);

    $blockHeight = $lineHeight * (count($lines) + 2);
    $margin = 250; // overshoot so rotated text still covers the corners

    for ($y = -$margin; $y < $pageHeightPt + $margin; $y += $blockHeight) {
        foreach ($lines as $i => $line) {
            $offset = ($i % 2 === 0) ? 0 : (int) ($stepX / 2);
            for ($x = -$margin; $x < $pageWidthPt + $margin; $x += $stepX) {
                $pdf->StartTransform();
                $pdf->Rotate(30, $x + $offset, $y + $i * $lineHeight);
                $pdf->Text($x + $offset, $y + $i * $lineHeight, $line);
                $pdf->StopTransform();
            }
        }
    }

    $pdf->SetAlpha(1);
}

/**
 * PATH B: rasterize -> watermark every page image with GD -> reassemble
 * as a single PDF at $destPdfPath with SimplePdf. Needs exec()/
 * shell_exec() and poppler-utils — used only when FPDI/TCPDF aren't
 * vendored (e.g. local dev without `composer install`).
 */
function build_watermarked_pdf_raster(string $srcPdfPath, string $destPdfPath, array $watermarkLines, int $maxPages = 0, int $dpi = 150, string $mode = 'download'): int
{
    $workDir = sys_get_temp_dir() . '/notebank_' . bin2hex(random_bytes(8));
    mkdir($workDir, 0755, true);

    try {
        $pageImages = rasterize_pdf($srcPdfPath, $workDir, $maxPages, $dpi);
        if (empty($pageImages)) {
            throw new WatermarkException('No pages were rendered from the source document.');
        }

        $pdf = new SimplePdf();
        foreach ($pageImages as $index => $pngPath) {
            $jpegPath = $workDir . '/wm-' . $index . '.jpg';
            watermark_image($pngPath, $watermarkLines, $jpegPath, $mode);

            [$pxW, $pxH] = jpeg_size($jpegPath);
            // Convert pixels at $dpi to PDF points (72pt = 1in).
            $ptW = $pxW * 72 / $dpi;
            $ptH = $pxH * 72 / $dpi;

            $pdf->addPage($ptW, $ptH);
            $pdf->image($jpegPath, 0, 0, $ptW, $ptH);
        }
        $pdf->save($destPdfPath);

        return count($pageImages);
    } finally {
        array_map('unlink', glob($workDir . '/*') ?: []);
        @rmdir($workDir);
    }
}

/**
 * Build the standard two-line watermark stamp used on both previews
 * and downloads: Note Bank + the viewer's identity, and a timestamp.
 */
function watermark_lines_for(string $identity): array
{
    return [
        'NOTE BANK - ' . $identity,
        date('Y-m-d H:i') . ' - Unauthorized redistribution prohibited',
    ];
}

/**
 * Generate (or reuse a fresh) preview PDF for a document, limited to
 * settings.preview_max_pages, watermarked with the student's identity.
 * Returns the absolute path to the preview PDF.
 */
function ensure_preview(array $document, string $studentIdentity): string
{
    $maxPages = (int) setting('preview_max_pages', '3');
    $previewPath = STORAGE_PREVIEW . '/doc' . $document['id'] . '_v' . $document['version'] . '.pdf';

    if (!is_file($previewPath)) {
        build_watermarked_pdf(
            $document['file_path'],
            $previewPath,
            watermark_lines_for($studentIdentity . ' • PREVIEW'),
            $maxPages,
            150,
            'preview'
        );
    }

    return $previewPath;
}

/**
 * Generate (or reuse a cached) fully watermarked download for one
 * entitlement. Cached under storage/processed/ keyed by entitlement id
 * and document version, so re-downloads are instant.
 */
function ensure_watermarked_download(array $document, array $entitlement, string $studentIdentity): string
{
    $processedPath = STORAGE_PROCESSED . '/entitlement' . $entitlement['id'] . '_v' . $document['version'] . '.pdf';

    if (!is_file($processedPath)) {
        build_watermarked_pdf(
            $document['file_path'],
            $processedPath,
            watermark_lines_for($studentIdentity),
            0,
            150,
            'download'
        );
    }

    return $processedPath;
}
