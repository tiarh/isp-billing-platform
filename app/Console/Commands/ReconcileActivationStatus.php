<?php

namespace App\Console\Commands;

use App\Models\CustomerMigration;
use App\Models\OltOnu;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\OltOnuSerialResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles database workflow status from existing device evidence.
 * It never provisions, deletes, renames, or changes customer activation.
 */
class ReconcileActivationStatus extends Command
{
    protected $signature = 'ops:reconcile-activation-status
        {--apply : Persist only the explicitly safe status promotions}
        {--days=1 : Look back this many WIB calendar days}
        {--resolve-migration-siblings : Archive needs_review rows with an exact synced sibling}';

    protected $description = 'Reconcile PSB/migration status from OLT snapshot and read-only MikroTik evidence';

    public function handle(): int
    {
        $days = max(1, min(7, (int) $this->option('days')));
        $now = now('Asia/Jakarta');
        $start = $now->copy()->subDays($days - 1)->startOfDay()->utc();
        $end = $now->copy()->endOfDay()->utc();

        $drafts = PsbDraft::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', [PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_OLT_OK])
            ->whereNotNull('router_id')
            ->whereNotNull('sn')
            ->get(['id', 'status', 'router_id', 'olt_id', 'sn', 'pppoe_user', 'customer_id', 'meta']);
        $migrations = CustomerMigration::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', [CustomerMigration::STATUS_NEEDS_REVIEW, CustomerMigration::STATUS_OLT_OK])
            ->whereNotNull('router_id')
            ->whereNotNull('new_sn')
            ->get(['id', 'status', 'router_id', 'new_olt_id', 'new_sn', 'pppoe_user', 'customer_id', 'meta']);

        if ($drafts->isEmpty() && $migrations->isEmpty()) {
            $this->info('Tidak ada draft/migrasi yang perlu direkonsiliasi.');

            return self::SUCCESS;
        }

        $onus = $this->loadOnus($drafts, $migrations);
        $evidenceByRouter = [];
        foreach ($drafts->pluck('router_id')->merge($migrations->pluck('router_id'))->filter()->unique() as $routerId) {
            $evidenceByRouter[(int) $routerId] = $this->readRouterEvidence((int) $routerId);
        }

        foreach ($drafts as $draft) {
            $evidence = $this->evidenceFor(
                (int) $draft->router_id,
                (int) $draft->olt_id,
                (string) $draft->sn,
                (string) $draft->pppoe_user,
                $onus,
                $evidenceByRouter[(int) $draft->router_id] ?? null,
            );
            $this->renderDraft($draft, $evidence);

            // OLT_OK is the highest safe automatic promotion. `applied` also
            // changes customer business status, so it remains an admin action.
            if ($this->option('apply') && $draft->status === PsbDraft::STATUS_SECRET_OK
                && $draft->customer_id && $evidence['strong']) {
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $meta['operational_status_reconcile'] = [
                    'status' => 'olt_ok',
                    'source' => 'olt_onu_snapshot+mikrotik_read_only',
                    'checked_at' => now()->toIso8601String(),
                    'olt_online' => true,
                    'pppoe_secret' => true,
                    'pppoe_active' => true,
                ];
                $draft->forceFill(['status' => PsbDraft::STATUS_OLT_OK, 'olt_at' => $draft->olt_at ?: now(), 'meta' => $meta])->save();
                $this->info("  applied PSB #{$draft->id}: secret_ok -> olt_ok");
            }
        }

        foreach ($migrations as $migration) {
            $evidence = $this->evidenceFor(
                (int) $migration->router_id,
                (int) $migration->new_olt_id,
                (string) $migration->new_sn,
                (string) $migration->pppoe_user,
                $onus,
                $evidenceByRouter[(int) $migration->router_id] ?? null,
            );
            $this->renderMigration($migration, $evidence);

            $sibling = $this->syncedSibling($migration);
            if ($sibling) {
                $this->line("  synced sibling: #{$sibling->id} source=".($sibling->source ?: 'unknown').'; exact customer+SN match');

                if ($this->option('apply') && $this->option('resolve-migration-siblings')
                    && $migration->status === CustomerMigration::STATUS_NEEDS_REVIEW) {
                    $meta = is_array($migration->meta) ? $migration->meta : [];
                    $meta['sync_reconcile'] = [
                        'status' => 'archived_as_cancelled',
                        'reason' => 'exact synced sibling exists',
                        'sibling_id' => $sibling->id,
                        'matched_by' => ['customer_id', 'new_sn'],
                        'previous_status' => $migration->status,
                        'actor' => 'ops:reconcile-activation-status --resolve-migration-siblings',
                        'at' => now()->toIso8601String(),
                    ];
                    $migration->forceFill([
                        'status' => CustomerMigration::STATUS_CANCELLED,
                        'last_error' => "Diarsip: duplikat sibling synced #{$sibling->id}",
                        'meta' => $meta,
                    ])->save();
                    $this->info("  archived MIG #{$migration->id}: duplicate of synced #{$sibling->id}");
                }
            }
        }

        return self::SUCCESS;
    }

