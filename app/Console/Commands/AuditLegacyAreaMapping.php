<?php

namespace App\Console\Commands;

use App\Services\LegacyAreaCoordinateService;
use App\Services\LegacyAreaResolver;
use Illuminate\Console\Command;

class AuditLegacyAreaMapping extends Command
{
    protected $signature = 'legacy:audit-area-mapping
        {--customers=migration_data/customers.json : Local legacy customer JSON export}
        {--transactions=migration_data/transactions.json : Local legacy transaction JSON export}
        {--write-report : Write full and suspicious report files}
        {--format=csv : Report format, csv or json}
        {--allow-suspicious : Return success even when suspicious rows are found}';

    protected $description = 'Audit legacy area mapping accuracy against legacy location, prefix, transactions, and coordinates.';

    public function handle(LegacyAreaResolver $resolver, LegacyAreaCoordinateService $coordinates): int
    {
        $customers = $this->loadJsonArray((string) $this->option('customers'));
        $transactionEvidence = $this->transactionEvidence((string) $this->option('transactions'));
        $centroids = $this->areaCentroids($customers, $resolver, $coordinates);
        $prefixMap = $resolver->prefixMap();
        $rows = [];
        $suspicious = [];

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $code = $this->customerCode($customer);
            $prefix = $this->prefix($code);
            $resolverResult = $resolver->resolve($customer);
            $resolverArea = $resolverResult['area'];
            $legacyArea = $this->legacyArea($customer, $resolver);
            $prefixArea = $prefix && isset($prefixMap[$prefix]) ? $prefixMap[$prefix] : null;
            $coordinate = $coordinates->parse($this->stringValue($customer['koordinat'] ?? null));
            $nearest = $coordinate ? $coordinates->nearest($coordinate, $centroids) : null;
            $evidence = $transactionEvidence[$code] ?? null;

            [$confidence, $reason, $isSuspicious] = $this->classify(
                $resolverArea,
                $legacyArea,
                $prefixArea,
                $nearest
            );

            $row = [
                'customer_code' => $code,
                'name' => $this->stringValue($customer['nama_pelanggan'] ?? $customer['name'] ?? null) ?? '',
                'resolver_area' => $resolverArea ?? '',
                'resolver_reason' => $resolverResult['reason'],
                'legacy_area' => $legacyArea ?? '',
                'prefix' => $prefix ?? '',
                'prefix_area' => $prefixArea ?? '',
                'coordinate' => $coordinate ? $coordinate['lat'].','.$coordinate['lng'] : '',
                'nearest_centroid_area' => $nearest['area'] ?? '',
                'nearest_centroid_km' => isset($nearest['distance_km']) ? (string) round($nearest['distance_km'], 3) : '',
                'transaction_address_evidence' => $evidence ?? '',
                'confidence' => $confidence,
                'reason' => $reason,
            ];

            $rows[] = $row;
            if ($isSuspicious) {
                $suspicious[] = $row;
            }
        }

        $this->line('Total customers audited: '.count($rows));
        $this->line('Suspicious mappings: '.count($suspicious));

        if ($this->option('write-report')) {
            $this->writeReports($rows, $suspicious);
        }

        if ($suspicious !== [] && ! $this->option('allow-suspicious')) {
            $this->error('Area audit found suspicious mappings. Review the suspicious report or rerun with --allow-suspicious.');

            return self::FAILURE;
        }

        $this->info('Area audit completed.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array{area: string, distance_km: float}|null  $nearest
     * @return array{0: string, 1: string, 2: bool}
     */
    private function classify(?string $resolverArea, ?string $legacyArea, ?string $prefixArea, ?array $nearest): array
    {
        $reasons = [];
        $isSuspicious = false;

        if (! $resolverArea) {
            $reasons[] = 'resolver_unmapped';
            $isSuspicious = true;
        }

        if ($legacyArea && $resolverArea && $legacyArea !== $resolverArea) {
            $reasons[] = 'resolver_legacy_mismatch';
            $isSuspicious = true;
        }

        if ($legacyArea && $prefixArea && $legacyArea !== $prefixArea) {
            $reasons[] = 'prefix_legacy_mismatch';
            $isSuspicious = true;
        }

        if ($nearest && $resolverArea && $nearest['area'] !== $resolverArea && $nearest['distance_km'] <= 2.0) {
            $reasons[] = 'coordinate_centroid_mismatch';
            $isSuspicious = true;
        }

        if ($reasons === []) {
            return ['high', 'consistent', false];
        }

        return ['review', implode('|', $reasons), $isSuspicious];
    }

    /**
     * @param  array<int, mixed>  $customers
     * @return array<string, array{lat: float, lng: float, count: int}>
     */
    private function areaCentroids(array $customers, LegacyAreaResolver $resolver, LegacyAreaCoordinateService $coordinates): array
    {
        $pointsByArea = [];

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $legacyArea = $this->legacyArea($customer, $resolver);
            if (! $legacyArea) {
                continue;
            }

            $coordinate = $coordinates->parse($this->stringValue($customer['koordinat'] ?? null));
            if (! $coordinate) {
                continue;
            }

            $pointsByArea[$legacyArea][] = $coordinate;
        }

        return $coordinates->centroids($pointsByArea);
    }

