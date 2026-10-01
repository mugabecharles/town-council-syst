<?php
/**
 * TCMS Pure-PHP PDF Generator
 * Generates PDF using raw PDF syntax — zero external libraries.
 * Supports: text, lines, rectangles, font styles, page breaks.
 */

class TcmsPdf {

    private array  $pages       = [];
    private int    $currentPage = 0;
    private array  $objects     = [];
    private int    $objCount    = 0;
    private string $fontFamily  = 'Helvetica';
    private float  $fontSize    = 10;
    private array  $textBuffer  = [];
    private float  $pageW       = 595.28;  // A4 width  (pts)
    private float  $pageH       = 841.89;  // A4 height (pts)
    private float  $marginL     = 50;
    private float  $marginR     = 50;
    private float  $marginT     = 50;
    private float  $marginB     = 50;
    private float  $curY;
    private float  $lineHeight  = 14;
    private string $title       = 'TCMS Document';
    private string $author      = 'Kijura Town Council';
    private array  $fillColor   = [0, 0, 0];    // RGB 0-1
    private array  $strokeColor = [0, 0, 0];
    private array  $textColor   = [0, 0, 0];
    private bool   $bold        = false;
    private bool   $italic      = false;

    public function __construct(string $title = 'TCMS Document') {
        $this->title = $title;
        $this->curY  = $this->marginT;
        $this->addPage();
    }

    // ── Page management ──────────────────────────────────────────

    public function addPage(): void {
        $this->currentPage++;
        $this->pages[$this->currentPage] = '';
        $this->curY = $this->marginT;
    }

    public function getPageWidth():  float { return $this->pageW; }
    public function getPageHeight(): float { return $this->pageH; }
    public function getInnerWidth():  float { return $this->pageW - $this->marginL - $this->marginR; }
    public function getCurrentY():   float { return $this->curY; }
    public function setY(float $y):  void  { $this->curY = $y; }

    public function checkPageBreak(float $needed = 20): void {
        if ($this->curY + $needed > $this->pageH - $this->marginB) {
            $this->addPage();
        }
    }

    // ── Font & Color ─────────────────────────────────────────────

    public function setFont(string $family, string $style = '', float $size = 10): void {
        $this->bold   = str_contains(strtoupper($style), 'B');
        $this->italic = str_contains(strtoupper($style), 'I');
        $this->fontSize = $size;
        $this->lineHeight = $size * 1.4;
    }

    public function setFontSize(float $size): void {
        $this->fontSize   = $size;
        $this->lineHeight = $size * 1.4;
    }

    public function setTextColor(int $r, int $g, int $b): void {
        $this->textColor = [$r/255, $g/255, $b/255];
    }

    public function setFillColor(int $r, int $g, int $b): void {
        $this->fillColor = [$r/255, $g/255, $b/255];
    }

    public function setDrawColor(int $r, int $g, int $b): void {
        $this->strokeColor = [$r/255, $g/255, $b/255];
    }

    private function getFontName(): string {
        if ($this->bold && $this->italic) return 'Helvetica-BoldOblique';
        if ($this->bold)   return 'Helvetica-Bold';
        if ($this->italic) return 'Helvetica-Oblique';
        return 'Helvetica';
    }

    // ── Drawing primitives ───────────────────────────────────────

    private function w(string $content): void {
        $this->pages[$this->currentPage] .= $content;
    }

    public function rect(float $x, float $y, float $w, float $h,
                         string $style = 'D'): void {
        $py = $this->pageH - $y - $h;
        [$fr, $fg, $fb] = $this->fillColor;
        [$sr, $sg, $sb] = $this->strokeColor;
        $styleUpper = strtoupper($style);
        if ($styleUpper === 'F') { $op = 'f'; }
        elseif ($styleUpper === 'FD' || $styleUpper === 'DF') { $op = 'B'; }
        else { $op = 'S'; }
        $this->w(sprintf(
            "%.4f %.4f %.4f rg %.4f %.4f %.4f RG %.2f %.2f %.2f %.2f re %s\n",
            $fr, $fg, $fb, $sr, $sg, $sb, $x, $py, $w, $h, $op
        ));
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void {
        [$sr, $sg, $sb] = $this->strokeColor;
        $py1 = $this->pageH - $y1;
        $py2 = $this->pageH - $y2;
        $this->w(sprintf(
            "%.4f %.4f %.4f RG %.2f %.2f m %.2f %.2f l S\n",
            $sr, $sg, $sb, $x1, $py1, $x2, $py2
        ));
    }

    public function setLineWidth(float $w): void {
        $this->w(sprintf("%.2f w\n", $w));
    }

    // ── Text ─────────────────────────────────────────────────────

    public function text(float $x, float $y, string $txt): void {
        $txt = $this->sanitizeText($txt);
        [$tr, $tg, $tb] = $this->textColor;
        $py = $this->pageH - $y;
        $font = $this->getFontName();
        $this->w(sprintf(
            "BT /F1 %.2f Tf %.4f %.4f %.4f rg %.2f %.2f Td (%s) Tj ET\n",
            $this->fontSize, $tr, $tg, $tb, $x, $py, $txt
        ));
    }

    /** Write a cell (text in a box, optional border/fill) */
    public function cell(float $w, float $h, string $txt,
                         string $border = '0', string $align = 'L',
                         bool $fill = false, bool $newline = false): void {
        $x = $this->marginL;
        $y = $this->curY;

        // Fill background
        if ($fill) {
            $this->rect($x, $y, $w, $h, 'F');
        }

        // Border
        if ($border === '1' || $border === 'B') {
            $this->setDrawColor(180, 180, 180);
            $this->rect($x, $y, $w, $h, 'D');
        } elseif ($border === 'B') {
            // bottom only
            $this->setDrawColor(180, 180, 180);
            $this->line($x, $y + $h, $x + $w, $y + $h);
        }

        // Text position
        $textX = $x + 3;
        if ($align === 'C') $textX = $x + ($w - $this->getTextWidth($txt)) / 2;
        if ($align === 'R') $textX = $x + $w - $this->getTextWidth($txt) - 3;
        $textY = $y + $h - ($h - $this->fontSize) / 2 - 1;

        $this->text($textX, $textY, $txt);

        if ($newline) {
            $this->curY += $h;
        }
    }

    /** Multi-cell with word wrap */
    public function multiCell(float $w, float $h, string $txt,
                              string $border = '0', string $align = 'L',
                              bool $fill = false): void {
        $words    = explode(' ', $txt);
        $lines    = ['']; $li = 0;
        foreach ($words as $word) {
            $test = trim($lines[$li] . ' ' . $word);
            if ($this->getTextWidth($test) > $w - 6 && $lines[$li] !== '') {
                $li++; $lines[$li] = $word;
            } else {
                $lines[$li] = $test;
            }
        }
        foreach ($lines as $line) {
            $this->checkPageBreak($h);
            $this->cell($w, $h, $line, $border, $align, $fill, true);
        }
    }

    /** ln() — move current Y down by lineHeight or custom amount */
    public function ln(float $h = 0): void {
        $this->curY += $h > 0 ? $h : $this->lineHeight;
    }

    private function getTextWidth(string $txt): float {
        // Approximate: Helvetica avg char width ≈ fontSize * 0.55
        return strlen($txt) * $this->fontSize * 0.52;
    }

    private function sanitizeText(string $txt): string {
        $txt = mb_convert_encoding($txt, 'ISO-8859-1', 'UTF-8');
        $txt = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $txt);
        // Remove non-printable
        return preg_replace('/[^\x20-\x7E]/', '', $txt);
    }

