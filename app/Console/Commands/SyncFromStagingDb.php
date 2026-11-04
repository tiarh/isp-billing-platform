<?php

namespace App\Console\Commands;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;

class SyncFromStagingDb extends Command
{
    protected $signature = 'ebilling:sync {--db= : Path to the SQLite staging DB}';

    protected $description = 'Sync customers and invoices from the Python eBilling SQLite staging DB into MySQL.';

    private PDO $sqlite;

    public function handle(): int
    {
        $dbPath = $this->option('db')
            ?? base_path('../../python/skynet-scraping-ebilling/ebilling_scrape.db');

        // Fallback to absolute path if relative fails (useful for local environment)
        if (! file_exists($dbPath)) {
            $dbPath = '/home/fairusinampratama/python/skynet-scraping-ebilling/ebilling_scrape.db';
        }

        if (! file_exists($dbPath)) {
            $this->error("SQLite DB not found at: {$dbPath}");

            return self::FAILURE;
        }

        $this->sqlite = new PDO("sqlite:{$dbPath}");
        $this->sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->info('🔗 Connected to staging DB: '.$dbPath);

        DB::disableQueryLog();

        $this->syncCustomers();
        $this->syncInvoices();

        $this->info('✅ Sync complete.');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────
    //  CUSTOMERS
    // ─────────────────────────────────────────────

    private function syncCustomers(): void
    {
        $this->info('👥 Syncing customers...');

        $rows = $this->sqlite
            ->query('
                SELECT c.*, a.name as area_name, p.name as package_name, p.price as package_price
                FROM customers c
                LEFT JOIN areas a ON c.area_id = a.id
                LEFT JOIN packages p ON c.package_id = p.id
            ')
            ->fetchAll(PDO::FETCH_ASSOC);

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        // Pre-load area and package caches to avoid N+1
        $areaCacheByName = Area::pluck('id', 'name')->toArray();
        $packageCacheByName = Package::pluck('id', 'name')->toArray();

        $updatedCount = 0;
        $deletedCount = 0;
        $suppressedCount = 0;
        $suppressedCodes = [];
        // Live (non-archived) eBilling rows: code => status (A8 suppression).
        $liveStatusByCode = Customer::query()->pluck('status', 'code')->map(fn ($s) => (string) $s)->all();

        DB::beginTransaction();

        foreach ($rows as $row) {
            $code = $row['code'] ?? $row['id'] ?? null;
            if (! $code) {
                $bar->advance();

                continue;
            }

            // Resolve or create Area
            $areaId = null;
            if (! empty($row['area_name'])) {
                $areaName = $row['area_name'];
                if (! isset($areaCacheByName[$areaName])) {
                    try {
                        $area = Area::firstOrCreate(
                            ['name' => $areaName],
                            ['code' => Str::slug($areaName) ?: 'area-'.Str::random(5)]
                        );
                        $areaCacheByName[$areaName] = $area->id;
                    } catch (\Exception $e) {
                        // If code collision, try with random suffix
                        try {
                            $area = Area::create([
                                'name' => $areaName,
                                'code' => Str::slug($areaName).'-'.Str::random(3),
                            ]);
                            $areaCacheByName[$areaName] = $area->id;
                        } catch (\Exception $e) {
                            $this->error("\nFailed to create area {$areaName}: ".$e->getMessage());
                        }
                    }
                }
                $areaId = $areaCacheByName[$areaName] ?? null;
            }

            // Resolve or create Package
            $packageId = null;
            $pkgName = ! empty($row['package_name']) ? $row['package_name'] : 'Paket Unknown';

            if (! isset($packageCacheByName[$pkgName])) {
                try {
                    $pkg = Package::firstOrCreate(
                        ['name' => $pkgName],
                        [
                            'price' => $row['package_price'] ?? 100000,
                            'code' => (Str::slug($pkgName) ?: 'pkg').'-'.Str::random(3),
                            'mikrotik_profile' => $pkgName,
                        ]
                    );
                    $packageCacheByName[$pkgName] = $pkg->id;
                } catch (\Exception $e) {
                    $this->error("\nFailed to resolve package {$pkgName}: ".$e->getMessage());
                }
            }
            $packageId = $packageCacheByName[$pkgName] ?? null;

            // Final fallback for package_id if it's still null (database integrity)
            if (! $packageId) {
                $packageId = Package::where('name', 'Paket Unknown')->value('id')
                    ?? Package::first()->id;
            }

            // Map status: SQLite "deleted" → Laravel "terminated"
            $status = match ($row['status'] ?? 'active') {
                'deleted' => 'terminated',
                'isolated' => 'isolated',
                'active' => 'active',
                default => 'active',
            };

            $wasDeleted = ($row['status'] ?? '') === 'deleted';

            // Roadmap 1.3 A8 (owner Q10): legacy "deleted" for a LIVE eBilling customer
            // (exists, not archived, not terminated) keeps its status; only reported.
            // The status column is then simply left out of the update, so a status
            // changed meanwhile is never reverted to a preloaded value.
            $suppressed = false;
            if ($status === 'terminated') {
                $liveStatus = $liveStatusByCode[(string) $code] ?? null;
                if ($liveStatus !== null && $liveStatus !== 'terminated') {
                    $suppressed = true;
                    $wasDeleted = false;
                    if (! isset($suppressedCodes[(string) $code])) {
                        $suppressedCodes[(string) $code] = true;
                        $suppressedCount++;
                    }
                }
            }

            // Ensure pppoe_user is not empty and unique
            $scrapedPppoeUser = $row['pppoe_username'] ?? $row['pppoe_user'] ?? null;
            $pppoeUser = ! empty($scrapedPppoeUser) ? $scrapedPppoeUser : ($code.'_PPPOE');

            try {
                $attributes = [
                    'name' => $row['name'] ?? 'Unknown',
                    'address' => $row['address'] ?? '-',
                    'phone' => $row['phone'] ?? '-',
                    'nik' => $row['nik'] ?? null,
                    'pppoe_user' => $pppoeUser,
                    'area_id' => $areaId,
                    'package_id' => $packageId,
                    'status' => $status,
                    'geo_lat' => $this->safeFloat($row['geo_lat'] ?? null, -90, 90),
                    'geo_long' => $this->safeFloat($row['geo_long'] ?? null, -180, 180),
                    'join_date' => $row['join_date'] ?? null,
                    'due_day' => $row['due_day'] ?? 20,
                    'ktp_photo_url' => $row['ktp_photo_url'] ?? null,
                    'is_online' => (bool) ($row['is_online'] ?? false),
                ];
                if ($suppressed) {
                    unset($attributes['status']);
                }
                Customer::updateOrCreate(['code' => $code], $attributes);

                // Suppressed rows are reported separately, not counted as synced.
                if (! $suppressed && $wasDeleted) {
                    $deletedCount++;
                } elseif (! $suppressed) {
                    $updatedCount++;
                }
            } catch (\Exception $e) {
                $this->error("\nFailed to sync customer {$code}: ".$e->getMessage());
            }

            $bar->advance();
        }

        DB::commit();
        $bar->finish();
        $this->newLine();
        $this->info("   ✔ {$updatedCount} active customers synced, {$deletedCount} marked terminated.");
        if ($suppressedCount > 0) {
            $this->warn("   legacy_terminate_suppressed={$suppressedCount} (live customers keep status; codes: ".implode(',', array_slice(array_keys($suppressedCodes), 0, 50)).($suppressedCount > 50 ? ',...' : '').')');
            Log::warning('[LifecycleGuard] legacy_terminate_suppressed', ['source' => 'ebilling:sync', 'count' => $suppressedCount]);
        }
    }

    // ─────────────────────────────────────────────
    //  INVOICES
    // ─────────────────────────────────────────────

    private function syncInvoices(): void
    {
        $this->info('💰 Syncing invoices...');

        $rows = $this->sqlite
            ->query('SELECT * FROM invoices')
            ->fetchAll(PDO::FETCH_ASSOC);

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        // Pre-load customer_id keyed by legacy code to avoid N+1
        $customerIdByCode = Customer::pluck('id', 'code')->toArray();
        $customerDueDay = Customer::pluck('due_day', 'id')->toArray();

        $upsertCount = 0;
        $skipCount = 0;
        $batchSize = 500;
        $processed = 0;

        DB::beginTransaction();

        foreach ($rows as $row) {
            $legacyCode = $row['customer_id'] ?? null; // SQLite stores the legacy id_pelanggan
            $periodStr = $row['period'] ?? null;

            if (! $legacyCode || ! $periodStr) {
                $skipCount++;
                $bar->advance();

                continue;
            }

            // Resolve Laravel customer ID
            if (! isset($customerIdByCode[$legacyCode])) {
                // Orphan: create stub customer so invoice history is preserved
                $stub = Customer::create([
                    'code' => $legacyCode,
                    'name' => 'Unknown (Deleted)',
                    'status' => 'terminated',
                    'due_day' => 20,
                ]);
                $customerIdByCode[$legacyCode] = $stub->id;
                $customerDueDay[$stub->id] = 20;
            }

            $customerId = $customerIdByCode[$legacyCode];

            try {
                $period = Carbon::parse($periodStr)->startOfMonth();
            } catch (\Exception) {
                $skipCount++;
                $bar->advance();

                continue;
            }

            $dueDay = $customerDueDay[$customerId] ?? 20;
            $dueDate = $period->copy()->day($dueDay <= $period->daysInMonth ? $dueDay : $period->daysInMonth);
            $status = str_contains(strtolower($row['status'] ?? ''), 'paid') ? 'paid' : 'unpaid';

            // Upsert — always update amount and status, not just on first insert
            Invoice::updateOrCreate(
                ['customer_id' => $customerId, 'period' => $period->toDateString()],
                [
                    'code' => $row['code'] ?? ('INV-'.$period->format('Ym').'-'.$legacyCode),
                    'amount' => $row['amount'] ?? 0,
                    'status' => $status,
                    'due_date' => $dueDate->toDateString(),
                    'payment_link' => $row['payment_link'] ?? null,
                    'generated_at' => $row['created_at'] ?? now(),
                ]
            );

            $upsertCount++;
            $processed++;

            if ($processed % $batchSize === 0) {
                DB::commit();
                DB::beginTransaction();
            }

            $bar->advance();
        }

        DB::commit();
        $bar->finish();
        $this->newLine();
        $this->info("   ✔ {$upsertCount} invoices upserted, {$skipCount} skipped (missing period/code).");
    }

    // ─────────────────────────────────────────────
    //  HELPERS
    // ─────────────────────────────────────────────

    private function safeFloat(?string $value, float $min, float $max): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $f = (float) $value;

        return ($f >= $min && $f <= $max) ? $f : null;
    }
}
