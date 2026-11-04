<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Package;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NormalizeStarliteCustomerProfiles extends Command
{
    private const STARTLITE_PROFILE = '200M+ STARTLITE';

    private const CSR_PROFILE = 'CSR';

    /** @var list<string> */
    private const STARLITE_TERMS = ['%STARTLITE%', '%STARLITE%'];

    protected $signature = 'customers:normalize-starlite-profiles
                            {--apply : Write DB changes after dry-run review}
                            {--limit=0 : Limit customers processed, 0 for all}
                            {--reason= : Required reason for apply}
                            {--actor-id= : Operator user ID for audit context}';

    protected $description = 'Normalize Starlite customers to 200M+ STARTLITE and school/institution customers to CSR without device writes';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $reason = trim((string) $this->option('reason'));

        if ($apply && $reason === '') {
            $this->error('--reason wajib untuk --apply.');

            return self::FAILURE;
        }

        $starlitePackages = $this->starlitePackages()->get();
        $csrPackages = $this->csrPackages()
            ->get()
            ->groupBy(fn (Package $package) => (int) ($package->router_id ?? 0))
            ->map(fn ($rows) => $rows->first());
        $globalCsr = $csrPackages->get(0);

        $customers = $this->candidateCustomers($starlitePackages->pluck('id')->map(fn ($id) => (int) $id)->all(), $limit)
            ->with('package')
            ->orderBy('id')
            ->get();

        $plan = [
            'mode' => $apply ? 'apply' : 'dry_run',
            'device_write' => false,
            'target_startlite_profile' => self::STARTLITE_PROFILE,
            'target_csr_profile' => self::CSR_PROFILE,
            'package_updates' => [],
            'customer_updates' => [],
            'skipped' => [],
        ];

        foreach ($starlitePackages as $package) {
            if ((string) $package->mikrotik_profile !== self::STARTLITE_PROFILE) {
                $plan['package_updates'][] = [
                    'id' => $package->id,
                    'code' => $package->code,
                    'name' => $package->name,
                    'router_id' => $package->router_id,
                    'from_profile' => $package->mikrotik_profile,
                    'to_profile' => self::STARTLITE_PROFILE,
                ];
            }
        }

        foreach ($customers as $customer) {
            $school = $this->isSchoolCustomer($customer);
            $targetPackage = $school ? $this->csrPackageFor($csrPackages, $globalCsr, $customer) : null;
            if ($school && ! $targetPackage) {
                $plan['skipped'][] = [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'reason' => 'CSR package aktif untuk router/global belum ada',
                ];

                continue;
            }

            $targetPackageId = $school ? $targetPackage->id : $customer->package_id;
            $targetProfile = $school ? self::CSR_PROFILE : self::STARTLITE_PROFILE;

            if ((int) $customer->package_id === (int) $targetPackageId
                && (string) $customer->mikrotik_profile === $targetProfile) {
                continue;
            }

            $plan['customer_updates'][] = [
                'customer_id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'pppoe_user' => $customer->pppoe_user,
                'router_id' => $customer->router_id,
                'school_detected' => $school,
                'from_package_id' => $customer->package_id,
                'to_package_id' => $targetPackageId,
                'from_profile' => $customer->mikrotik_profile,
                'to_profile' => $targetProfile,
            ];
        }

        $this->line(json_encode([
            'summary' => [
                'candidate_customers' => $customers->count(),
                'package_updates' => count($plan['package_updates']),
                'customer_updates' => count($plan['customer_updates']),
                'school_to_csr' => collect($plan['customer_updates'])->where('school_detected', true)->count(),
                'startlite_to_200m' => collect($plan['customer_updates'])->where('school_detected', false)->count(),
                'skipped' => count($plan['skipped']),
            ],
            'sample_customer_updates' => array_slice($plan['customer_updates'], 0, 30),
            'package_updates' => $plan['package_updates'],
            'skipped' => $plan['skipped'],
            'device_write' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if (! $apply) {
            $this->warn('DRY RUN selesai. Tambah --apply --reason="..." untuk update DB saja.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($plan, $reason): void {
            foreach ($plan['package_updates'] as $row) {
                Package::query()
                    ->whereKey($row['id'])
                    ->update([
                        'mikrotik_profile' => self::STARTLITE_PROFILE,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($plan['customer_updates'] as $row) {
                Customer::query()
                    ->whereKey($row['customer_id'])
                    ->update([
                        'package_id' => $row['to_package_id'],
                        'mikrotik_profile' => $row['to_profile'],
                        'updated_at' => now(),
                    ]);
            }

            DB::table('activity_log')->insert([
                'log_name' => 'starlite_profile_normalization',
                'description' => 'normalize_starlite_profiles',
                'event' => 'updated',
                'subject_type' => null,
                'subject_id' => null,
                'causer_type' => null,
                'causer_id' => $this->option('actor-id') ? (int) $this->option('actor-id') : null,
                'properties' => json_encode([
                    'reason' => $reason,
                    'package_updates' => count($plan['package_updates']),
                    'customer_updates' => count($plan['customer_updates']),
                    'school_to_csr' => collect($plan['customer_updates'])->where('school_detected', true)->count(),
                    'device_write' => false,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->info('APPLY selesai; device_write=false.');

        return self::SUCCESS;
    }

    private function starlitePackages(): Builder
    {
        return Package::query()
            ->where(function (Builder $query): void {
                foreach (self::STARLITE_TERMS as $term) {
                    $query->orWhereRaw("UPPER(COALESCE(name, '')) LIKE ?", [$term])
                        ->orWhereRaw("UPPER(COALESCE(code, '')) LIKE ?", [$term])
                        ->orWhereRaw("UPPER(COALESCE(mikrotik_profile, '')) LIKE ?", [$term]);
                }
            })
            ->where(function (Builder $query): void {
                $query->whereNull('mikrotik_profile')
                    ->orWhereRaw("UPPER(COALESCE(mikrotik_profile, '')) != ?", [self::CSR_PROFILE]);
            });
    }

    private function csrPackages(): Builder
    {
        return Package::query()
            ->where(function (Builder $query): void {
                $query->where('name', 'like', '%CSR%')
                    ->orWhere('code', 'like', '%CSR%')
                    ->orWhere('mikrotik_profile', 'like', '%CSR%');
            })
            ->where(function (Builder $query): void {
                $query->where('is_active', true)->orWhereNull('is_active');
            })
            ->orderByDesc('router_id')
            ->orderByDesc('id');
    }

    /**
     * @param  list<int>  $starlitePackageIds
     */
    private function candidateCustomers(array $starlitePackageIds, int $limit): Builder
    {
        $query = Customer::query()
            ->where(function (Builder $query) use ($starlitePackageIds): void {
                if ($starlitePackageIds !== []) {
                    $query->whereIn('package_id', $starlitePackageIds);
                }
                foreach (self::STARLITE_TERMS as $term) {
                    $query->orWhereRaw("UPPER(COALESCE(mikrotik_profile, '')) LIKE ?", [$term]);
                }
            });

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query;
    }

    /**
     * Detect only clear institution prefixes/words. Avoid bare "RA" because it is
     * also used as router/site shorthand in this project.
     */
    private function isSchoolCustomer(Customer $customer): bool
    {
        $haystack = strtoupper(trim((string) $customer->name.' '.(string) $customer->pppoe_user));

        return (bool) preg_match('/^(MI|MTS|MA|SD|SMP|SMA|SMK|TK|PAUD|TPQ)\b|MADRASAH|PONDOK|PONPES|YAYASAN/u', $haystack);
    }

    /**
     * @param  Collection<int, Package>  $csrPackages
     */
    private function csrPackageFor($csrPackages, ?Package $globalCsr, Customer $customer): ?Package
    {
        $routerPackage = $customer->router_id ? $csrPackages->get((int) $customer->router_id) : null;

        return $routerPackage ?: $globalCsr ?: $csrPackages->first();
    }
}
