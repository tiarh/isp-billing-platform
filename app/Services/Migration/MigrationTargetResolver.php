<?php

namespace App\Services\Migration;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\Router;
use App\Services\PsbSiteRegistry;
use App\Services\PsbSiteResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Target resolution of the migration flow (customer lookup by PPPoE, router, OLT, package,
 * area, site and the routing predicates), moved out of CustomerMigrationService without a
 * change of behaviour. resolveCustomer stays on the facade (tests mock it).
 *
 * The PSB facade and the message parser are read through the seams at call time.
 */
final class MigrationTargetResolver
{
    public function __construct(
        private MigrationSeams $seams,
        private MigrationEvidenceReader $evidence,
        private MigrationPlanBuilder $plans,
    ) {}

    public function findCustomerByPppoe(string $pppoe): ?Customer
    {
        $pppoe = MigrationNormalizer::normalizeUsername($pppoe);
        if ($pppoe === '') {
            return null;
        }

        $matches = Customer::with(['router', 'olt', 'package'])
            ->whereRaw('LOWER(pppoe_user) = ?', [strtolower($pppoe)])
            ->limit(3)
            ->get();

        return $matches->first(fn (Customer $customer): bool => trim((string) $customer->pppoe_user) === $pppoe);
    }

    /**
     * Resolve the PPPoE username for a Gadang Huawei router-replacement
     * migration when the technician did not send it. Read-only lookup by old
     * SN (customer.onu_serial) then by customer name, scoped to the target
     * router when known.
     *
     * @param  array<string, mixed>  $parsed
     * @return array{pppoe: ?string, source: string, customer_id?: int}
     */
    public function resolvePppoeForHuaweiDraft(array $parsed, string $oldSn, string $targetRouterField): array
    {
        $name = trim((string) ($parsed['full_name'] ?? ''));
        $routerId = null;
        if ($targetRouterField !== '') {
            $routerId = $this->resolveMigrationRouterAlias($targetRouterField)?->id;
        }

        // Try the target router first, then fall back to a global lookup so a
        // cross-router customer (still on the source router) is found too.
        $scopes = $routerId
            ? [fn ($q) => $q->where('router_id', $routerId), fn ($q) => $q]
            : [fn ($q) => $q];

        if ($oldSn !== '') {
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $oldSn) ?? '');
            foreach ($scopes as $scope) {
                $match = $scope(Customer::query())
                    ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(onu_serial, ""), "-", ""), " ", "")) = ?', [$normalized])
                    ->orderByDesc('id')
                    ->first();
                if ($match && trim((string) $match->pppoe_user) !== '') {
                    return [
                        'pppoe' => MigrationNormalizer::normalizeUsername((string) $match->pppoe_user),
                        'source' => 'old_sn',
                        'customer_id' => (int) $match->id,
                    ];
                }
            }
        }

        if ($name !== '') {
            foreach ($scopes as $scope) {
                $match = $scope(Customer::query())
                    ->where('name', 'like', $name)
                    ->orderByDesc('id')
                    ->first();
                if ($match && trim((string) $match->pppoe_user) !== '') {
                    return [
                        'pppoe' => MigrationNormalizer::normalizeUsername((string) $match->pppoe_user),
                        'source' => 'name',
                        'customer_id' => (int) $match->id,
                    ];
                }
            }
        }

        return ['pppoe' => null, 'source' => 'unresolved'];
    }

    public function primeMigrationFromCustomer(CustomerMigration $migration, Customer $customer): void
    {
        $old = $this->evidence->oldOnuSnapshot($customer);
        foreach (array_keys($old) as $key) {
            if (filled($migration->{$key})) {
                unset($old[$key]);
            }
        }
        $fill = array_merge($old, [
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'full_name' => $migration->full_name ?: $customer->name,
            'phone' => $migration->phone ?: $customer->phone,
            'area_id' => $migration->area_id ?: $customer->area_id,
            'router_id' => $migration->router_id ?: $customer->router_id,
        ]);
        if (! $migration->daerah && $customer->area) {
            $fill['daerah'] = $customer->area->name;
        }
        if (! $migration->odp && Schema::hasColumn('customers', 'odp_name')) {
            $fill['odp'] = $customer->odp_name;
        }
        $migration->fill(array_filter($fill, fn ($v) => $v !== null && $v !== ''));
        $migration->save();
    }

    public function resolveRouterForMigration(CustomerMigration $migration, Customer $customer): ?Router
    {
        $rawRouter = trim((string) (($this->seams->parser)()->parse((string) $migration->raw_text)['router_field'] ?? ''));
        if ($rawRouter !== '') {
            $explicit = $this->resolveMigrationRouterAlias($rawRouter);
            if ($explicit) {
                return $explicit;
            }

            return null;
        }

        $id = (int) ($migration->router_id ?: $customer->router_id ?: 0);
        if ($id > 0) {
            return Router::query()->whereKey($id)->where('is_active', true)->first();
        }

        $resolved = ($this->seams->psb)()->resolveRouter();

        return $resolved && $resolved->is_active ? $resolved : null;
    }

    public function resolveSourceRouterForMigration(CustomerMigration $migration, Customer $customer): ?Router
    {
        $parsed = ($this->seams->parser)()->parse((string) $migration->raw_text);
        $rawRouter = trim((string) ($parsed['old_router_field'] ?? ''));
        if ($rawRouter !== '') {
            $explicit = $this->resolveMigrationRouterAlias($rawRouter);
            if ($explicit) {
                return $explicit;
            }

            return null;
        }

        $id = (int) ($migration->old_router_id ?: $customer->router_id ?: 0);
        if ($id > 0) {
            return Router::query()->whereKey($id)->where('is_active', true)->first();
        }

        return null;
    }

    public function resolveMigrationRouterAlias(string $alias): ?Router
    {
        $raw = trim($alias);
        if ($raw === '') {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '', $raw) ?? '');
        if ($normalized !== '') {
            // Exact-name first: "Randuagung" must resolve to router 1, not the
            // RA site registry (513); "Krian" to 15. The krian->514 site move is
            // expressed by writing `Router: krian-olt` in the WA format, which
            // this exact-name path resolves to 514 naturally.
            $exact = Router::query()->where('is_active', true)->get(['id', 'name'])->first(
                fn (Router $router) => strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) $router->name) ?? '') === $normalized
            );
            if ($exact) {
                // The name-only query avoids loading secrets; rehydrate before
                // the MikroTik client reads ip_address, port, or credentials.
                return Router::query()->find($exact->id);
            }
        }

        $resolved = app(PsbSiteResolver::class)->resolveRouterAlias($raw);

        return $resolved && $resolved->is_active ? $resolved : null;
    }

    public function migrationHasExplicitSourceRouter(CustomerMigration $migration): bool
    {
        return trim((string) (($this->seams->parser)()->parse((string) $migration->raw_text)['old_router_field'] ?? '')) !== '';
    }

    public function resolveOltForMigration(CustomerMigration $migration, Customer $customer, ?Router $router): ?Olt
    {
        // Explicit new OLT always wins.
        $id = (int) ($migration->new_olt_id ?: 0);
        if ($id > 0) {
            $olt = Olt::query()->whereKey($id)->first();
            if ($olt) {
                return $olt;
            }
        }

        // Explicit site/OLT text resolves through the PSB site registry (and a
        // name/code fallback). This lets a migration target an OLT that is not
        // linked to the target router row, e.g. Krian HTB (#15) -> OLT KRIAN
        // (#9). Without it the resolver falls back to the router's own OLTs and
        // then to the global default (OLT RA).
        $explicitOlt = $this->resolveOltFromMigrationField($migration);
        if ($explicitOlt) {
            return $explicitOlt;
        }

        // Only inherit the customer's current OLT when it actually belongs to
        // the target router. For cross-router migrations customer.olt_id points
        // at the SOURCE OLT and must NOT be reused as the target OLT — doing so
        // would provision the new ONU on the old OLT/router.
        if ($router && $customer->olt_id) {
            $inherited = Olt::query()->whereKey((int) $customer->olt_id)->first();
            if ($inherited && (int) ($inherited->router_id ?? 0) === (int) $router->id) {
                return $inherited;
            }
        }

        if ($router) {
            // Prefer ZTE C300 (legacy full_olt path), then fall back to any OLT
            // owned by the target router (e.g. FiberHome) so cross-router
            // migrations to a FiberHome-only target do not silently resolve null.
            $olt = Olt::query()
                ->where('router_id', $router->id)
                ->where(function ($q) {
                    $q->where('vendor', 'like', '%zte%')
                        ->orWhere('name', 'like', '%C300%');
                })
                ->orderBy('id')
                ->first();
            if (! $olt) {
                $olt = Olt::query()->where('router_id', $router->id)->orderBy('id')->first();
            }
            if ($olt) {
                return $olt;
            }
        }

        return ($this->seams->psb)()->resolveOlt();
    }

    /**
     * Resolve the target OLT from the technician's message. The PSB site
     * registry is the source of truth (e.g. `Krian` -> OLT KRIAN #9), with a
     * name/code fallback for free-text OLT labels. Ambiguous input returns
     * null so the caller can fall through to the router-based resolution.
     */
    public function resolveOltFromMigrationField(CustomerMigration $migration): ?Olt
    {
        $parsed = ($this->seams->parser)()->parse((string) $migration->raw_text);
        $registry = app(PsbSiteRegistry::class);

        foreach (['olt_field', 'daerah', 'new_router_field', 'router_field'] as $key) {
            $fieldKey = trim((string) ($parsed[$key] ?? ''));
            if ($fieldKey === '') {
                continue;
            }
            $site = $registry->byKey($fieldKey);
            $oltId = (int) ($site['olt_id'] ?? 0);
            if ($oltId > 0 && ($olt = Olt::query()->whereKey($oltId)->first())) {
                return $olt;
            }
        }

        $field = trim((string) ($parsed['olt_field'] ?? ''));
        if ($field === '') {
            return null;
        }

        $oltKey = MigrationNormalizer::normalizeOltToken($field);
        if ($oltKey === '') {
            return null;
        }

        $contains = [];
        foreach (Olt::query()->get(['id', 'name', 'code']) as $olt) {
            $name = MigrationNormalizer::normalizeOltToken((string) $olt->name);
            $code = MigrationNormalizer::normalizeOltToken((string) ($olt->code ?? ''));
            if (($name !== '' && $name === $oltKey) || ($code !== '' && $code === $oltKey)) {
                return Olt::query()->find((int) $olt->id);
            }
            if (($name !== '' && str_contains($name, $oltKey)) || ($code !== '' && str_contains($code, $oltKey))) {
                $contains[] = (int) $olt->id;
            }
        }

        $contains = array_values(array_unique($contains));

        return count($contains) === 1 ? Olt::query()->find($contains[0]) : null;
    }

    public function resolvePackageForMigration(CustomerMigration $migration, Customer $customer): ?Package
    {
        $meta = MigrationNormalizer::meta($migration);
        $id = (int) ($meta['package_id'] ?? 0);
        if ($id > 0) {
            return Package::query()->whereKey($id)->first();
        }

        $hint = trim((string) ($meta['package_hint'] ?? ''));
        if ($hint === '') {
            return $this->targetPackageForMigration($migration, $customer);
        }

        $package = $this->resolvePackageFromHint($hint, (int) ($migration->router_id ?: $customer->router_id ?: 0) ?: null);
        if ($package) {
            $meta['package_id'] = $package->id;
            $meta['package_name'] = $package->name;
            $meta['mikrotik_profile'] = $package->mikrotik_profile;
            $migration->meta = $meta;
            $migration->save();
        }

        return $package;
    }

    public function targetPackageForMigration(CustomerMigration $migration, Customer $customer): ?Package
    {
        $routerId = (int) ($migration->router_id ?: $customer->router_id ?: 0);

        // Krian keeps the customer's current package. When the package row is
        // absent (or has no profile) the caller uses the live source secret
        // profile, then customer.mikrotik_profile, so the speed still does
        // not change.
        if ($this->plans->migrationPreservesCustomerPackage($routerId)) {
            $packageId = (int) ($customer->package_id ?? 0);

            return $packageId > 0 ? Package::query()->whereKey($packageId)->first() : null;
        }

        $profile = $this->plans->targetProfileForMigration($migration, $customer);

        return $this->plans->findTargetPackage($profile, $routerId);
    }

    public function resolvePackageFromHint(string $hint, ?int $routerId = null): ?Package
    {
        $hint = trim($hint);
        if ($hint === '') {
            return null;
        }

        return ($this->seams->psb)()->resolvePackageFromHint($hint, $routerId);
    }

    public function vlanForOltPort(Olt $olt, string $port): ?int
    {
        if (! Schema::hasTable('olt_vlan_mappings')) {
            return null;
        }

        $port = MigrationNormalizer::normalizePonPort($port);
        if (! $port) {
            return null;
        }

        $row = OltVlanMapping::query()
            ->where('olt_id', $olt->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('service_type', 'internet')
                    ->orWhereNull('service_type')
                    ->orWhere('service_type', '');
            })
            ->whereRaw('LOWER(REPLACE(pon_port, "gpon-olt_", "")) = ?', [strtolower($port)])
            ->first();

        return $row ? (int) $row->vlan : null;
    }

    public function packageSpeed(Customer $customer, CustomerMigration $migration): string
    {
        $target = $this->plans->targetProfileForMigration($migration, $customer);
        if ($target === 'STARTLITE/200M') {
            return '200M';
        }

        $meta = MigrationNormalizer::meta($migration);
        $hint = (string) ($meta['package_hint'] ?? '');
        $parts = [
            $hint,
            (string) optional($customer->package)->mikrotik_profile,
            (string) optional($customer->package)->rate_limit,
            (string) optional($customer->package)->name,
            (string) $customer->mikrotik_profile,
        ];
        foreach ($parts as $part) {
            if (preg_match('/(\d{1,4})\s*(?:M|MB|MBPS|Mbit)?/i', $part, $m)) {
                $n = max(1, min(1000, (int) $m[1]));

                return $n.'M';
            }
        }

        return '100M';
    }

    public function resolveArea(string $area): ?Area
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $area) ?? '');
        if ($key === '') {
            return null;
        }

        $aliases = [
            'rdg' => 'randuagung',
            'ra' => 'randuagung',
            'kalmpok' => 'klampok',
            'klampok' => 'klampok',
        ];
        $want = $aliases[$key] ?? $key;
        $best = null;
        $score = 0;
        foreach (Area::query()->get(['id', 'name']) as $areaRow) {
            $name = strtolower(preg_replace('/[^a-z0-9]+/i', '', $areaRow->name) ?? '');
            $name = preg_replace('/^(skynet|subnet)/', '', $name) ?? $name;
            $s = 0;
            if ($name === $want) {
                $s = 100;
            } elseif (str_contains($name, $want) || str_contains($want, $name)) {
                $s = 40 + min(strlen($name), 20);
            }
            if ($s > $score) {
                $score = $s;
                $best = $areaRow;
            }
        }

        return $score >= 40 ? $best : null;
    }

    /** @param array<string, mixed> $parsed */
    public function isHuaweiOltOnlyParsed(array $parsed): bool
    {
        $mode = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', (string) ($parsed['mode_field'] ?? '')) ?? '');

        if (in_array($mode, ['OLTONLYHUAWEI', 'HUAWEIOLTONLY'], true)) {
            return true;
        }

        $site = $this->resolveMigrationTargetSite($parsed);
        $isHuaweiSite = $site !== null
            && (string) ($site['mode'] ?? '') === PsbSiteRegistry::MODE_HUAWEI_OLT;

        if ($this->isOltShortForm($parsed)) {
            // Site-aware: only a Huawei site may take the Huawei OLT-only short
            // form. A Krian/RA short form resolves to a full_olt site and must
            // stay on the C300/FiberHome path instead of being hijacked into
            // the Huawei adapter (which would look for a Huawei OLT).
            if ($site !== null) {
                return $isHuaweiSite;
            }

            // No site hint at all: keep the historical contract (PPPoE + new
            // SN, no discriminator -> Huawei preflight is authoritative).
            return true;
        }

        // Gadang Huawei router-replacement form: the technician sends a full
        // message (Nama, PPPoE, SN baru, SN lama, OLT/Router/Daerah Gadang,
        // RT/RW, Keterangan). When it resolves to a Huawei site and carries a
        // new SN, it must use the Huawei OLT-only adapter, not the guided
        // customer lookup (which expects a cross-router customer migration).
        if ($isHuaweiSite && trim((string) ($parsed['new_sn'] ?? '')) !== '') {
            return true;
        }

        return false;
    }

    /**
     * The Huawei short form is a bare PPPoE + new SN with no customer or old
     * ONU discriminator. It is distinct from the guided customer lookup.
     *
     * @param  array<string, mixed>  $parsed
     */
    public function isOltShortForm(array $parsed): bool
    {
        return trim((string) ($parsed['pppoe_user'] ?? '')) !== ''
            && trim((string) ($parsed['new_sn'] ?? '')) !== ''
            && trim((string) ($parsed['full_name'] ?? '')) === ''
            && trim((string) ($parsed['onu_name'] ?? '')) === ''
            && trim((string) ($parsed['old_sn'] ?? '')) === '';
    }

    /**
     * A bare PPPoE + SN short form that resolves to a non-Huawei site
     * (Krian/RA) belongs on the existing C300/FiberHome uncfg path, not the
     * guided customer lookup and not the Huawei adapter.
     *
     * @param  array<string, mixed>  $parsed
     */
    public function shouldUseC300UncfgPath(array $parsed): bool
    {
        if (! $this->isOltShortForm($parsed)) {
            return false;
        }

        $site = $this->resolveMigrationTargetSite($parsed);
        if ($site === null) {
            return false;
        }

        return (string) ($site['mode'] ?? '') !== PsbSiteRegistry::MODE_HUAWEI_OLT;
    }

    /**
     * Resolve a migration message to a canonical PSB site through the registry
     * so Gadang (Huawei) and Krian (C300) target selection no longer depends on
     * the message shape or on fragile name-LIKE fallbacks. Free-text OLT names
     * such as `OLT GADANG 3` are matched through the registry aliases.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>|null
     */
    public function resolveMigrationTargetSite(array $parsed): ?array
    {
        $candidates = [
            $parsed['daerah'] ?? null,
            $parsed['new_router_field'] ?? null,
            $parsed['router_field'] ?? null,
            $parsed['olt_field'] ?? null,
        ];

        $hasCandidate = false;
        foreach ($candidates as $candidate) {
            if (trim((string) $candidate) !== '') {
                $hasCandidate = true;
                break;
            }
        }
        if (! $hasCandidate) {
            return null;
        }

        $registry = app(PsbSiteRegistry::class);
        foreach ($candidates as $candidate) {
            $siteKey = trim((string) $candidate);
            if ($siteKey === '') {
                continue;
            }
            $site = $registry->byKey($siteKey);
            if (is_array($site)) {
                return $site;
            }
        }

        // Free-text OLT name fallback against registry aliases / olt_match.
        // Exact normalized match only: a substring match could resolve
        // `OLT GADANG 3` to the legacy Hioso site instead of Huawei.
        $oltToken = MigrationNormalizer::normalizeSiteToken((string) ($parsed['olt_field'] ?? ''));
        if ($oltToken !== '') {
            foreach ($registry->all() as $site) {
                $needles = array_merge(
                    (array) ($site['olt_match'] ?? []),
                    (array) ($site['aliases'] ?? []),
                    [(string) ($site['name'] ?? '')],
                );
                foreach ($needles as $needle) {
                    if (MigrationNormalizer::normalizeSiteToken((string) $needle) === $oltToken) {
                        return $site;
                    }
                }
            }
        }

        return null;
    }
}
