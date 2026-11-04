<?php

namespace App\Services\Recap;

use App\Support\SimpleXlsxWriter;

/**
 * Generic two-sheet recap workbook: "Ringkasan" (formula-driven counts over the
 * detail sheet) + "Data" (one row per record). Topic-specific code only supplies
 * the headers, rows and which columns to summarise.
 *
 * Detail cell: scalar (int/float = number, else text, null = empty) or
 * ['date' => 'Y-m-d'] for a real date cell.
 */
final class RecapXlsxBuilder
{
    public const DETAIL_SHEET = 'Data';

    public const SUMMARY_SHEET = 'Ringkasan';

    /**
     * Section kind "date": item match is Y-m-d and the label becomes a date cell.
     * Item match null = count blank cells in the column.
     *
     * @param  list<array{0: string, 1: string}>  $meta  label/value lines under the title
     * @param  list<string>  $headers
     * @param  list<list<mixed>>  $rows
     * @param  list<array{title: string, column: int, kind?: string, static?: bool, items: list<array{label: string, match: ?string, count: int}>}>  $sections
     * @param  list<int|float>  $widths
     */
    public function build(string $path, string $title, array $meta, array $headers, array $rows, array $sections, array $widths = []): string
    {
        $detail = [];
        $detail[] = array_map(static fn (string $h): array => ['v' => $h, 'style' => 'header'], $headers);
        foreach ($rows as $row) {
            $detail[] = array_map(
                static fn ($cell) => is_array($cell) && isset($cell['date']) ? ['v' => $cell['date'], 'date' => true] : $cell,
                $row,
            );
        }

        $lastRow = max(2, count($rows) + 1);
        $range = fn (int $column): string => self::DETAIL_SHEET.'!'.self::col($column).'2:'.self::col($column).$lastRow;

        $summary = [];
        $summary[] = [['v' => $title, 'style' => 'bold']];
        foreach ($meta as [$label, $value]) {
            $summary[] = [$label, $value];
        }
        $summary[] = [];
        $summary[] = [
            ['v' => 'Total', 'style' => 'bold'],
            ['v' => count($rows), 'f' => 'COUNT('.$range(0).')', 'style' => 'bold'],
        ];

        foreach ($sections as $section) {
            $summary[] = [];
            $summary[] = [['v' => $section['title'], 'style' => 'header'], ['v' => 'Jumlah', 'style' => 'header']];
            $isDate = ($section['kind'] ?? 'text') === 'date';
            foreach ($section['items'] as $item) {
                $rowNumber = count($summary) + 1;
                if ($item['match'] === null) {
                    $formula = 'COUNTBLANK('.$range($section['column']).')';
                } elseif ($isDate) {
                    $formula = 'COUNTIF('.$range($section['column']).',A'.$rowNumber.')';
                } else {
                    $formula = 'COUNTIF('.$range($section['column']).',"='.self::criteria($item['match']).'")';
                }
                // "static": the count is not a column count (e.g. field-only, bulk rows excluded), so a
                // formula would change once Excel recalculates; keep the computed value.
                $summary[] = [
                    $isDate && $item['match'] !== null ? ['v' => $item['match'], 'date' => true] : $item['label'],
                    ($section['static'] ?? false) ? ['v' => $item['count']] : ['v' => $item['count'], 'f' => $formula],
                ];
            }
        }

        return SimpleXlsxWriter::createWorkbook($path, [
            [
                'name' => self::SUMMARY_SHEET,
                'rows' => $summary,
                'widths' => [34, 18],
            ],
            [
                'name' => self::DETAIL_SHEET,
                'rows' => $detail,
                'widths' => $widths,
                'freeze_header' => true,
            ],
        ]);
    }

    /** Escape a literal for a COUNTIF criteria string: quotes doubled, wildcards neutralised. */
    private static function criteria(string $value): string
    {
        $value = str_replace(['~', '*', '?'], ['~~', '~*', '~?'], $value);

        return str_replace('"', '""', $value);
    }

    private static function col(int $index): string
    {
        $n = $index + 1;
        $name = '';
        while ($n > 0) {
            $n--;
            $name = chr(65 + ($n % 26)).$name;
            $n = intdiv($n, 26);
        }

        return $name;
    }
}
