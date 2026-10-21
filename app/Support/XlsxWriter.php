<?php

namespace App\Support;

use ZipArchive;

/**
 * Writes a single-sheet .xlsx - enough of the format for a header row (bold,
 * frozen) and data rows, with no library. Values are written as text so
 * identifiers and phone numbers keep their leading zeros and digits; only the
 * columns named in $numeric are written as numbers (when they are numeric).
 */
final class XlsxWriter
{
    /**
     * @param  string[]  $headers
     * @param  iterable<array>  $rows  each a list of values in header order
     * @param  int[]  $numeric  zero-based indexes of columns written as numbers
     */
    public static function write(string $path, array $headers, iterable $rows, array $numeric = [], string $sheet = 'Sheet1'): void
    {
        $xml = '<row r="1">';
        foreach (array_values($headers) as $i => $header) {
            $xml .= self::cell($i, 1, $header, false, 1);
        }
        $xml .= '</row>';

        $r = 1;
        foreach ($rows as $row) {
            $r++;
            $xml .= '<row r="' . $r . '">';
            foreach (array_values($row) as $i => $value) {
                if ($value !== null && $value !== '') {
                    $xml .= self::cell($i, $r, $value, in_array($i, $numeric, true) && is_numeric($value));
                }
            }
            $xml .= '</row>';
        }

        $last = self::column(max(0, count($headers) - 1)) . max(1, $r);
        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $last . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols><col min="1" max="' . max(1, count($headers)) . '" width="16" customWidth="1"/></cols>'
            . '<sheetData>' . $xml . '</sheetData></worksheet>';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Could not create {$path}");
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escape($sheet) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Style 0 = normal, style 1 = bold (the header row)
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
        $zip->close();
    }

    private static function cell(int $index, int $row, mixed $value, bool $number, int $style = 0): string
    {
        $ref = self::column($index) . $row;
        $s = $style ? ' s="' . $style . '"' : '';

        return $number
            ? '<c r="' . $ref . '"' . $s . '><v>' . (0 + $value) . '</v></c>'
            : '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::escape((string) $value) . '</t></is></c>';
    }

    /** 0 => A, 25 => Z, 26 => AA */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26) . $name;
        }

        return $name;
    }

    private static function escape(string $text): string
    {
        // Characters XML 1.0 doesn't allow would make Excel refuse the file
        $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $text) ?? '';

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
