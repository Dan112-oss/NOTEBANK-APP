<?php
/**
 * SimplePdf — a tiny, dependency-free PDF writer.
 *
 * Note Bank generates two kinds of PDF at runtime: watermarked
 * preview/download documents (built from rasterized, watermarked page
 * images) and branded invoices (text, rules and the Note Bank logo).
 * Both are simple enough not to need a full library, and writing them
 * by hand means the security-critical watermark pipeline works the
 * moment the project is unzipped, with no Composer/network step
 * required before the first document can be protected.
 *
 * Supports: base-14 Helvetica/Helvetica-Bold text, straight lines,
 * filled rectangles, and full-bleed baseline JPEG images. That is
 * everything invoice.php and watermark.php need.
 *
 * Coordinates passed to the public API are "from the top" (y grows
 * downward), matching how most people lay out a page — the class
 * converts to PDF's bottom-left origin internally.
 */

declare(strict_types=1);

final class SimplePdf
{
    /** @var array<int, array{dict: string, stream: ?string}> */
    private array $objects = [];

    /** @var int[] object numbers of each finished page, in order */
    private array $pages = [];

    private ?int $fontRegular = null;
    private ?int $fontBold = null;

    private float $pageW;
    private float $pageH;

    /** @var string[] content stream operators for the page being built */
    private array $ops = [];

    /** @var array<string,int> XObject name => object number, for the current page */
    private array $pageXObjects = [];

    private int $xobjCounter = 0;
    private bool $pageOpen = false;

    public function __construct(float $widthPt = 595.28, float $heightPt = 841.89)
    {
        $this->pageW = $widthPt;
        $this->pageH = $heightPt;
    }

    public function addPage(?float $widthPt = null, ?float $heightPt = null): void
    {
        $this->ensureFonts();
        if ($this->pageOpen) {
            $this->flushPage();
        }
        if ($widthPt !== null) {
            $this->pageW = $widthPt;
        }
        if ($heightPt !== null) {
            $this->pageH = $heightPt;
        }
        $this->ops = [];
        $this->pageXObjects = [];
        $this->pageOpen = true;
    }

    public function pageWidth(): float
    {
        return $this->pageW;
    }

    public function pageHeight(): float
    {
        return $this->pageH;
    }

    public function text(float $x, float $yFromTop, string $text, float $size = 11, bool $bold = false, array $rgb = [17, 24, 38]): void
    {
        $font = $bold ? 'FB' : 'FR';
        $y = $this->pageH - $yFromTop;
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $this->ops[] = sprintf(
            'q %.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET Q',
            $r, $g, $b, $font, $size, $x, $y, $this->escapeText($text)
        );
    }

    /**
     * Naive word-wrap using an average Helvetica glyph-width factor
     * (good enough for invoice labels/titles; not a full AFM metrics
     * table, which would be overkill here).
     *
     * @return string[] wrapped lines
     */
    public function wrap(string $text, float $maxWidthPt, float $size): array
    {
        $avgCharWidth = $size * 0.52;
        $maxChars = max(4, (int) floor($maxWidthPt / $avgCharWidth));
        return $this->wordwrap($text, $maxChars);
    }

    private function wordwrap(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines ?: [''];
    }

    public function line(float $x1, float $y1FromTop, float $x2, float $y2FromTop, float $widthPt = 0.75, array $rgb = [200, 205, 214]): void
    {
        $y1 = $this->pageH - $y1FromTop;
        $y2 = $this->pageH - $y2FromTop;
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $this->ops[] = sprintf(
            'q %.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S Q',
            $r, $g, $b, $widthPt, $x1, $y1, $x2, $y2
        );
    }

    public function rectFill(float $x, float $yFromTop, float $w, float $h, array $rgb): void
    {
        $y = $this->pageH - $yFromTop - $h;
        [$r, $g, $b] = [$rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255];
        $this->ops[] = sprintf('q %.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f Q', $r, $g, $b, $x, $y, $w, $h);
    }

