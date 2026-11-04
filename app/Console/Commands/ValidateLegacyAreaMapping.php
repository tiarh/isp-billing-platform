<?php

namespace App\Console\Commands;

use App\Services\LegacyAreaResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ValidateLegacyAreaMapping extends Command
{
    protected $signature = 'legacy:validate-area-mapping
        {--source=api : api or file}
        {--file= : Local JSON file path}
        {--write-report : Write mapped and unmapped report files}
        {--format=csv : Report format, csv or json}';

    protected $description = 'Dry-run legacy customer area categorization and fail if any customer maps to general or unmapped.';

    public function handle(LegacyAreaResolver $resolver): int
    {
        $customers = $this->loadCustomers();
        $mapped = [];
        $unmapped = [];
        $counts = [
            'api_area' => 0,
            'legacy_location' => 0,
            'prefix' => 0,
            'package_keyword' => 0,
            'address_keyword' => 0,
            'unmapped' => 0,
            'general' => 0,
            'junk' => 0,
        ];

        foreach ($customers as $customer) {
            if (! is_array($customer)) {
                continue;
            }

            $result = $resolver->resolve($customer);
            $row = $this->reportRow($customer, $result);

            if (($result['area'] ?? null) === 'SKYNET-GENERAL') {
                $counts['general']++;
                $unmapped[] = $row + ['failure' => 'general'];

                continue;
            }

            if (! $result['area']) {
                $counts['unmapped']++;
                $unmapped[] = $row + ['failure' => 'unmapped'];

                continue;
            }

            if (! $resolver->isApprovedArea($result['area'])) {
                $counts['junk']++;
                $unmapped[] = $row + ['failure' => 'junk'];

                continue;
            }

            $counts[$result['reason']]++;
            $mapped[] = $row;
        }

        $this->line('Total customers: '.count($customers));
        $this->line('Mapped by API area: '.$counts['api_area']);
        $this->line('Mapped by legacy location: '.$counts['legacy_location']);
        $this->line('Mapped by prefix: '.$counts['prefix']);
        $this->line('Mapped by package: '.$counts['package_keyword']);
        $this->line('Mapped by address: '.$counts['address_keyword']);
        $this->line('Unmapped: '.$counts['unmapped']);
        $this->line('SKYNET-GENERAL: '.$counts['general']);
        $this->line('Junk/invalid area: '.$counts['junk']);

        if ($this->option('write-report')) {
            $this->writeReports($mapped, $unmapped);
        }

        if ($unmapped !== []) {
            $this->error('Area validation failed. Check unmapped rows before running legacy sync.');

            return self::FAILURE;
        }

        $this->info('Area validation passed. Every customer maps to an approved real area.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCustomers(): array
    {
        $file = $this->option('file');
        if ($file) {
            $path = base_path($file);
            if (! file_exists($path)) {
                $path = $file;
            }

            if (! file_exists($path)) {
                throw new \RuntimeException("Customer file not found: {$file}");
            }

            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return $this->customerRows($decoded);
        }

        if ($this->option('source') !== 'api') {
            throw new \InvalidArgumentException('Use --source=api or provide --file=path/to/customers.json.');
        }

        $baseUrl = config('services.legacy_scraper.url', config('site.legacy_scraper.public_url'));
        $response = Http::timeout(60)->get("{$baseUrl}/api/v1/customers");

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to fetch legacy customers: '.$response->body());
        }

        return $this->customerRows($response->json());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerRows(mixed $decoded): array
    {
        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Customer data must be a JSON array.');
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return array_values($decoded['data']);
        }

        if (isset($decoded['customers']) && is_array($decoded['customers'])) {
            return array_values($decoded['customers']);
        }

        return array_values($decoded);
    }

    /**
     * @param  array<string, mixed>  $customer
     * @param  array{area: ?string, reason: string, source_value: ?string, valid: bool}  $result
     * @return array<string, string>
     */
    private function reportRow(array $customer, array $result): array
    {
        return [
            'customer_code' => (string) ($customer['code'] ?? $customer['id'] ?? $customer['id_pelanggan'] ?? $customer['customer_id'] ?? ''),
            'name' => (string) ($customer['name'] ?? $customer['nama_pelanggan'] ?? ''),
            'address' => (string) ($customer['address'] ?? $customer['alamat'] ?? ''),
            'package' => $this->packageName($customer),
            'chosen_area' => (string) ($result['area'] ?? ''),
            'mapping_reason' => $result['reason'],
            'source_value' => (string) ($result['source_value'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function packageName(array $customer): string
    {
        if (isset($customer['package']) && is_array($customer['package'])) {
            return (string) ($customer['package']['name'] ?? '');
        }

        return (string) ($customer['package'] ?? $customer['paket'] ?? '');
    }

    /**
     * @param  array<int, array<string, string>>  $mapped
     * @param  array<int, array<string, string>>  $unmapped
     */
    private function writeReports(array $mapped, array $unmapped): void
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            throw new \InvalidArgumentException('--format must be csv or json.');
        }

        if ($format === 'json') {
            $this->writeJson(storage_path('app/reports/area_mapping_mapped.json'), $mapped);
            $this->writeJson(storage_path('app/reports/area_mapping_unmapped.json'), $unmapped);
            $this->line('Reports written to storage/app/reports/*.json');

            return;
        }

        $this->writeCsv(storage_path('app/reports/area_mapping_mapped.csv'), $mapped);
        $this->writeCsv(storage_path('app/reports/area_mapping_unmapped.csv'), $unmapped);
        $this->line('Reports written to storage/app/reports/*.csv');
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

        $headers = ['customer_code', 'name', 'address', 'package', 'chosen_area', 'mapping_reason', 'source_value', 'failure'];
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header) => $row[$header] ?? '',
                $headers
            ));
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
