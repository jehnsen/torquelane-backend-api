<?php

declare(strict_types=1);

namespace App\Exports;

use App\Domain\PurchaseOrders\ExportTable;
use App\Domain\PurchaseOrders\PurchaseOrderExport;
use RuntimeException;
use ZipArchive;

/**
 * Renders an ExportTable as CSV or as a minimal Office Open XML workbook
 * (one sheet, a bold header row, column widths, money as numbers formatted
 * `#,##0.00`). Write-only, no dependency: an .xlsx is a zip of a few XML
 * parts. Money is written from integer centavos as an exact decimal, never
 * through a float.
 */
final class SpreadsheetWriter
{
    public const string XLSX_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public const string CSV_TYPE = 'text/csv; charset=UTF-8';

    public static function csv(ExportTable $table): string
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new RuntimeException('Cannot open a temporary stream.');
        }
        fwrite($stream, "\xEF\xBB\xBF"); // so spreadsheet apps read UTF-8 (₱, ñ)
        fputcsv($stream, $table->headings, escape: '');
        foreach ($table->rows as $row) {
            fputcsv($stream, array_map(fn (int $i): string => self::text($table, $i, $row[$i] ?? null), array_keys($row)), escape: '');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public static function xlsx(ExportTable $table): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file.');
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Cannot write the workbook.');
            }
            $zip->addFromString('[Content_Types].xml', self::CONTENT_TYPES);
            $zip->addFromString('_rels/.rels', self::ROOT_RELS);
            $zip->addFromString('xl/workbook.xml', sprintf(self::WORKBOOK, self::xml(self::sheetName($table->sheet))));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::WORKBOOK_RELS);
            $zip->addFromString('xl/styles.xml', self::STYLES);
            $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($table));
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private static function sheet(ExportTable $table): string
    {
        $cols = '';
        foreach ($table->widths as $i => $width) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$d" customWidth="1"/>', $i + 1, $width);
        }

        $rows = '<row r="1">';
        foreach ($table->headings as $i => $heading) {
            $rows .= sprintf('<c r="%s1" t="inlineStr" s="1"><is><t>%s</t></is></c>', self::column($i), self::xml($heading));
        }
        $rows .= '</row>';

        foreach ($table->rows as $r => $row) {
            $n = $r + 2;
            $rows .= "<row r=\"{$n}\">";
            foreach ($row as $i => $value) {
                $ref = self::column($i).$n;
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_int($value)) {
                    $money = in_array($i, $table->moneyColumns, true);
                    $rows .= sprintf('<c r="%s"%s><v>%s</v></c>', $ref, $money ? ' s="2"' : '', $money ? PurchaseOrderExport::pesos($value) : (string) $value);

                    continue;
                }
                $rows .= sprintf('<c r="%s" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $ref, self::xml($value));
            }
            $rows .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            ."<cols>{$cols}</cols><sheetData>{$rows}</sheetData></worksheet>";
    }

    private static function text(ExportTable $table, int $column, string|int|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_int($value) && in_array($column, $table->moneyColumns, true)) {
            return PurchaseOrderExport::pesos($value);
        }

        return (string) $value;
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** Excel refuses : \ / ? * [ ] in a sheet name, and more than 31 characters. */
    private static function sheetName(string $name): string
    {
        $clean = trim((string) preg_replace('/[:\\\\\/?*\[\]]/', '-', $name));

        return mb_substr($clean === '' ? 'Sheet1' : $clean, 0, 31);
    }

    private static function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private const string CONTENT_TYPES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        .'</Types>';

    private const string ROOT_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';

    private const string WORKBOOK = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets></workbook>';

    private const string WORKBOOK_RELS = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        .'</Relationships>';

    private const string STYLES = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="3">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'</cellXfs></styleSheet>';
}