    // ── Image placeholder (for logo area) ───────────────────────

    public function imageBox(float $x, float $y, float $w, float $h,
                             string $label = 'TC'): void {
        $this->setFillColor(26, 58, 92);
        $this->rect($x, $y, $w, $h, 'F');
        $this->setTextColor(200, 168, 75);
        $this->setFont('', 'B', $h * 0.45);
        $tx = $x + ($w - $this->getTextWidth($label)) / 2;
        $ty = $y + $h * 0.65;
        $this->text($tx, $ty, $label);
        $this->setTextColor(0, 0, 0);
    }

    // ── Output ───────────────────────────────────────────────────

    public function output(string $mode = 'I', string $filename = 'document.pdf'): void {
        $pdf = $this->buildPdf();
        switch (strtoupper($mode)) {
            case 'I': // inline
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $filename . '"');
                header('Cache-Control: private, max-age=0, must-revalidate');
                echo $pdf;
                break;
            case 'D': // download
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($pdf));
                echo $pdf;
                break;
            case 'S': // string
                echo $pdf;
                break;
            case 'F': // file
                file_put_contents($filename, $pdf);
                break;
        }
    }

    private function buildPdf(): string {
        $this->objCount = 0;
        $xrefs = [];
        $body  = '';

        // Helper to add object
        $addObj = function(string $content) use (&$body, &$xrefs): int {
            $this->objCount++;
            $xrefs[$this->objCount] = strlen($body) + 9; // offset after header
            $body .= "{$this->objCount} 0 obj\n$content\nendobj\n";
            return $this->objCount;
        };

        // Catalog
        $catId = $addObj("<< /Type /Catalog /Pages 2 0 R >>");
        // Pages (placeholder — updated later)
        $pagesId = $addObj("<< /Type /Pages /Count " . count($this->pages) . " /Kids [" .
            implode(' ', array_map(fn($i) => ($i + 3) . " 0 R", array_keys($this->pages))) .
            "] >>");

        // Font
        $fontId = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>");
        $fontBId = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>");
        $fontIId = $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>");
        $fontBIId= $addObj("<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-BoldOblique /Encoding /WinAnsiEncoding >>");

        // Page objects
        foreach ($this->pages as $pageNum => $content) {
            // Resources for this page
            $resId = $addObj("<< /Font << /F1 {$fontId} 0 R /F2 {$fontBId} 0 R >> >>");
            // Stream
            $stream = $content;
            $streamId = $addObj("<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream");
            // Page dict
            $addObj("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$this->pageW} {$this->pageH}] " .
                    "/Contents {$streamId} 0 R " .
                    "/Resources << /Font << /F1 {$fontId} 0 R /F2 {$fontBId} 0 R /F3 {$fontIId} 0 R /F4 {$fontBIId} 0 R >> >> >>");
        }

        // Info
        $infoId = $addObj("<< /Title (" . $this->sanitizeText($this->title) . ") " .
                          "/Author (" . $this->sanitizeText($this->author) . ") " .
                          "/CreationDate (D:" . date('YmdHis') . ") >>");

        // Build final PDF
        $header   = "%PDF-1.4\n%âãÏÓ\n";
        $xrefPos  = strlen($header) + strlen($body);
        $xrefTable = "xref\n0 " . ($this->objCount + 1) . "\n0000000000 65535 f \n";
        foreach ($xrefs as $id => $offset) {
            $xrefTable .= str_pad($offset + strlen($header), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $trailer = "trailer\n<< /Size " . ($this->objCount + 1) .
                   " /Root {$catId} 0 R /Info {$infoId} 0 R >>\n" .
                   "startxref\n{$xrefPos}\n%%EOF";

        return $header . $body . $xrefTable . $trailer;
    }
}
