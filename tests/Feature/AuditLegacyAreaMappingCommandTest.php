<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuditLegacyAreaMappingCommandTest extends TestCase
{
    public function test_command_writes_reports_when_mappings_are_consistent(): void
    {
        $customers = $this->jsonFile([
            [
                'id_pelanggan' => 'LSS001',
                'nama_pelanggan' => 'Karangploso Customer 1',
                'nama_lokasi' => 'SKYNET-KARANGPLOSO',
                'koordinat' => '-6.891503,111.601937',
            ],
            [
                'id_pelanggan' => 'LSS002',
                'nama_pelanggan' => 'Karangploso Customer 2',
                'nama_lokasi' => 'SKYNET-KARANGPLOSO',
                'koordinat' => '-6.892583,111.604772',
            ],
        ]);
        $transactions = $this->jsonFile([
            ['id_pelanggan' => 'LSS001', 'alamat' => 'Karangploso'],
            ['id_pelanggan' => 'LSS002', 'alamat' => 'Karangploso'],
        ]);

        $this->artisan('legacy:audit-area-mapping', [
            '--customers' => $customers,
            '--transactions' => $transactions,
            '--write-report' => true,
            '--format' => 'csv',
        ])
            ->assertSuccessful()
            ->expectsOutput('Suspicious mappings: 0');

        $this->assertFileExists(storage_path('app/reports/area_mapping_audit.csv'));
        $this->assertFileExists(storage_path('app/reports/area_mapping_suspicious.csv'));
    }

    public function test_command_fails_when_prefix_conflicts_with_legacy_location(): void
    {
        $customers = $this->jsonFile([
            [
                'id_pelanggan' => 'DKR001',
                'nama_pelanggan' => 'Mixed Prefix Customer',
                'nama_lokasi' => 'RANDUAGUNG',
                'koordinat' => '-6.861455,111.680107',
            ],
        ]);
        $transactions = $this->jsonFile([
            ['id_pelanggan' => 'DKR001', 'alamat' => 'Randuagung'],
        ]);

        $this->artisan('legacy:audit-area-mapping', [
            '--customers' => $customers,
            '--transactions' => $transactions,
        ])
            ->assertFailed()
            ->expectsOutput('Suspicious mappings: 1');
    }

    public function test_command_can_allow_suspicious_rows_for_report_generation(): void
    {
        $customers = $this->jsonFile([
            [
                'id_pelanggan' => 'DKR001',
                'nama_pelanggan' => 'Mixed Prefix Customer',
                'nama_lokasi' => 'RANDUAGUNG',
            ],
        ]);
        $transactions = $this->jsonFile([]);

        $this->artisan('legacy:audit-area-mapping', [
            '--customers' => $customers,
            '--transactions' => $transactions,
            '--allow-suspicious' => true,
        ])
            ->assertSuccessful()
            ->expectsOutput('Suspicious mappings: 1');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function jsonFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-area-audit-').'.json';
        file_put_contents($path, json_encode($rows, JSON_THROW_ON_ERROR));

        return $path;
    }
}
