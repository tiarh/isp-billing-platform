<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigrationImportRow;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\Lifecycle\LifecycleBackstop;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ExcelCustomerConflictResolver
{
    private const BATCH = 'excel-master-conflicts:2026-07-24';

    /**
     * @return list<array<string, mixed>>
     */
    public function preview(): array
    {
        return array_values(array_filter(array_map(
            fn (array $plan) => $this->previewPlan($plan),
            $this->plans()
        )));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function apply(int $actorId, string $reason): array
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Reason wajib diisi.');
        }
        $actor = User::query()->findOrFail($actorId);

        return DB::transaction(function () use ($actor, $reason): array {
            $results = [];
            foreach ($this->plans() as $plan) {
                $results[] = $this->applyPlan($plan, $actor, trim($reason));
            }

            return $results;
        }, 3);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(): array
    {
        return [
            ['correct_row' => 35, 'discard_rows' => [31], 'wrong_codes' => [], 'merge_related' => false],
            ['correct_row' => 1487, 'discard_rows' => [122], 'wrong_codes' => [], 'merge_related' => false],
            ['correct_row' => 125, 'discard_rows' => [131], 'wrong_codes' => [], 'merge_related' => false],
            ['correct_row' => 192, 'discard_rows' => [145], 'wrong_codes' => ['MGWN087'], 'merge_related' => false],
            ['correct_row' => 311, 'discard_rows' => [182], 'wrong_codes' => ['KRNPT112'], 'merge_related' => true],
            ['correct_row' => 1186, 'discard_rows' => [316], 'wrong_codes' => ['KRNPT011'], 'merge_related' => true],
            ['correct_row' => 184, 'discard_rows' => [], 'wrong_codes' => ['KRNPT111'], 'merge_related' => true],
            ['correct_row' => 185, 'discard_rows' => [], 'wrong_codes' => ['KRN592'], 'merge_related' => true],
            ['correct_row' => 701, 'discard_rows' => [], 'wrong_codes' => ['KRN521'], 'merge_related' => false],
            ['correct_row' => 876, 'discard_rows' => [], 'wrong_codes' => ['KRN486'], 'merge_related' => false],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function previewPlan(array $plan): ?array
    {
        $row = $this->row((int) $plan['correct_row']);
        if (! $row) {
            return null;
        }
        $pppoe = strtoupper(trim((string) $row->pppoe_user));
        $target = Customer::withTrashed()->whereRaw('UPPER(pppoe_user) = ?', [$pppoe])->first();
        $wrong = Customer::withTrashed()
            ->whereIn('code', $plan['wrong_codes'])
            ->when($target, fn ($query) => $query->where('id', '!=', $target->id))
            ->get();

        return [
            'correct_row' => $row->row_number,
            'code' => data_get($row->raw_data, 'code'),
            'name' => $row->full_name,
            'pppoe_user' => $row->pppoe_user,
            'target_customer_id' => $target?->id,
            'target_action' => $target ? 'update' : 'create',
            'discard_rows' => $plan['discard_rows'],
            'wrong_customer_ids' => $wrong->pluck('id')->all(),
            'wrong_customer_codes' => $wrong->pluck('code')->all(),
            'merge_related' => (bool) $plan['merge_related'],
            'device_write' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function applyPlan(array $plan, User $actor, string $reason): array
    {
        $row = CustomerMigrationImportRow::query()
            ->where('batch_key', self::BATCH)
            ->where('row_number', $plan['correct_row'])
            ->lockForUpdate()
            ->firstOrFail();

        if ($row->status === 'resolved') {
            return [
                'correct_row' => $row->row_number,
                'status' => 'already_resolved',
                'target_customer_id' => $row->applied_target_id,
                'device_write' => false,
            ];
        }

        $pppoe = strtoupper(trim((string) $row->pppoe_user));
        $target = Customer::withTrashed()
            ->whereRaw('UPPER(pppoe_user) = ?', [$pppoe])
            ->lockForUpdate()
            ->first();
        $wrongCustomers = Customer::withTrashed()
            ->whereIn('code', $plan['wrong_codes'])
            ->when($target, fn ($query) => $query->where('id', '!=', $target->id))
            ->lockForUpdate()
            ->get();

        $transferAudit = [];
        foreach ($wrongCustomers as $wrong) {
            if ((bool) $wrong->is_online) {
                throw new RuntimeException("Customer salah #{$wrong->id} masih online; apply diblok.");
            }
            $transferAudit[$wrong->id] = (bool) $plan['merge_related'] && $target
                ? $this->transferSafeRelations($wrong, $target)
                : ['moved' => [], 'skipped_invoice_ids' => []];

            $oldCode = (string) $wrong->code;
            // A10: own is_online check above (offline only) = this resolver's guard.
            LifecycleBackstop::allow([(int) $wrong->id], 'excel_conflict_resolver', function () use ($wrong, $oldCode): void {
                $wrong->forceFill([
                    'code' => substr("DUP-{$wrong->id}-{$oldCode}", 0, 100),
                    'status' => 'inactive',
                ])->save();
                $wrong->delete();
            });

            activity('excel_conflict_resolution')
                ->performedOn($wrong)
                ->causedBy($actor)
                ->withProperties([
                    'action' => 'soft_delete_wrong_customer',
                    'old_code' => $oldCode,
                    'correct_excel_row' => $row->row_number,
                    'correct_pppoe' => $row->pppoe_user,
                    'relation_transfer' => $transferAudit[$wrong->id],
                    'reason' => $reason,
                    'device_write' => false,
                ])
                ->log('Archived wrong Excel conflict customer');
        }

        if (! $target) {
            $target = new Customer;
        } elseif ($target->trashed()) {
            $target->restore();
        }
        $before = $target->exists ? $target->only(['id', 'code', 'name', 'pppoe_user', 'status']) : null;
        $this->fillFromStaging($target, $row);
        $target->save();

        $resolution = [
            'status' => 'resolved_correct_data',
            'target_customer_id' => $target->id,
            'wrong_customer_ids' => $wrongCustomers->pluck('id')->all(),
            'discarded_rows' => $plan['discard_rows'],
            'relation_transfer' => $transferAudit,
            'before' => $before,
            'after' => $target->only(['id', 'code', 'name', 'pppoe_user', 'status']),
            'reason' => $reason,
            'actor_id' => $actor->id,
            'resolved_at' => now()->toISOString(),
            'device_write' => false,
        ];

        $this->markResolved($row, $resolution, $actor);
        foreach ($plan['discard_rows'] as $discardRow) {
            $discard = CustomerMigrationImportRow::query()
                ->where('batch_key', self::BATCH)
                ->where('row_number', $discardRow)
                ->lockForUpdate()
                ->firstOrFail();
            $this->markDiscarded($discard, $resolution, $actor);
        }

        activity('excel_conflict_resolution')
            ->performedOn($target)
            ->causedBy($actor)
            ->withProperties($resolution)
            ->log('Applied correct Excel conflict customer data');

        return [
            'correct_row' => $row->row_number,
            'status' => 'resolved',
            'target_customer_id' => $target->id,
            'wrong_customer_ids' => $wrongCustomers->pluck('id')->all(),
            'discarded_rows' => $plan['discard_rows'],
            'device_write' => false,
        ];
    }

    private function row(int $rowNumber): ?CustomerMigrationImportRow
    {
        return CustomerMigrationImportRow::query()
            ->where('batch_key', self::BATCH)
            ->where('row_number', $rowNumber)
            ->first();
    }

    private function fillFromStaging(Customer $customer, CustomerMigrationImportRow $row): void
    {
        $raw = is_array($row->raw_data) ? $row->raw_data : [];
        $code = trim((string) ($raw['code'] ?? ''));
        $package = Package::query()->where('name', (string) ($raw['package_name'] ?? ''))->first();
        if (! $package && (! $customer->exists || ! $customer->package_id)) {
            throw new RuntimeException("Package Excel row {$row->row_number} tidak ditemukan.");
        }
        $router = $this->router((string) ($raw['router_name'] ?? ''));
        if (! $router) {
            throw new RuntimeException("Router Excel row {$row->row_number} tidak ditemukan.");
        }
        $area = $this->area((string) $row->area);
        if (! $area) {
            throw new RuntimeException("Area Excel row {$row->row_number} tidak ditemukan.");
        }

        $photo = trim((string) ($raw['ktp_photo_url'] ?? ''));
        $status = $customer->exists
            ? $customer->status
            : ($raw['status'] ?? 'active');
        $payload = [
            'code' => $code,
            'legacy_id' => trim((string) ($raw['legacy_id'] ?? '')) ?: null,
            'name' => $row->full_name,
            'phone' => $this->nullablePhone($raw['phone'] ?? null),
            'address' => trim((string) ($raw['address'] ?? '')) ?: '-',
            'area_id' => $area->id,
            'package_id' => $package?->id ?? $customer->package_id,
            'router_id' => $router->id,
            'pppoe_user' => $row->pppoe_user,
            'status' => $status,
            'join_date' => $this->date($raw['join_date'] ?? null),
            'due_day' => (int) ($raw['due_day'] ?? 20) ?: 20,
            'geo_lat' => $raw['geo_lat'] ?? null,
            'geo_long' => $raw['geo_long'] ?? null,
        ];
        if ($photo !== '' && ! str_ends_with($photo, '/')) {
            $payload['ktp_photo_url'] = $photo;
        }
        if ($row->sn_baru || $row->sn_lama) {
            $payload['onu_serial'] = $row->sn_baru ?: $row->sn_lama;
        }

        $customer->fill($payload);
    }

    /**
     * @return array{moved: array<string, int>, skipped_invoice_ids: list<int>}
     */
    private function transferSafeRelations(Customer $wrong, Customer $target): array
    {
        $moved = [];
        $skippedInvoices = [];
        if (Schema::hasTable('invoices')) {
            foreach (DB::table('invoices')->where('customer_id', $wrong->id)->get(['id', 'period']) as $invoice) {
                $periodConflict = DB::table('invoices')
                    ->where('customer_id', $target->id)
                    ->where('period', $invoice->period)
                    ->exists();
                if ($periodConflict) {
                    $skippedInvoices[] = (int) $invoice->id;

                    continue;
                }
                DB::table('invoices')->where('id', $invoice->id)->update(['customer_id' => $target->id]);
                $moved['invoices'] = ($moved['invoices'] ?? 0) + 1;
            }
        }

        foreach ([
            'support_tickets' => 'customer_id',
            'psb_drafts' => 'customer_id',
            'customer_migrations' => 'customer_id',
            'genieacs_devices' => 'customer_id',
            'billing_wa_logs' => 'customer_id',
            'router_staged_customers' => 'matched_customer_id',
        ] as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $count = DB::table($table)->where($column, $wrong->id)->update([$column => $target->id]);
            if ($count > 0) {
                $moved[$table] = $count;
            }
        }

        return ['moved' => $moved, 'skipped_invoice_ids' => $skippedInvoices];
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function markResolved(CustomerMigrationImportRow $row, array $resolution, User $actor): void
    {
        $meta = is_array($row->meta) ? $row->meta : [];
        $meta['conflict_resolution'] = $resolution;
        $row->forceFill([
            'classification' => 'keep_existing',
            'classification_source' => 'manual_override',
            'status' => 'resolved',
            'override_reason' => $resolution['reason'],
            'corrected_by' => $actor->id,
            'corrected_at' => now(),
            'applied_target_type' => Customer::class,
            'applied_target_id' => $resolution['target_customer_id'],
            'meta' => $meta,
            'applied_error' => null,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $resolution
     */
    private function markDiscarded(CustomerMigrationImportRow $row, array $resolution, User $actor): void
    {
        $meta = is_array($row->meta) ? $row->meta : [];
        $meta['conflict_resolution'] = array_merge($resolution, ['status' => 'discarded_wrong_source']);
        $row->forceFill([
            'classification' => 'discarded_wrong_source',
            'classification_source' => 'manual_override',
            'status' => 'discarded',
            'override_reason' => $resolution['reason'],
            'corrected_by' => $actor->id,
            'corrected_at' => now(),
            'meta' => $meta,
            'applied_error' => null,
        ])->save();
    }

    private function router(string $name): ?Router
    {
        $key = preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?? '';
        $canonical = match ($key) {
            'skynetraolt' => 'SKYNET-RA-OLT',
            'skynetkrian' => 'Krian',
            default => $name,
        };

        return Router::query()->whereRaw('LOWER(name) = ?', [strtolower($canonical)])->first();
    }

    private function area(string $name): ?Area
    {
        return Area::query()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->orWhereRaw('LOWER(name) = ?', ['skynet-'.strtolower($name)])
            ->orderByRaw('LOWER(name) = ? DESC', [strtolower($name)])
            ->first();
    }

    private function nullablePhone(mixed $phone): ?string
    {
        $phone = trim((string) $phone);

        return $phone === '' || $phone === '0' ? null : $phone;
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return Carbon::parse((string) $value)->timezone('Asia/Jakarta')->toDateString();
    }
}