    private function loadOnus(Collection $drafts, Collection $migrations): Collection
    {
        if (! Schema::hasTable('olt_onus')) {
            return collect();
        }

        // Both inputs are Eloquent collections. Convert the mapped values to
        // a base collection before merging; EloquentCollection::merge()
        // expects model instances and otherwise calls getKey() on arrays.
        $keys = $drafts->map(fn (PsbDraft $row) => [(int) $row->olt_id, strtoupper((string) $row->sn)])->toBase()
            ->merge($migrations->map(fn (CustomerMigration $row) => [(int) $row->new_olt_id, strtoupper((string) $row->new_sn)])->all());
        $serials = $keys->pluck(1)->filter()->unique()->values();

        return $serials->isEmpty() ? collect() : OltOnu::query()
            ->whereIn('serial_number', $serials)
            ->get(['id', 'olt_id', 'serial_number', 'state', 'phase_state', 'state_collected_at', 'rx_collected_at']);
    }

    private function readRouterEvidence(int $routerId): ?array
    {
        $router = Router::query()->find($routerId);
        if (! $router) {
            return null;
        }

        try {
            $service = app(MikrotikService::class)->connect($router, ['timeout' => 5, 'attempts' => 1]);
            $secrets = collect($service->getPPPSecrets())->map(fn (array $row) => strtolower(trim((string) ($row['name'] ?? ''))))->filter();
            $active = collect($service->getActiveConnections(false))->map(fn (array $row) => strtolower(trim((string) ($row['name'] ?? ''))))->filter();

            return ['status' => 'verified', 'secrets' => $secrets, 'active' => $active];
        } catch (\Throwable $e) {
            $this->warn("Router #{$routerId} read-only check gagal: ".get_class($e));

            return null;
        }
    }

    private function evidenceFor(int $routerId, int $oltId, string $sn, string $username, Collection $onus, ?array $router): array
    {
        $serial = strtoupper(trim($sn));
        $user = strtolower(trim($username));
        // Same serial can be registered twice on one OLT (stale offline
        // registration next to the live one): pick deterministically.
        $candidates = $onus->filter(fn (OltOnu $row) => (int) $row->olt_id === $oltId
            && strcasecmp((string) $row->serial_number, $serial) === 0);
        $onu = app(OltOnuSerialResolver::class)->pick($candidates, $oltId);
        $oltOnline = $onu && OltOnu::normalizeOperationalState($onu->state, $onu->phase_state) === 'online';
        $secret = $router && $user !== '' && $router['secrets']->contains($user);
        $active = $router && $user !== '' && $router['active']->contains($user);

        return [
            'strong' => (bool) ($oltOnline && $secret && $active),
            'olt_online' => (bool) $oltOnline,
            'secret' => (bool) $secret,
            'active' => (bool) $active,
        ];
    }

    private function renderDraft(PsbDraft $draft, array $evidence): void
    {
        $this->line("PSB #{$draft->id} {$draft->status}: OLT=".($evidence['olt_online'] ? 'online' : 'unknown').', PPPoE='.($evidence['secret'] ? 'secret' : 'missing').'/'.($evidence['active'] ? 'active' : 'offline'));
    }

    private function renderMigration(CustomerMigration $migration, array $evidence): void
    {
        $hold = $migration->status === CustomerMigration::STATUS_NEEDS_REVIEW ? ' review_hold' : '';
        $this->line("MIG #{$migration->id} {$migration->status}{$hold}: OLT=".($evidence['olt_online'] ? 'online' : 'unknown').', PPPoE='.($evidence['secret'] ? 'secret' : 'missing').'/'.($evidence['active'] ? 'active' : 'offline'));
        if ($hold !== '') {
            $this->line('  tidak dipromosikan: needs_review wajib tetap menunggu resolusi identitas/approval.');
        }
    }

    private function syncedSibling(CustomerMigration $migration): ?CustomerMigration
    {
        $customerId = (int) $migration->customer_id;
        $sn = strtoupper(trim((string) $migration->new_sn));

        if ($customerId <= 0 || $sn === '') {
            return null;
        }

        return CustomerMigration::query()
            ->whereKeyNot($migration->id)
            ->where('status', CustomerMigration::STATUS_SYNCED)
            ->where('customer_id', $customerId)
            ->whereRaw('UPPER(TRIM(new_sn)) = ?', [$sn])
            ->latest('id')
            ->first(['id', 'source', 'customer_id', 'new_sn']);
    }
}
