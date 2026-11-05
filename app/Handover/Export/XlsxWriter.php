<?php

namespace App\Handover\Export;

use RuntimeException;
use ZipArchive;

/**
 * Penulis file Excel (.xlsx) sederhana tanpa library tambahan (cukup ekstensi zip PHP).
 * Mendukung beberapa sheet, baris judul tebal + dibekukan + filter otomatis, lebar kolom, teks terbungkus,
 * dan warna latar per baris.
 */
final class XlsxWriter
{
    // Gaya sel (indeks cellXfs di styles.xml)
    public const NORMAL = 0;
    public const HEADER = 1;
    public const WRAP = 2;
    public const FILLS = ['pending' => 3, 'onprogress' => 4, 'trouble' => 5, 'done' => 6];  // baris berwarna (+ wrap)
    public const TITLE = 7;
    public const BOLD = 8;

    /** @var array<int, array{name:string, rows:array, widths:array, header:int|null, wrap:array}> */
    private array $sheets = [];

    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows  baris data (sel string/angka)
     * @param  array<int, float>  $widths  lebar kolom (karakter)
     * @param  array<int, int>  $rowStyles  gaya per indeks baris (0-based), mis. warna status
     * @param  int|null  $headerRow  indeks baris judul kolom (tebal, dibekukan, filter); null = tidak ada
     * @param  array<int, int>  $cellStyles  gaya per "baris:kolom" untuk sel tertentu
     */
    public function addSheet(string $name, array $rows, array $widths = [], array $rowStyles = [], ?int $headerRow = 0, array $cellStyles = []): self
    {
        // Nama sheet Excel: maks 31 karakter, tanpa : \ / ? * [ ]
        $name = mb_substr(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name), 0, 31);
        $this->sheets[] = compact('name', 'rows', 'widths', 'rowStyles', 'headerRow', 'cellStyles');

        return $this;
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat file Excel');
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', self::styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheetXml($sheet));
        }
        $zip->close();
    }

    private static function esc(string $s): string
    {
        // Buang karakter kontrol yang tidak sah di XML
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';

        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26).$s;
        }

        return $s;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $_) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    private function workbook(): string
    {
        $sheets = $names = '';
        foreach ($this->sheets as $i => $s) {
            $sheets .= '<sheet name="'.self::esc($s['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
            if ($s['headerRow'] !== null && count($s['rows']) > $s['headerRow'] + 1) {
                $last = self::col(max(count($s['rows'][$s['headerRow']]), 1) - 1).count($s['rows']);
                $ref = "'".str_replace("'", "''", $s['name'])."'!\$A\$".($s['headerRow'] + 1).':$'.preg_replace('/(\d+)$/', '\$$1', $last);
                $names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$i.'" hidden="1">'.self::esc($ref).'</definedName>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets>'.($names ? '<definedNames>'.$names.'</definedNames>' : '').'</workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach ($this->sheets as $i => $_) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $rels .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';
    }

    private static function styles(): string
    {
        // Warna mengikuti legenda spreadsheet: putih = pending, kuning = on progress, orange = trouble, hijau = done.
        $fill = fn (string $rgb) => '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$rgb.'"/><bgColor indexed="64"/></patternFill></fill>';
        $border = '<border><left style="thin"><color rgb="FFD9DEE8"/></left><right style="thin"><color rgb="FFD9DEE8"/></right>'
            .'<top style="thin"><color rgb="FFD9DEE8"/></top><bottom style="thin"><color rgb="FFD9DEE8"/></bottom><diagonal/></border>';
        $xf = fn (int $font, int $fillId, bool $wrap, int $borderId = 1) => '<xf numFmtId="0" fontId="'.$font.'" fillId="'.$fillId.'" borderId="'.$borderId.'"'
            .($fillId ? ' applyFill="1"' : '').' applyBorder="1"'.($font ? ' applyFont="1"' : '')
            .'><alignment vertical="top"'.($wrap ? ' wrapText="1"' : '').'/></xf>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3"><font><sz val="10"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="13"/><color rgb="FF0A3D91"/><name val="Calibri"/></font></fonts>'
            .'<fills count="8"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .$fill('0A3D91').$fill('FFFFFF').$fill('FFF2B3').$fill('FFC999').$fill('B7E1CD').'<fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'.$border.'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="9">'
            .$xf(0, 0, false)          // 0 normal
            .$xf(1, 2, true)           // 1 judul kolom (biru tua, teks putih tebal)
            .$xf(0, 0, true)           // 2 teks terbungkus
            .$xf(0, 3, true)           // 3 pending (putih)
            .$xf(0, 4, true)           // 4 on progress (kuning)
            .$xf(0, 5, true)           // 5 trouble (orange)
            .$xf(0, 6, true)           // 6 done (hijau)
            .$xf(2, 0, false, 0)       // 7 judul laporan
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyBorder="1"><alignment vertical="top"/></xf>'  // 8 tabel ringkasan
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheetXml(array $s): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        if ($s['headerRow'] !== null) {
            $top = 'A'.($s['headerRow'] + 2);
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.($s['headerRow'] + 1).'" topLeftCell="'.$top
                .'" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="'.$top.'" sqref="'.$top.'"/></sheetView></sheetViews>';
        }
        if ($s['widths']) {
            $xml .= '<cols>';
            foreach ($s['widths'] as $i => $w) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.$w.'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($s['rows'] as $r => $row) {
            $style = $r === $s['headerRow'] ? self::HEADER : ($s['rowStyles'][$r] ?? self::WRAP);
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c).($r + 1);
                $st = $s['cellStyles']["{$r}:{$c}"] ?? $style;
                if ($v === null || $v === '') {
                    $xml .= '<c r="'.$ref.'" s="'.$st.'"/>';
                } elseif (is_int($v) || is_float($v)) {
                    $xml .= '<c r="'.$ref.'" s="'.$st.'"><v>'.$v.'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" s="'.$st.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $v).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';
        if ($s['headerRow'] !== null && count($s['rows']) > $s['headerRow'] + 1) {
            $xml .= '<autoFilter ref="A'.($s['headerRow'] + 1).':'.self::col(count($s['rows'][$s['headerRow']]) - 1).count($s['rows']).'"/>';
        }

        return $xml.'<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/></worksheet>';
    }
}
