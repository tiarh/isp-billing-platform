<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Console\Command;

class AuditCustomerPhones extends Command
{
    protected $signature = 'billing:audit-phones {--output= : Write a CSV report to this path}';

    protected $description = 'Read-only audit of customer phone formats and canonicalization conflicts';

    public function handle(IndonesianPhoneNormalizer $phones): int
    {
        $rows = [];
        $byCanonical = [];

        Customer::query()
            ->select(['id', 'name', 'phone'])
            ->orderBy('id')
            ->chunkById(500, function ($customers) use (&$rows, &$byCanonical, $phones): void {
                foreach ($customers as $customer) {
                    $raw = (string) ($customer->phone ?? '');
                    $normalized = $phones->normalize($raw);
                    $canonical = $normalized['canonical_local'];
                    $category = $this->category($raw, $normalized);

                    $row = [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone_masked' => $this->mask($raw),
                        'canonical_local' => $canonical === null ? '' : $this->mask($canonical),
                        'category' => $category,
                        'reason' => $normalized['reason'] ?? '',
                    ];
                    $rows[] = $row;

                    if ($canonical !== null) {
                        $byCanonical[$canonical][] = count($rows) - 1;
                    }
                }
            });

        foreach ($byCanonical as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                $rows[$index]['category'] = 'conflict';
                $rows[$index]['reason'] = 'duplicate_after_canonicalization';
            }
        }

        if ($path = $this->option('output')) {
            $handle = fopen((string) $path, 'wb');
            if ($handle === false) {
                $this->error('Tidak dapat membuka output CSV.');

                return self::FAILURE;
            }
            fputcsv($handle, ['id', 'name', 'phone_masked', 'canonical_local', 'category', 'reason']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
            $this->line('report='.$path);
        }

        $summary = collect($rows)->countBy('category')->sortKeys();
        $this->table(['Category', 'Count'], $summary->map(fn ($count, $category) => [$category, $count])->values()->all());
        $this->info('Read-only: tidak ada data customer yang diubah.');

        return self::SUCCESS;
    }

    private function category(string $raw, array $normalized): string
    {
        if (! $normalized['is_valid']) {
            return $raw === '' ? 'empty' : 'invalid';
        }
        if (preg_match('/^\+?62/', trim($raw)) || str_starts_with(preg_replace('/\D+/', '', $raw) ?? '', '62')) {
            return 'convert_country_code';
        }
        if (str_starts_with(preg_replace('/\D+/', '', $raw) ?? '', '8')) {
            return 'add_zero';
        }

        return $normalized['was_changed'] ? 'normalized' : 'already_canonical';
    }

    private function mask(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : substr($digits, 0, 2).str_repeat('*', max(2, strlen($digits) - 4)).substr($digits, -2);
    }
}
