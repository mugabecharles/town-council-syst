<?php
/**
 * TCMS Excel/CSV Export Engine
 * Generates native .xlsx files using pure PHP (no library).
 * Also supports CSV as fallback.
 *
 * Usage:
 *   $xl = new TcmsExcel('Revenue Report');
 *   $xl->addSheet('By Ward');
 *   $xl->setHeaders(['Ward','Collected','Transactions']);
 *   $xl->addRow(['Ward 1', 1500000, 25]);
 *   $xl->download('revenue-report.xlsx');
 */

class TcmsExcel {

    private string $title;
    private array  $sheets     = [];   // [['name'=>, 'headers'=>[], 'rows'=>[]]]
    private int    $curSheet   = -1;

    // Column style: 'number','currency','date','percent','text'
    private array  $colStyles  = [];

    public function __construct(string $title = 'TCMS Export') {
        $this->title = $title;
    }

    public function addSheet(string $name = 'Sheet1'): self {
        $this->curSheet++;
        $this->sheets[$this->curSheet] = [
            'name'    => $this->sanitizeSheetName($name),
            'headers' => [],
            'rows'    => [],
            'styles'  => [],
        ];
        $this->colStyles = [];
        return $this;
    }

    public function setHeaders(array $headers): self {
        if ($this->curSheet < 0) $this->addSheet();
        $this->sheets[$this->curSheet]['headers'] = $headers;
        return $this;
    }

    public function setColStyles(array $styles): self {
        // e.g. ['text','currency','number','date']
        $this->colStyles = $styles;
        $this->sheets[$this->curSheet]['styles'] = $styles;
        return $this;
    }

    public function addRow(array $row): self {
        if ($this->curSheet < 0) $this->addSheet();
        $this->sheets[$this->curSheet]['rows'][] = $row;
        return $this;
    }

    public function addRows(array $rows): self {
        foreach ($rows as $row) $this->addRow($row);
        return $this;
    }

    public function addSummaryRow(array $row): self {
        if ($this->curSheet < 0) $this->addSheet();
        $this->sheets[$this->curSheet]['rows'][] = ['__summary__' => true, 'data' => $row];
        return $this;
    }

    // ── Download as .xlsx ────────────────────────────────────────

    public function download(string $filename = 'export.xlsx'): void {
        if (!str_ends_with(strtolower($filename), '.xlsx')) $filename .= '.xlsx';
        $xlsx = $this->buildXlsx();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: private, max-age=0, must-revalidate');
        echo $xlsx;
        exit;
    }

    // ── Download as CSV (single sheet) ───────────────────────────

    public function downloadCsv(string $filename = 'export.csv'): void {
        if ($this->curSheet < 0) return;
        $sheet = $this->sheets[0];
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private');
        // BOM for Excel UTF-8 compatibility
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if (!empty($sheet['headers'])) fputcsv($out, $sheet['headers']);
        foreach ($sheet['rows'] as $row) {
            if (isset($row['__summary__'])) {
                fputcsv($out, $row['data']);
            } else {
                fputcsv($out, $row);
            }
        }
        fclose($out);
        exit;
    }

    // ── Build XLSX (ZIP of XML files) ────────────────────────────

    private function buildXlsx(): string {
        if ($this->curSheet < 0) $this->addSheet();

        $files = [];

        // [Content_Types].xml
        $sheetRefs = '';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $sheetRefs .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+main+xml\"/>\n";
        }
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/styles.xml"   ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
  ' . $sheetRefs . '
</Types>';

        // _rels/.rels
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>';

        // xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $wbRels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
        }
        $wbRels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $wbRels .= '<Relationship Id="rIdSt" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $wbRels .= '</Relationships>';
        $files['xl/_rels/workbook.xml.rels'] = $wbRels;

        // xl/workbook.xml
        $sheets = '';
        foreach ($this->sheets as $i => $s) {
            $n = $i + 1;
            $sheets .= "<sheet name=\"{$s['name']}\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>' . $sheets . '</sheets>
</workbook>';

        // xl/styles.xml
        $files['xl/styles.xml'] = $this->buildStyles();

        // Shared strings & worksheets
        $sharedStrings = [];
        $ssIdx = function(string $s) use (&$sharedStrings): int {
            $key = array_search($s, $sharedStrings);
            if ($key !== false) return $key;
            $sharedStrings[] = $s;
            return count($sharedStrings) - 1;
        };

        foreach ($this->sheets as $i => $sheet) {
            $n  = $i + 1;
            $rows = '';
            $rowNum = 1;

            // Header row
            if (!empty($sheet['headers'])) {
                $cells = '';
                foreach ($sheet['headers'] as $ci => $h) {
                    $col   = $this->colName($ci);
                    $idx   = $ssIdx((string)$h);
                    $cells .= "<c r=\"{$col}{$rowNum}\" t=\"s\" s=\"2\"><v>{$idx}</v></c>";
                }
                $rows .= "<row r=\"{$rowNum}\">{$cells}</row>";
                $rowNum++;
            }

            // Data rows
            foreach ($sheet['rows'] as $row) {
                $isSummary = isset($row['__summary__']);
                $data      = $isSummary ? $row['data'] : $row;
                $style     = $isSummary ? 3 : 1;

                $cells = '';
                foreach (array_values($data) as $ci => $val) {
                    $col      = $this->colName($ci);
                    $colStyle = $sheet['styles'][$ci] ?? 'text';
                    $s        = $isSummary ? 3 : ($colStyle === 'currency' || $colStyle === 'number' ? 4 : 1);

                    if (is_numeric($val) && $colStyle !== 'text') {
                        $cells .= "<c r=\"{$col}{$rowNum}\" s=\"{$s}\"><v>" . (float)$val . "</v></c>";
                    } else {
                        $idx    = $ssIdx((string)$val);
                        $cells .= "<c r=\"{$col}{$rowNum}\" t=\"s\" s=\"{$style}\"><v>{$idx}</v></c>";
                    }
                }
                $rows .= "<row r=\"{$rowNum}\">{$cells}</row>";
                $rowNum++;
            }

            $files["xl/worksheets/sheet{$n}.xml"] =
                '<?xml version="1.0" encoding="UTF-8"?>' .
                '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
                '<sheetData>' . $rows . '</sheetData></worksheet>';
        }

        // Shared strings
        $ssEntries = '';
        foreach ($sharedStrings as $s) {
            $ssEntries .= '<si><t xml:space="preserve">' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
        }
        $files['xl/sharedStrings.xml'] = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">' . $ssEntries . '</sst>';

        return $this->zipFiles($files);
    }

    private function buildStyles(): string {
        return '<?xml version="1.0" encoding="UTF-8"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts>
    <font><sz val="10"/><name val="Calibri"/></font>
    <font><sz val="10"/><name val="Calibri"/></font>
    <font><sz val="10"/><b/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="10"/><b/><name val="Calibri"/><color rgb="FF1A3A5C"/></font>
    <font><sz val="10"/><b/><name val="Calibri"/></font>
  </fonts>
  <fills>
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1A3A5C"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF4F6F9"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FE"/></patternFill></fill>
  </fills>
  <borders><border><left/><right/><top/><bottom/><diagonal/></border></borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>
    <xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>
  </cellXfs>
</styleSheet>';
    }

    private function colName(int $idx): string {
        $name = '';
        $idx++;
        while ($idx > 0) {
            $idx--;
            $name = chr(65 + ($idx % 26)) . $name;
            $idx  = (int)($idx / 26);
        }
        return $name;
    }

    private function sanitizeSheetName(string $name): string {
        return substr(preg_replace('/[\/\\\?\*\[\]:]/', '', $name), 0, 31);
    }

    // ── Pure PHP ZIP builder ──────────────────────────────────────

    private function zipFiles(array $files): string {
        $entries    = '';
        $centralDir = '';
        $offset     = 0;

        foreach ($files as $name => $content) {
            $crc     = crc32($content);
            $size    = strlen($content);
            $dosTime = $this->getDosTime();

            // Local file header
            $lf  = pack('VvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $crc, $size, $size, strlen($name));
            $lf .= $name . $content;

            $centralDir .= pack('VvvvvVVVvvvvvVV',
                0x02014b50, 20, 20, 0, 0, $dosTime, $crc, $size, $size,
                strlen($name), 0, 0, 0, 0, 0x20, $offset);
            $centralDir .= $name;

            $offset  += strlen($lf);
            $entries .= $lf;
        }

        $cdSize  = strlen($centralDir);
        $cdCount = count($files);
        $eocd    = pack('VvvvvVVv', 0x06054b50, 0, 0, $cdCount, $cdCount, $cdSize, $offset, 0);

        return $entries . $centralDir . $eocd;
    }

    private function getDosTime(): int {
        $t = getdate();
        return (($t['year'] - 1980) << 25) | ($t['mon'] << 21) |
               ($t['mday'] << 16) | ($t['hours'] << 11) |
               ($t['minutes'] << 5) | (int)($t['seconds'] / 2);
    }
}
