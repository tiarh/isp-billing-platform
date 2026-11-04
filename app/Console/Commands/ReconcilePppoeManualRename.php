<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PppoeManualRenameReconcileService;
use Illuminate\Console\Command;

class ReconcilePppoeManualRename extends Command
{
    protected $signature = 'customers:reconcile-pppoe-rename
                            {customer : Customer ID atau PPPoE username lama}
                            {--candidate= : PPPoE username live yang diusulkan}
                            {--live : Verifikasi secret/active connection live secara read-only}
                            {--apply : Apply DB/draft only setelah preflight live}
                            {--reason= : Alasan approval, wajib untuk apply}
                            {--actor-id= : User ID operator untuk audit}';

    protected $description = 'Reconcile manual PPPoE rename into customer/draft DB without device write';

    public function handle(PppoeManualRenameReconcileService $service): int
    {
        $customer = $this->resolveCustomer((string) $this->argument('customer'));
        if (! $customer) {
            $this->error('Customer tidak ditemukan atau input ambigu. Gunakan customer ID/PPPoE exact.');

            return self::FAILURE;
        }

        try {
            $result = $this->option('apply')
                ? $service->apply(
                    $customer,
                    (string) $this->option('candidate'),
                    (string) $this->option('reason'),
                    $this->option('actor-id') ? (int) $this->option('actor-id') : null
                )
                : $service->preview($customer, $this->option('candidate') ?: null, (bool) $this->option('live'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info(($this->option('apply') ? 'APPLY' : 'DRY RUN').' selesai; device_write=false.');

        return self::SUCCESS;
    }

    private function resolveCustomer(string $value): ?Customer
    {
        if (ctype_digit($value)) {
            return Customer::withTrashed()->find((int) $value);
        }

        $rows = Customer::withTrashed()->whereRaw('UPPER(pppoe_user) = ?', [strtoupper(trim($value))])->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
