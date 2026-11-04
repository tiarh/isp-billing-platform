<?php

namespace App\Console\Commands;

use App\Services\GuidedMigrationLookupService;
use Illuminate\Console\Command;

class PreviewGuidedMigrationLookup extends Command
{
    protected $signature = 'migration:guided-lookup
        {--name= : Nama pelanggan}
        {--new-sn= : SN ONT baru}
        {--phone= : Nomor HP}
        {--customer-code= : Kode customer}
        {--area= : Daerah/area pelanggan}
        {--odp= : ODP pelanggan}
        {--rt= : RT pelanggan}
        {--rw= : RW pelanggan}';

    protected $description = 'Preview read-only kandidat identitas migrasi tanpa action perangkat atau perubahan data';

    public function handle(GuidedMigrationLookupService $service): int
    {
        $result = $service->preview([
            'name' => $this->option('name'),
            'new_sn' => $this->option('new-sn'),
            'phone' => $this->option('phone'),
            'customer_code' => $this->option('customer-code'),
            'area' => $this->option('area'),
            'odp' => $this->option('odp'),
            'rt' => $this->option('rt'),
            'rw' => $this->option('rw'),
        ]);

        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return ($result['status'] ?? null) === 'input_incomplete' ? self::FAILURE : self::SUCCESS;
    }
}
