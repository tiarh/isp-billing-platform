<?php

namespace App\Services\Migration;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\PsbSiteRegistry;
use App\Support\OpsTime;
use Illuminate\Support\Str;

/**
 * Target contract and dry-run plan of the migration flow, moved out of
 * CustomerMigrationService without a change of behaviour. Stateless: no device call,
 * no collaborator.
 */
final class MigrationPlanBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function migrationTargetContract(CustomerMigration $migration, array $preflight): array
    {
        $customer = $preflight['customer_before'] ?? [];
        $name = Str::upper((string) ($migration->full_name ?: ($customer['name'] ?? '')));
        $school = $this->isSchoolOrInstitutionName($name);
        $router = $preflight['router'] ?? [];
        $routerId = (int) ($router['id'] ?? 0);

        // Krian migration keeps the customer's existing package/profile: the
        // media moves (HTB -> C300) without a speed change. An explicit Paket
        // hint still overrides through resolvePackageForMigration().
        $preservePackage = $this->migrationPreservesCustomerPackage($routerId, (string) ($router['name'] ?? ''));
        $customerProfile = trim((string) ($customer['mikrotik_profile'] ?? ''));
        $customerPackageId = (int) ($customer['package_id'] ?? 0);
        $profileSource = 'default';
        $profileWarning = null;
        $preserveBlocker = null;

        if ($preservePackage) {
            $package = $customerPackageId > 0
                ? Package::query()->whereKey($customerPackageId)->first()
                : null;
            $targetProfile = trim((string) ($package?->mikrotik_profile ?? ''));
            $profileSource = 'package';
            if ($targetProfile === '') {
                // The kept package carries no MikroTik profile (e.g. "Paket
                // Free"). customers.mikrotik_profile can be stale, so the live
                // PPPoE secret on the source router is the source of truth.
                [$targetProfile, $profileSource, $profileWarning] = $this->keptPackageFallbackProfile(
                    (array) ($preflight['mikrotik_secret'] ?? []),
                    $customerProfile,
                );
            }
            if ($targetProfile === '') {
                $preserveBlocker = 'package_profile_missing:package_'.($package?->id ?: ($customerPackageId ?: 'none'));
            }
        } else {
            $targetProfile = $school ? 'CSR' : 'STARTLITE/200M';
            $package = $this->findTargetPackage($targetProfile, $routerId, $routerId === 514);
        }

        return [
            'profile' => $targetProfile,
            'customer_type' => $school ? 'school_or_institution' : 'regular',
            'package_id' => $package?->id,
            'package_name' => $package?->name,
            'mikrotik_profile' => $package?->mikrotik_profile ?: $targetProfile,
            'package_blocker' => $preservePackage
                ? $preserveBlocker
                : ($package ? null : "package_mapping_missing:{$targetProfile}:router_{$routerId}"),
            'profile_source' => $profileSource,
            'profile_warning' => $profileWarning,
            'preserve_package' => $preservePackage,
            'krian' => $this->krianDryRunContract($routerId, $targetProfile, $package),
            'old_package_kept_as_before_snapshot' => true,
        ];
    }

    /**
     * Target profile for a kept package without its own MikroTik profile:
     * live source secret first, stale customers.mikrotik_profile only as a
     * warned fallback when the live read gave nothing usable.
     *
     * @param  array<string, mixed>  $secret  verifyExistingPppoeSecret() result
     * @return array{0: string, 1: string, 2: ?string}
     */
    public function keptPackageFallbackProfile(array $secret, string $customerProfile): array
    {
        $liveProfile = trim((string) ($secret['profile'] ?? ''));
        if (($secret['found'] ?? null) === true && $liveProfile !== '') {
            return [$liveProfile, 'live_pppoe_secret', null];
        }

        if ($customerProfile !== '') {
            return [
                $customerProfile,
                'customer_column_fallback',
                "package_profile_fallback_customer_column: profil live secret PPPoE tidak terbaca; memakai customers.mikrotik_profile '{$customerProfile}' (bisa usang), cek profil sebelum execute.",
            ];
        }

        return ['', 'none', null];
    }

    /**
     * Indonesian operator hint for blocking preflight reasons.
     *
     * @param  array<int, string>  $reasons
     */
    public function migrationReasonHint(array $reasons): string
    {
        foreach ($reasons as $reason) {
            if (str_starts_with((string) $reason, 'package_profile_missing:')) {
                return ' — Paket tidak punya profil MikroTik — pilih paket atau isi profil.';
            }
        }

        return '';
    }

    /**
     * Sites where a migration must keep the customer's current package/profile.
     * Krian moves the media (HTB -> C300) without a speed change. The needle
     * list is a Setting so a future site can opt in without a code change.
     */
    public function migrationPreservesCustomerPackage(int $routerId, string $routerName = ''): bool
    {
        $raw = (string) Setting::get('migration_preserve_package_router_needles', 'krian');
        $needles = array_values(array_filter(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            explode(',', $raw),
        )));
        if ($needles === []) {
            return false;
        }

        $name = strtolower(trim($routerName));
        if ($name === '' && $routerId > 0) {
            $name = strtolower((string) (Router::query()->whereKey($routerId)->value('name') ?? ''));
        }
        if ($name === '') {
            return false;
        }

        foreach ($needles as $needle) {
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function findTargetPackage(string $targetProfile, int $routerId, bool $strictRouter = false): ?Package
    {
        $query = Package::query()->where(function ($query) {
            $query->whereNull('is_active')->orWhere('is_active', true);
        });

        if ($targetProfile === 'CSR') {
            $query->where(function ($query) {
                $query->where('code', 'like', '%CSR%')
                    ->orWhere('name', 'like', '%CSR%')
                    ->orWhere('mikrotik_profile', 'like', '%CSR%');
            });
        } else {
            $query->where(function ($query) {
                $query->where('code', 'like', '%STARTLITE%')
                    ->orWhere('code', 'like', '%STARLITE%')
                    ->orWhere('name', 'like', '%STARTLITE%')
                    ->orWhere('name', 'like', '%STARLITE%')
                    ->orWhere('mikrotik_profile', 'like', '%STARTLITE%')
                    ->orWhere('mikrotik_profile', 'like', '%STARLITE%')
                    ->orWhere('mikrotik_profile', 'like', '%200M%');
            });
        }

        $routerPackage = (clone $query)->where('router_id', $routerId)->orderByDesc('id')->first();
        if ($strictRouter) {
            return $routerPackage;
        }

        return $routerPackage
            ?: (clone $query)->whereNull('router_id')->orderByDesc('id')->first()
            ?: $query->orderByDesc('id')->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function krianDryRunContract(int $routerId, string $targetProfile, ?Package $package): array
    {
        // Krian migration targets the existing Krian BNG (router #15, 598
        // customers) while the ONU is provisioned on OLT KRIAN (#9). Both the
        // HTB router and the Skynet-Krian-OLT router count as Krian targets.
        $isKrianTarget = $routerId === 514 || $this->migrationPreservesCustomerPackage($routerId);

        $krianSite = app(PsbSiteRegistry::class)->byKey('krian');
        $targetRouterId = (int) ($krianSite['router_id'] ?? 0) ?: 514;
        $targetRouterName = (string) (($krianSite['router'] ?? null)?->name ?? 'Krian');

        return [
            'applies' => $isKrianTarget,
            'source_router' => 'Krian',
            'target_router' => $targetRouterName,
            'target_router_id' => $targetRouterId,
            'target_olt' => 'OLT KRIAN',
            'customer_area' => 'Krian',
            'blockers' => ($isKrianTarget && $targetProfile === 'CSR' && ! $package)
                ? ['csr_package_mapping_router_'.$targetRouterId.'_missing']
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mikrotikDryRunDiff(CustomerMigration $migration, array $preflight, array $target): array
    {
        return [
            'router' => $preflight['router'] ?? null,
            'source_router' => $preflight['source_router'] ?? null,
            'cross_router' => (bool) ($preflight['cross_router'] ?? false),
            'pppoe_user' => $migration->pppoe_user,
            'secret_snapshot' => $preflight['mikrotik_secret'] ?? null,
            'target_profile' => $target['mikrotik_profile'] ?? $target['profile'] ?? null,
            'target_ip_action' => ($preflight['cross_router'] ?? false)
                ? 'allocate_from_target_router_pool_or_use_target_profile_pool'
                : 'preserve_existing_router_secret_addressing',
            'pppoe_plan' => $this->pppoeMigrationPlan($migration, $preflight),
            'comment' => $this->migrationSecretComment($migration),
            'mode' => 'planned_only_no_write',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pppoeMigrationPlan(CustomerMigration $migration, array $preflight): array
    {
        $customer = $preflight['customer_before'] ?? [];
        $current = MigrationNormalizer::normalizeUsername((string) ($migration->pppoe_user ?: ($customer['pppoe_user'] ?? '')));
        $oldOdp = MigrationNormalizer::normalizeTextForCompare((string) ($customer['odp_name'] ?? ''));
        $newOdpRaw = trim((string) ($migration->odp ?: ($customer['odp_name'] ?? '')));
        $newOdp = MigrationNormalizer::normalizeTextForCompare($newOdpRaw);
        $oldSuffix = MigrationNormalizer::odpSuffix((string) ($customer['odp_name'] ?? ''));
        $newSuffix = MigrationNormalizer::odpSuffix($newOdpRaw);
        $odpChanged = $newOdp !== '' && $oldOdp !== '' && $newOdp !== $oldOdp;
        $target = $current;
        $notes = [];

        if ($odpChanged && $newSuffix !== '' && $current !== '' && str_contains($current, '_')) {
            $target = preg_replace('/_[^_]+$/', '_'.$newSuffix, $current) ?: $current;
        } elseif ($odpChanged) {
            $notes[] = 'odp_changed_but_username_suffix_needs_manual_review';
        } else {
            $notes[] = 'odp_same_or_unknown_keep_username_profile_only';
        }

        return [
            'current_username' => $current ?: null,
            'target_username' => $target ?: null,
            'username_action' => $odpChanged ? 'rename_for_new_odp' : 'keep_existing',
            'profile_action' => 'set_target_profile',
            'password_action' => 'preserve_existing',
            'old_odp' => $customer['odp_name'] ?? null,
            'new_odp' => $newOdpRaw ?: null,
            'old_odp_suffix' => $oldSuffix ?: null,
            'new_odp_suffix' => $newSuffix ?: null,
            'odp_changed' => $odpChanged,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ktpMigrationPlan(CustomerMigration $migration, array $preflight): array
    {
        $customer = $preflight['customer_before'] ?? [];
        $existing = trim((string) ($customer['ktp_photo_url'] ?? ''));
        $incoming = trim((string) ($migration->ktp_photo_path ?? ''));

        return [
            'existing' => $existing !== '',
            'incoming' => $incoming !== '',
            'action' => $incoming !== ''
                ? 'update_if_customer_empty_or_replaceable'
                : ($existing !== '' ? 'keep_existing' : 'request_upload_from_technician'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function oltDryRunDiff(CustomerMigration $migration, array $preflight, array $target): array
    {
        return [
            'olt' => $preflight['olt'] ?? null,
            'old_onu_ref' => $migration->old_onu_ref ?: 'unknown',
            'old_sn' => MigrationNormalizer::normalizeSerial((string) $migration->old_sn) ?: 'unknown',
            'new_onu_ref' => $migration->new_onu_ref ?: 'unknown',
            'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: 'unknown',
            'target_bandwidth' => ($target['profile'] ?? '') === 'CSR' ? 'CSR' : '200M',
            'snapshot' => $preflight['olt_snapshot'] ?? null,
            'mode' => 'planned_only_no_write',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function genieAcsDryRunDiff(CustomerMigration $migration, array $preflight): array
    {
        return [
            'new_sn' => MigrationNormalizer::normalizeSerial((string) $migration->new_sn) ?: 'unknown',
            'snapshot' => $preflight['genieacs_snapshot'] ?? null,
            'target_label' => $migration->pppoe_user ?: $migration->full_name,
            'mode' => 'planned_only_no_write',
        ];
    }

    /**
     * @return list<string>
     */
    public function unknownMigrationInputs(CustomerMigration $migration, array $preflight): array
    {
        $unknown = [];
        if (MigrationNormalizer::normalizeUsername((string) $migration->pppoe_user) === '') {
            $unknown[] = 'pppoe_missing';
        }
        if (MigrationNormalizer::normalizeSerial((string) $migration->old_sn) === '') {
            $unknown[] = 'old_sn_unknown';
        }
        if (! $migration->old_onu_ref) {
            $unknown[] = 'old_onu_ref_unknown';
        }
        if (! (($preflight['mikrotik_secret']['found'] ?? null) === true)) {
            $unknown[] = 'old_pppoe_secret_not_confirmed';
        }

        return array_values(array_unique($unknown));
    }

    public function migrationSecretComment(CustomerMigration $migration): string
    {
        $date = OpsTime::now()->format('dMY');
        $reason = trim((string) data_get($migration->meta, 'device_action_approval.reason', 'migrasi'));
        $meta = MigrationNormalizer::meta($migration);

        if ((bool) ($meta['cross_router'] ?? false)) {
            $source = (string) data_get($meta, 'router_mapping.source_input', '');
            $target = (string) data_get($meta, 'router_mapping.target_input', '');
            $route = trim($source.'->'.$target, '->');
            $label = $route !== '' ? 'migrasi '.$route : 'migrasi';
            if (trim((string) ($meta['keterangan'] ?? '')) !== '') {
                $label .= ' ('.mb_substr(trim((string) $meta['keterangan']), 0, 60).')';
            }

            return trim("{$label} {$date}");
        }

        return trim("migrasi {$date} - {$reason}");
    }

    public function targetProfileForMigration(CustomerMigration $migration, Customer $customer): string
    {
        $name = (string) ($migration->full_name ?: $customer->name);

        return $this->isSchoolOrInstitutionName($name) ? 'CSR' : 'STARTLITE/200M';
    }

    public function isSchoolOrInstitutionName(string $name): bool
    {
        return Str::contains(Str::upper($name), ['SD ', 'SMP', 'SMA', 'SMK', 'MI ', 'MTS', 'MA ', 'SEKOLAH', 'PONDOK', 'YAYASAN']);
    }
}