    /**
     * Draw a full-bleed baseline RGB JPEG. $jpegPath must already be a
     * JPEG (use imagejpeg() upstream) so it can be embedded verbatim
     * with /Filter /DCTDecode — no re-encoding needed.
     */
    public function image(string $jpegPath, float $x, float $yFromTop, float $w, float $h): void
    {
        $bytes = file_get_contents($jpegPath);
        if ($bytes === false) {
            throw new RuntimeException("Cannot read image for PDF embedding: {$jpegPath}");
        }
        $info = getimagesize($jpegPath);
        if ($info === false) {
            throw new RuntimeException("Not a valid image: {$jpegPath}");
        }
        [$pxW, $pxH] = $info;
        $channels = $info['channels'] ?? 3;
        $colorSpace = $channels === 1 ? '/DeviceGray' : '/DeviceRGB';

        $dict = sprintf(
            '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
            $pxW, $pxH, $colorSpace, strlen($bytes)
        );
        $objNum = $this->addObject($dict, $bytes);

        $this->xobjCounter++;
        $name = 'Im' . $this->xobjCounter;
        $this->pageXObjects[$name] = $objNum;

        $y = $this->pageH - $yFromTop - $h;
        $this->ops[] = sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, $y, $name);
    }

    public function save(string $path): void
    {
        if ($this->pageOpen) {
            $this->flushPage();
        }

        $catalogObj = $this->addObject('__CATALOG__'); // placeholder, patched below
        $pagesObj = $this->addObject('__PAGES__');

        $kids = implode(' ', array_map(fn($n) => "{$n} 0 R", $this->pages));
        $this->objects[$pagesObj - 1]['dict'] = sprintf(
            '<< /Type /Pages /Kids [%s] /Count %d >>',
            $kids,
            count($this->pages)
        );
        $this->objects[$catalogObj - 1]['dict'] = sprintf(
            '<< /Type /Catalog /Pages %d 0 R >>',
            $pagesObj
        );

        foreach ($this->pages as $pageObjNum) {
            $this->objects[$pageObjNum - 1]['dict'] = str_replace(
                '/Parent 0 0 R',
                "/Parent {$pagesObj} 0 R",
                $this->objects[$pageObjNum - 1]['dict']
            );
        }

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($this->objects as $i => $obj) {
            $num = $i + 1;
            $offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$obj['dict']}\n";
            if ($obj['stream'] !== null) {
                $out .= "stream\n" . $obj['stream'] . "\nendstream\n";
            }
            $out .= "endobj\n";
        }

        $xrefStart = strlen($out);
        $count = count($this->objects) + 1;
        $out .= "xref\n0 {$count}\n";
        $out .= "0000000000 65535 f \n";
        for ($n = 1; $n < $count; $n++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$n]);
        }
        $out .= "trailer\n<< /Size {$count} /Root {$catalogObj} 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $out);
    }

    private function ensureFonts(): void
    {
        if ($this->fontRegular === null) {
            $this->fontRegular = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        }
        if ($this->fontBold === null) {
            $this->fontBold = $this->addObject('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        }
    }

    private function flushPage(): void
    {
        $content = implode("\n", $this->ops);
        $contentObj = $this->addObject('', $content, true);

        $xobjEntries = '';
        foreach ($this->pageXObjects as $name => $num) {
            $xobjEntries .= "/{$name} {$num} 0 R ";
        }

        $resources = sprintf(
            '<< /Font << /FR %d 0 R /FB %d 0 R >> /XObject << %s >> /ProcSet [/PDF /Text /ImageC] >>',
            $this->fontRegular,
            $this->fontBold,
            trim($xobjEntries)
        );

        $pageDict = sprintf(
            '<< /Type /Page /Parent 0 0 R /MediaBox [0 0 %.2F %.2F] /Resources %s /Contents %d 0 R >>',
            $this->pageW,
            $this->pageH,
            $resources,
            $contentObj
        );
        $pageObj = $this->addObject($pageDict);
        $this->pages[] = $pageObj;
        $this->pageOpen = false;
    }

    private function addObject(string $dict, ?string $stream = null, bool $isPlainStream = false): int
    {
        if ($isPlainStream) {
            $dict = sprintf('<< /Length %d >>', strlen($stream ?? ''));
        }
        $this->objects[] = ['dict' => $dict, 'stream' => $stream];
        return count($this->objects);
    }

    private function escapeText(string $s): string
    {
        // Base-14 fonts use WinAnsi; transliterate anything outside it
        // rather than emit bytes that would corrupt the stream.
        $s = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s);
        if ($s === false) {
            $s = preg_replace('/[^\x20-\x7E]/', '?', $s) ?? '';
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    }
}
