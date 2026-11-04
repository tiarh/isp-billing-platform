<?php

namespace App\Services;

use App\Models\User;
use App\Support\SimpleXlsxWriter;

class FinancePerAreaExportService
{
    public function __construct(private FinancePerAreaReportService $report) {}

    /**
     * @return array{path: string, row_count: int}
     */
    public function create(User $user, string $period, ?int $areaId, ?string $asOf, array $filters): array
    {
        $rowCount = 0;
        $rows = $this->report->exportRows($user, $period, $areaId, $asOf, $filters)
            ->map(function (array $row) use (&$rowCount): array {
                $rowCount++;

                return array_map(fn (mixed $value): mixed => $this->sanitizeCell($value), [
                    $row['area_code'],
                    $row['area_name'],
                    $row['invoice_code'],
                    $row['customer_code'],
                    $row['customer_name'],
                    $row['period'],
                    $row['due_date'],
                    $row['status'],
                    $row['amount'],
                    $row['effective_paid'],
                    $row['outstanding'],
                    $row['overpayment'],
                    $row['aging']['bucket'] ?? null,
                ]);
            });

        $path = SimpleXlsxWriter::create(
            'finance-per-area-'.$period.'.xlsx',
            [
                'Kode area',
                'Area',
                'Kode invoice',
                'Kode pelanggan',
                'Nama pelanggan',
                'Periode',
                'Jatuh tempo',
                'Status efektif',
                'Tagihan',
                'Pemasukan efektif',
                'Outstanding',
                'Overpayment',
                'Aging',
            ],
            $rows,
        );

        return ['path' => $path, 'row_count' => $rowCount];
    }

    private function sanitizeCell(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