    /**
     * @return array<int, mixed>
     */
    private function loadJsonArray(string $path): array
    {
        $fullPath = base_path($path);
        if (! file_exists($fullPath)) {
            $fullPath = $path;
        }

        if (! file_exists($fullPath)) {
            throw new \RuntimeException("JSON file not found: {$path}");
        }

        $decoded = json_decode((string) file_get_contents($fullPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException("JSON file must contain an array: {$path}");
        }

        return array_values($decoded);
    }

    /**
     * @return array<string, string>
     */
    private function transactionEvidence(string $path): array
    {
        $transactions = $this->loadJsonArray($path);
        $addressesByCustomer = [];

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $code = $this->stringValue($transaction['id_pelanggan'] ?? $transaction['customer_id'] ?? null);
            $address = $this->stringValue($transaction['alamat'] ?? $transaction['address'] ?? null);
            if (! $code || ! $address) {
                continue;
            }

            $normalizedAddress = strtoupper($address);
            $normalizedAddress = str_replace(['GLAGARHUM', 'GLAHARHUM'], 'GLAGAHARUM', $normalizedAddress);
            $normalizedAddress = str_replace('REKASAN', 'REKESAN', $normalizedAddress);
            $normalizedAddress = str_replace(['KAMPUNG B ARU', 'KAMBUNG BARU'], 'KAMPUNG BARU', $normalizedAddress);
            $addressesByCustomer[$code][$normalizedAddress] = ($addressesByCustomer[$code][$normalizedAddress] ?? 0) + 1;
        }

        $evidence = [];
        foreach ($addressesByCustomer as $code => $addresses) {
            arsort($addresses);
            $evidence[$code] = collect($addresses)
                ->take(3)
                ->map(fn (int $count, string $address) => "{$address}:{$count}")
                ->implode('; ');
        }

        return $evidence;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function legacyArea(array $customer, LegacyAreaResolver $resolver): ?string
    {
        $legacyLocation = $this->stringValue($customer['nama_lokasi'] ?? null);
        if (! $legacyLocation) {
            return null;
        }

        $result = $resolver->resolve(['nama_lokasi' => $legacyLocation]);

        return $result['area'] && $resolver->isApprovedArea($result['area']) ? $result['area'] : null;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function customerCode(array $customer): string
    {
        return $this->stringValue(
            $customer['code']
                ?? $customer['id']
                ?? $customer['id_pelanggan']
                ?? $customer['customer_id']
                ?? null
        ) ?? '';
    }

    private function prefix(string $code): ?string
    {
        if (preg_match('/^[A-Z]+/i', trim($code), $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[0]);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     * @param  array<int, array<string, string>>  $suspicious
     */
    private function writeReports(array $rows, array $suspicious): void
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            throw new \InvalidArgumentException('--format must be csv or json.');
        }

        if ($format === 'json') {
            $this->writeJson(storage_path('app/reports/area_mapping_audit.json'), $rows);
            $this->writeJson(storage_path('app/reports/area_mapping_suspicious.json'), $suspicious);
            $this->line('Reports written to storage/app/reports/area_mapping_*.json');

            return;
        }

        $this->writeCsv(storage_path('app/reports/area_mapping_audit.csv'), $rows);
        $this->writeCsv(storage_path('app/reports/area_mapping_suspicious.csv'), $suspicious);
        $this->line('Reports written to storage/app/reports/area_mapping_*.csv');
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function writeCsv(string $path, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        $handle = fopen($path, 'wb');
        if (! $handle) {
            throw new \RuntimeException("Unable to write report: {$path}");
        }

        $headers = [
            'customer_code',
            'name',
            'resolver_area',
            'resolver_reason',
            'legacy_area',
            'prefix',
            'prefix_area',
            'coordinate',
            'nearest_centroid_area',
            'nearest_centroid_km',
            'transaction_address_evidence',
            'confidence',
            'reason',
        ];

        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header) => $row[$header] ?? '', $headers));
        }

        fclose($handle);
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    private function writeJson(string $path, array $rows): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
