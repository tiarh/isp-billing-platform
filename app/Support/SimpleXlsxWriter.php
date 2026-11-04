<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

class SimpleXlsxWriter
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<int, array<int, mixed>>  $rows
     */
    public static function create(string $filename, array $headers, iterable $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to create XLSX exports.');
        }

        $directory = self::temporaryDirectory();

        $xlsxPath = tempnam($directory, pathinfo($filename, PATHINFO_FILENAME).'-');
        $sheetPath = tempnam($directory, 'sheet-');
        if ($xlsxPath === false || $sheetPath === false) {
            throw new RuntimeException('Failed to create temporary XLSX export files.');
        }

        $sheet = fopen($sheetPath, 'wb');
        if ($sheet === false) {
            throw new RuntimeException('Failed to open temporary XLSX worksheet file.');
        }

        try {
            fwrite($sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>');
            fwrite($sheet, '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');
            self::writeRow($sheet, 1, $headers, true);

            $rowNumber = 2;
            foreach ($rows as $row) {
                self::writeRow($sheet, $rowNumber, $row);
                $rowNumber++;
            }

            fwrite($sheet, '</sheetData></worksheet>');
        } finally {
            fclose($sheet);
        }

        $zip = new ZipArchive;
        if ($zip->open($xlsxPath, ZipArchive::OVERWRITE) !== true) {
            File::delete($sheetPath);
            throw new RuntimeException('Failed to create XLSX archive.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypesXml());
        $zip->addFromString('_rels/.rels', self::rootRelationshipsXml());
        $zip->addFromString('xl/workbook.xml', self::workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationshipsXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->close();

        File::delete($sheetPath);

        return $xlsxPath;
    }

    /**
     * Multi-sheet workbook with styles, real date cells and formula cells
     * (with a cached value so viewers that do not recalculate still show it).
     *
     * Sheet spec: ['name' => string, 'rows' => list<list<cell>>, 'widths' => list<int|float>,
     * 'freeze_header' => bool]. A cell is a scalar (int/float = number, anything
     * else = text, null = empty) or ['v' => scalar, 'f' => ?formula (no leading "="),
     * 'style' => ?'bold'|'header'|'date', 'date' => ?bool (v is Y-m-d)].
     *
     * @param  list<array{name: string, rows: array<int, array<int, mixed>>, widths?: array<int, int|float>, freeze_header?: bool}>  $sheets
     * @return string absolute path of the written file ($path)
     */
    public static function createWorkbook(string $path, array $sheets): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to create XLSX exports.');
        }
        if ($sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }

        File::ensureDirectoryExists(dirname($path));
        if (is_file($path)) {
            File::delete($path);
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Failed to create XLSX archive.');
        }

        $count = count($sheets);
        $zip->addFromString('[Content_Types].xml', self::multiContentTypesXml($count));
        $zip->addFromString('_rels/.rels', self::rootRelationshipsXml());
        $zip->addFromString('xl/workbook.xml', self::multiWorkbookXml($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::multiWorkbookRelationshipsXml($count));
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        foreach (array_values($sheets) as $index => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', self::sheetXml($sheet));
        }
        $zip->close();

        return $path;
    }

    /**
     * @param  array{name: string, rows: array<int, array<int, mixed>>, widths?: array<int, int|float>, freeze_header?: bool}  $sheet
     */
    private static function sheetXml(array $sheet): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (! empty($sheet['freeze_header'])) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }

        $widths = array_values($sheet['widths'] ?? []);
        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $width) {
                $n = $i + 1;
                $xml .= '<col min="'.$n.'" max="'.$n.'" width="'.((float) $width).'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach (array_values($sheet['rows']) as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $xml .= '<row r="'.$rowNumber.'">';
            foreach (array_values($row) as $colIndex => $cell) {
                $xml .= self::cellXml(self::columnName($colIndex + 1).$rowNumber, $cell);
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function cellXml(string $ref, mixed $cell): string
    {
        $formula = null;
        $style = null;
        $isDate = false;
        $value = $cell;
        if (is_array($cell)) {
            $value = $cell['v'] ?? null;
            $formula = isset($cell['f']) && $cell['f'] !== '' ? (string) $cell['f'] : null;
            $style = $cell['style'] ?? null;
            $isDate = ! empty($cell['date']);
        }

        $s = match ($style) {
            'bold' => 1,
            'header' => 2,
            default => 0,
        };
        if ($isDate) {
            $s = 3;
            $value = self::dateSerial((string) $value);
        }
        $sAttr = $s > 0 ? ' s="'.$s.'"' : '';

        if ($formula !== null) {
            $cached = is_int($value) || is_float($value) ? '<v>'.$value.'</v>' : '';

            return '<c r="'.$ref.'"'.$sAttr.'><f>'.self::escape($formula).'</f>'.$cached.'</c>';
        }

        if ($value === null || $value === '') {
            return $s > 0 ? '<c r="'.$ref.'"'.$sAttr.'/>' : '';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="'.$ref.'"'.$sAttr.'><v>'.$value.'</v></c>';
        }

        return '<c r="'.$ref.'"'.$sAttr.' t="inlineStr"><is><t xml:space="preserve">'.self::escape((string) $value).'</t></is></c>';
    }

    /** Excel serial for a Y-m-d date (1900 date system). */
    private static function dateSerial(string $ymd): int
    {
        $ts = strtotime($ymd.' 00:00:00 UTC');

        return (int) floor(($ts === false ? 0 : $ts) / 86400) + 25569;
    }

    /**
     * @param  list<array{name: string}>  $sheets
     */
    private static function multiWorkbookXml(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach (array_values($sheets) as $index => $sheet) {
            $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $sheet['name']) ?? 'Sheet', 0, 31);
            $xml .= '<sheet name="'.self::escape($name).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return $xml.'</sheets></workbook>';
    }

    private static function multiWorkbookRelationshipsXml(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }
        $xml .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $xml.'</Relationships>';
    }

    private static function multiContentTypesXml(int $count): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml.'</Types>';
    }

    /** Style ids: 0 default, 1 bold, 2 header (bold + grey fill), 3 date dd/mm/yyyy. */
    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFD9D9D9"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private static function temporaryDirectory(): string
    {
        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);

        if (is_dir($directory) && is_writable($directory)) {
            return $directory;
        }

        $fallback = sys_get_temp_dir();
        if (! is_dir($fallback) || ! is_writable($fallback)) {
            throw new RuntimeException('No writable temporary directory is available for XLSX exports.');
        }

        return $fallback;
    }

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $values
     */
    private static function writeRow($handle, int $rowNumber, array $values, bool $header = false): void
    {
        fwrite($handle, '<row r="'.$rowNumber.'">');

        foreach (array_values($values) as $index => $value) {
            $cell = self::columnName($index + 1).$rowNumber;

            if (! $header && is_numeric($value) && $value !== '') {
                fwrite($handle, '<c r="'.$cell.'"><v>'.$value.'</v></c>');

                continue;
            }

            fwrite($handle, '<c r="'.$cell.'" t="inlineStr"><is><t>'.self::escape((string) $value).'</t></is></c>');
        }

        fwrite($handle, '</row>');
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private static function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Export" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private static function workbookRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}
