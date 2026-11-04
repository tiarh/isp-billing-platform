<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\DismantleReason;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * One-off import of a warehouse dismantle recap into Pengambilan Perangkat.
 *
 * Input is a local JSON file (NOT committed; it holds customer ids). Default is
 * a dry-run that prints what each row would do; writes need --apply and
 * --actor=<admin-like user id>. Every row runs in its own transaction and is
 * idempotent through an event idempotency key, so a re-run creates nothing.
 *
 * Never sends WhatsApp/notifications, never dispatches OLT cleanup, never
 * touches routers/RADIUS, never requires photos.
 */
class DeviceRecoveryImportRecap extends Command
{
    public const IMPORT_SOURCE = 'warehouse_recap_import';

    public const IMPORT_NOTE = 'Impor rekap gudang (tanpa foto)';

    public const EVENT_SOURCE = 'recap_import';

    public const TIMEZONE = 'Asia/Jakarta';

    protected $signature = 'device-recovery:import-recap
        {file : Path to the recap JSON ({"source": "...", "rows": [...]})}
        {--apply : Write changes (default is a dry-run)}
        {--actor= : Admin-like user id recorded as the actor (required with --apply)}';

    protected $description = 'One-off: import a warehouse dismantle recap (cancel duplicate / receive existing / reused / new received); dry-run unless --apply';

    private string $recapSource = '';

    /** Guard verdict of the row being processed (null = nothing to evaluate for this row). */
    private ?GuardDecision $rowDecision = null;

    private ?GuardDecision $unevaluated = null;

    public function __construct(private DeviceRecoveryService $recovery)
    {
        parent::__construct();
    }

    /** Resolved lazily so the container binding at run time (and test stubs) apply. */
    private function termination(): LifecycleTerminationService
    {
        return app(LifecycleTerminationService::class);
    }

    /**
     * Lifecycle guard for the customer a row would terminate. receive_existing /
     * new_received: the case customer; reused: the OLD customer, only when the
     * row asks for terminate_customer, with the ONU signal waived (the owner
     * account holds the device). Null when nothing would be terminated.
     *
     * @param  array<string, mixed>  $row
     */
    private function preVerdictRow(array $row, string $action): ?GuardDecision
    {
        try {
            $customerId = (int) ($row['customer_id'] ?? 0);
            if ($customerId <= 0 || ! in_array($action, ['receive_existing', 'new_received', 'reused'], true)) {
                return null;
            }
            if ($action === 'reused' && ! filter_var($row['terminate_customer'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return null;
            }
            $customer = Customer::withTrashed()->find($customerId);
            if (! $customer || ! in_array((string) $customer->status, DeviceRecoveryService::TERMINATE_ON_RECEIVE_FROM, true)) {
                return null;
            }
            $context = $action === 'reused'
                ? GuardContext::automated('recap_import_reused', (int) ($row['reused_by_customer_id'] ?? 0) ?: null)
                : GuardContext::automated('recap_import');

            return $this->termination()->preVerdict($customer, $context);
        } catch (\Throwable $e) {
            return null; // a terminable customer without a verdict is deferred (fail-closed) when applied
        }
    }

    /** "guard=allow -> terminated" / "guard=deny (codes) -> DITUNDA" for dry-run output. */
    private function guardNote(?GuardDecision $decision): string
    {
        if ($decision === null) {
            return 'guard=tidak dievaluasi';
        }

        return $decision->allowed()
            ? 'guard=allow → terminated'
            : 'guard='.$decision->verdict.' ('.implode(', ', $decision->reasonCodes()).') → DITUNDA';
    }

    private function failClosedDecision(): GuardDecision
    {
        return $this->unevaluated ??= GuardDecision::fromSignals('terminate', [
            GuardSignal::unknown(GuardSignal::PPPOE, 'not_evaluated', 'Pagar belum dievaluasi untuk baris rekap ini.'),
        ]);
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $actor = null;
        if ($this->option('actor') !== null && $this->option('actor') !== '') {
            $actor = User::query()->find((int) $this->option('actor'));
            if (! $actor || ! $actor->isAdminLike()) {
                $this->error('--actor must be an existing admin-like user id.');

                return self::FAILURE;
            }
        }
        if ($apply && ! $actor) {
            $this->error('--apply requires --actor=<admin-like user id>.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Recap file not found: '.$path);

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! is_array($data['rows'] ?? null)) {
            $this->error('Recap file must be JSON like {"source": "...", "rows": [...]}.');

            return self::FAILURE;
        }
        $this->recapSource = trim((string) ($data['source'] ?? ''));

        $table = [];
        $counts = ['applied' => 0, 'would_apply' => 0, 'skipped' => 0, 'refused' => 0];
        $deferred = 0;
        foreach ($data['rows'] as $index => $row) {
            $row = is_array($row) ? $row : [];
            $label = (string) ($row['row'] ?? ('#'.($index + 1)));
            $action = (string) ($row['action'] ?? '?');
            // Roadmap 1.3 PR-4b: guard verdict BEFORE the row transaction (the guard may wait on Grafana).
            $this->rowDecision = $this->preVerdictRow($row, $action);
            try {
                $out = DB::transaction(fn (): array => $this->processRow($row, $label, $apply, $actor));
            } catch (InvalidArgumentException $e) {
                $out = ['result' => 'refused', 'note' => $e->getMessage(), 'warnings' => []];
            } catch (ValidationException $e) {
                $out = ['result' => 'refused', 'note' => (string) collect($e->errors())->flatten()->first(), 'warnings' => []];
            } catch (\Throwable $e) {
                report($e);
                $out = ['result' => 'refused', 'note' => 'error: '.class_basename($e).' '.$e->getMessage(), 'warnings' => []];
            }

            $counts[$out['result']]++;
            if (($out['deferred'] ?? false) === true) {
                $deferred++;
            }
            $table[] = [
                'row' => $label,
                'action' => $action,
                'case' => $row['case_id'] ?? '-',
                'customer' => $row['customer_id'] ?? '-',
                'result' => $out['result'],
                'detail' => $out['note'].($out['warnings'] !== [] ? ' | WARN: '.implode('; ', $out['warnings']) : ''),
            ];
        }

        $this->table(array_keys($table[0] ?? ['row' => 0]), $table);
        $summary = sprintf(
            'mode=%s rows=%d applied=%d would_apply=%d skipped=%d refused=%d deferred=%d',
            $apply ? 'apply' : 'dry-run',
            count($table),
            $counts['applied'],
            $counts['would_apply'],
            $counts['skipped'],
            $counts['refused'],
            $deferred,
        );
        $this->info($summary);
        Log::info('[device-recovery] import-recap '.$summary, ['source' => $this->recapSource]);

        return $apply && $counts['refused'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{result: string, note: string, warnings: list<string>}
     */
    private function processRow(array $row, string $label, bool $apply, ?User $actor): array
    {
        $action = (string) ($row['action'] ?? '');
        $key = 'recap-import:'.hash('sha256', implode('|', [
            $this->recapSource,
            $label,
            $action,
            (string) ($row['customer_id'] ?? ''),
            (string) ($row['serial'] ?? ''),
        ]));

        if (! in_array($action, ['cancel_duplicate', 'receive_existing', 'reused', 'new_received'], true)) {
            $this->refuse('aksi tidak dikenal: '.$action);
        }
        if (DeviceRecoveryEvent::query()->where('idempotency_key', $key)->exists()) {
            return $this->out('skipped', 'sudah ada (baris ini sudah pernah diimpor)');
        }

        return match ($action) {
            'cancel_duplicate' => $this->cancelDuplicate($row, $key, $apply, $actor),
            'receive_existing' => $this->receiveExisting($row, $label, $key, $apply, $actor),
            'reused' => $this->reused($row, $label, $key, $apply, $actor),
            default => $this->newReceived($row, $label, $key, $apply, $actor),
        };
    }

    /** @param  array<string, mixed>  $row */
    private function cancelDuplicate(array $row, string $key, bool $apply, ?User $actor): array
    {
        $caseId = (int) ($row['case_id'] ?? 0);
        $customerId = (int) ($row['customer_id'] ?? 0);
        $keepId = (int) ($row['keep_case_id'] ?? 0);
        $reason = trim((string) ($row['reason'] ?? ''));
        if ($caseId <= 0 || $customerId <= 0 || $keepId <= 0) {
            $this->refuse('case_id, customer_id, keep_case_id wajib');
        }
        if (mb_strlen($reason) < 5) {
            $this->refuse('reason minimal 5 karakter');
        }
        if (SensitiveDataRedactor::redact($reason) !== $reason) {
            $this->refuse('reason tidak boleh memuat password, token, secret, atau credential');
        }

        $case = DeviceRecoveryCase::query()->find($caseId);
        if (! $case) {
            $this->refuse("case #{$caseId} tidak ditemukan");
        }
        if ((int) $case->customer_id !== $customerId) {
            $this->refuse("case #{$caseId} bukan milik customer #{$customerId}");
        }
        if (! in_array($case->status, DeviceRecoveryService::PRE_START_STATUSES, true)) {
            $this->refuse("case #{$caseId} bukan open (status {$case->status})");
        }
        $keep = DeviceRecoveryCase::query()->find($keepId);
        if (! $keep || (int) $keep->customer_id !== $customerId || $keep->status !== DeviceRecoveryService::STATUS_RECEIVED) {
            $this->refuse("keep case #{$keepId} bukan 'received' untuk customer #{$customerId}");
        }

        if (! $apply) {
            return $this->out('would_apply', "batalkan duplikat case #{$caseId}, tiket ditutup (cancelled); simpan #{$keepId}");
        }

        $this->recovery->cancel($case, $actor, $reason, $key);
        $closed = $this->cancelTicket($case, $actor);

        return $this->out('applied', "case #{$caseId} dibatalkan".($closed ? ', tiket cancelled' : ''));
    }

    /** @param  array<string, mixed>  $row */
    private function receiveExisting(array $row, string $label, string $key, bool $apply, ?User $actor): array
    {
        $caseId = (int) ($row['case_id'] ?? 0);
        $customerId = (int) ($row['customer_id'] ?? 0);
        $serial = $this->recovery->normalizeSerial((string) ($row['serial'] ?? ''));
        if ($caseId <= 0 || $customerId <= 0 || $serial === null) {
            $this->refuse('case_id, customer_id, serial wajib');
        }
        $at = $this->at($row);

        $case = DeviceRecoveryCase::query()->when($apply, fn ($q) => $q->lockForUpdate())->find($caseId);
        if (! $case) {
            $this->refuse("case #{$caseId} tidak ditemukan");
        }
        if ((int) $case->customer_id !== $customerId) {
            $this->refuse("case #{$caseId} bukan milik customer #{$customerId}");
        }
        if (! in_array($case->status, DeviceRecoveryService::PRE_START_STATUSES, true)) {
            $this->refuse("case #{$caseId} bukan open (status {$case->status})");
        }
        $items = $case->items()->when($apply, fn ($q) => $q->lockForUpdate())->get();
        if ($items->count() !== 1 || $items->first()->status !== DeviceRecoveryService::ITEM_PENDING) {
            $this->refuse("case #{$caseId} harus punya tepat 1 perangkat berstatus pending");
        }
        /** @var DeviceRecoveryItem $item */
        $item = $items->first();
        $warnings = [];
        $filledFromCustomer = false;
        if (trim((string) $item->expected_serial_number) === '') {
            // Case was created while the customer had no onu_serial: accept when
            // the customer's current serial equals the recap serial.
            $current = $this->recovery->normalizeSerial(Customer::withTrashed()->find($customerId)?->onu_serial);
            if ($current === null || ! $this->recovery->serialsMatch($current, $serial)) {
                $this->refuse("SN rekap {$serial} tidak bisa dicocokkan: SN expected case #{$caseId} kosong dan onu_serial customer ".($current === null ? 'kosong' : 'berbeda').' (case harus punya expected SN atau customer harus punya onu_serial yang sama dengan rekap)');
            }
            $filledFromCustomer = true;
            $warnings[] = "SN expected case #{$caseId} kosong; diisi dari onu_serial customer ({$serial}) yang cocok dengan rekap";
        } elseif (! $this->recovery->serialsMatch($item->expected_serial_number, $serial)) {
            $this->refuse("SN rekap {$serial} tidak cocok dengan SN expected case #{$caseId} (".($item->expected_serial_number ?: '-').')');
        }
        $this->assertSerialFree($serial, $case->id);

        if (! $apply) {
            $customerNote = $this->rowDecision === null
                ? 'customer terminated bila active/isolated/inactive'
                : 'customer: '.$this->guardNote($this->rowDecision);

            return $this->out('would_apply', "case #{$caseId} -> received ({$at->toDateString()} UTC), {$customerNote}, tiket ditutup", $warnings, $this->rowDecision !== null && ! $this->rowDecision->allowed());
        }

        $itemMeta = is_array($item->meta) ? $item->meta : [];
        $itemMeta['recap_import'] = true;
        if ($filledFromCustomer) {
            $itemMeta['expected_filled_from_customer'] = true;
            $item->forceFill(['expected_serial_number' => $serial, 'expected_source' => 'customer']);
        }
        $item->forceFill([
            'captured_serial_number' => $serial,
            'status' => DeviceRecoveryService::ITEM_RECEIVED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'captured_by' => $actor->id,
            'captured_at' => $at,
            'verified_by' => $actor->id,
            'verified_at' => $at,
            'notes' => self::IMPORT_NOTE,
            'meta' => $itemMeta,
        ])->save();

        $from = (string) $case->status;
        $case->forceFill([
            'status' => DeviceRecoveryService::STATUS_RECEIVED,
            'started_at' => $case->started_at ?: $at,
            'received_at' => $at,
            'updated_by' => $actor->id,
            'version' => (int) $case->version + 1,
            'meta' => $this->caseMeta($case->meta, $row, $label) + ($filledFromCustomer ? ['expected_filled_from_customer' => true] : []),
        ])->save();

        $this->stampTicketResolved($case, $at);
        $effects = $this->recovery->applyRecapReceiveEffects($case, $actor, $this->rowDecision);
        $this->event($case, $actor, 'warehouse_received', $from, DeviceRecoveryService::STATUS_RECEIVED, self::IMPORT_NOTE, $key, [
            ...$this->recapMeta($row, $label),
            'item_id' => $item->id,
            ...($filledFromCustomer ? ['expected_filled_from_customer' => true] : []),
            ...$effects,
        ]);

        return $this->out('applied', "case #{$caseId} received".(($effects['customer_terminated'] ?? false) ? ', customer terminated' : '').(($effects['customer_termination_deferred'] ?? false) ? ', terminate customer DITUNDA (pagar)' : '').(($effects['ticket_auto_closed'] ?? false) ? ', tiket closed' : ''), $warnings, ($effects['customer_termination_deferred'] ?? false) === true);
    }

    /** @param  array<string, mixed>  $row */
    private function reused(array $row, string $label, string $key, bool $apply, ?User $actor): array
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        $ownerId = (int) ($row['reused_by_customer_id'] ?? 0);
        $serial = $this->recovery->normalizeSerial((string) ($row['serial'] ?? ''));
        if ($customerId <= 0 || $ownerId <= 0 || $serial === null) {
            $this->refuse('customer_id, reused_by_customer_id, serial wajib');
        }
        if ($customerId === $ownerId) {
            $this->refuse('reused_by_customer_id sama dengan customer_id');
        }
        $at = $this->at($row);
        $customer = $this->customer($customerId);
        if ($this->customerHasSerialCase($customerId, $serial)) {
            return $this->out('skipped', 'sudah ada (customer sudah punya case dengan SN ini)');
        }
        $this->assertNoOpenCase($customerId);

        $owner = Customer::withTrashed()->find($ownerId);
        if (! $owner) {
            $this->refuse("customer pemakai #{$ownerId} tidak ditemukan");
        }
        if ($owner->trashed() || in_array((string) $owner->status, DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES, true)) {
            $this->refuse("customer pemakai #{$ownerId} sudah tidak live (".($owner->trashed() ? 'deleted' : $owner->status).')');
        }
        if (! $this->recovery->serialsMatch($owner->onu_serial, $serial)) {
            $this->refuse("onu_serial customer pemakai #{$ownerId} tidak lagi cocok dengan SN {$serial}");
        }

        $stored = $this->recovery->normalizeSerial($customer->onu_serial);
        $warnings = [];
        if ($stored !== null && ! $this->recovery->serialsMatch($stored, $serial)) {
            $warnings[] = "SN tersimpan customer ({$stored}) berbeda dengan rekap ({$serial}); expected diisi dari rekap";
        }

        if (! $apply) {
            $willTerminate = filter_var($row['terminate_customer'] ?? false, FILTER_VALIDATE_BOOLEAN)
                && in_array((string) $customer->status, DeviceRecoveryService::TERMINATE_ON_RECEIVE_FROM, true);

            $note = ! $willTerminate
                ? 'status customer lama tidak diubah'
                : ($this->rowDecision !== null && ! $this->rowDecision->allowed()
                    ? 'customer lama DITUNDA, '.$this->guardNote($this->rowDecision)
                    : 'customer lama diterminate'.($this->rowDecision === null ? '' : ', '.$this->guardNote($this->rowDecision)));

            return $this->out('would_apply', "buat tiket dismantle (closed) + case reused (dipakai #{$ownerId}); ".$note, $warnings, $willTerminate && $this->rowDecision !== null && ! $this->rowDecision->allowed());
        }

        $ownerLabel = $owner->code ?: $owner->name;
        $reason = "Sudah digunakan pelanggan lain: {$ownerLabel} (#{$owner->id})";
        $ticket = $this->createTicket($customer, $actor, $at, $label, $row);
        $meta = $this->caseMeta(null, $row, $label);
        if ($warnings !== []) {
            $meta['stored_onu_serial'] = $stored;
        }
        $meta['reused'] = [
            'customer_id' => (int) $owner->id,
            'customer_code' => $owner->code,
            'serial' => $serial,
            'source' => self::IMPORT_SOURCE,
            'from_status' => null,
            'reuse_after_pickup' => true,
            'candidate_customer_ids' => [(int) $owner->id],
            'at' => $at->toIso8601String(),
        ];
        $case = $this->createCase($customer, $ticket, DeviceRecoveryService::STATUS_REUSED, $actor, $at, $meta, $reason);
        $item = $this->createItem($case, $serial, $serial, 'recap', $actor, $at, ['recap_import' => true, 'reused' => true]);

        $this->event($case, $actor, 'case_created', null, DeviceRecoveryService::STATUS_REUSED, self::IMPORT_NOTE, $key, [
            ...$this->recapMeta($row, $label),
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'item_id' => $item->id,
        ]);
        $terminated = [];
        if (filter_var($row['terminate_customer'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $terminated = $this->terminateOldCustomer($customer, $case, $actor, $owner);
        }
        $this->event($case, $actor, 'reused_closed', null, DeviceRecoveryService::STATUS_REUSED, $reason, $key.':reused', $meta['reused'] + $terminated);
        $deferred = ($terminated['customer_termination_deferred'] ?? false) === true;

        return $this->out('applied', "case #{$case->id} reused, tiket #{$ticket->id} closed".(($terminated['customer_terminated'] ?? false) ? ', customer lama terminated' : '').($deferred ? ', terminate customer lama DITUNDA (pagar)' : ''), $warnings, $deferred);
    }

    /** @param  array<string, mixed>  $row */
    private function newReceived(array $row, string $label, string $key, bool $apply, ?User $actor): array
    {
        $customerId = (int) ($row['customer_id'] ?? 0);
        $serial = $this->recovery->normalizeSerial((string) ($row['serial'] ?? ''));
        if ($customerId <= 0) {
            $this->refuse('customer_id wajib');
        }
        $deviceType = strtolower(trim((string) ($row['device_type'] ?? 'onu'))) ?: 'onu';
        if (! in_array($deviceType, ['onu', 'ont', 'router', 'modem', 'adapter', 'other'], true)) {
            $this->refuse('device_type tidak valid: '.$deviceType);
        }
        $at = $this->at($row);
        $customer = $this->customer($customerId);
        if ($serial === null) {
            if (DeviceRecoveryCase::query()->where('customer_id', $customerId)->whereIn('status', DeviceRecoveryService::FINAL_STATUSES)->exists()) {
                return $this->out('skipped', 'sudah ada (customer sudah punya case final)');
            }
            $this->assertNoOpenCase($customerId);
        } else {
            if ($this->customerHasSerialCase($customerId, $serial)) {
                return $this->out('skipped', 'sudah ada (customer sudah punya case dengan SN ini)');
            }
            $this->assertNoOpenCase($customerId);
            $this->assertSerialFree($serial, 0);
        }

        $stored = $this->recovery->normalizeSerial($customer->onu_serial);
        $warnings = [];
        if ($serial === null) {
            $warnings[] = 'SN tidak tercatat di rekap; item received dengan SN kosong';
        } elseif ($stored !== null && ! $this->recovery->serialsMatch($stored, $serial)) {
            $warnings[] = "SN tersimpan customer ({$stored}) berbeda dengan rekap ({$serial}); expected diisi dari rekap";
        }
        if ($serial !== null && ($holder = $this->liveSerialHolder($serial, $customerId))) {
            $warnings[] = "SN rekap sekarang dipakai customer live #{$holder->id}; mungkin seharusnya baris 'reused'";
        }
        $storedForExpected = in_array($deviceType, ['onu', 'ont'], true) ? $stored : null;

        if (! $apply) {
            $guardNote = $this->rowDecision === null ? '' : '; '.$this->guardNote($this->rowDecision);

            return $this->out('would_apply', "buat tiket dismantle (closed) + case received; customer {$customer->status}".($customer->trashed() ? ' (deleted)' : '').$guardNote, $warnings, $this->rowDecision !== null && ! $this->rowDecision->allowed());
        }

        $ticket = $this->createTicket($customer, $actor, $at, $label, $row);
        $meta = $this->caseMeta(null, $row, $label);
        if ($serial !== null && $warnings !== []) {
            $meta['stored_onu_serial'] = $stored;
        }
        $case = $this->createCase($customer, $ticket, DeviceRecoveryService::STATUS_RECEIVED, $actor, $at, $meta, null);
        $expected = $serial ?? $storedForExpected;
        $itemMeta = ['recap_import' => true] + ($serial === null ? ['serial_not_recorded' => true] : []);
        $item = $this->createItem(
            $case,
            $expected,
            $serial,
            $expected === null ? null : ($serial === null || ($stored !== null && $warnings === []) ? 'customer' : 'recap'),
            $actor,
            $at,
            $itemMeta,
            $deviceType,
        );

        $this->event($case, $actor, 'case_created', null, DeviceRecoveryService::STATUS_RECEIVED, self::IMPORT_NOTE, $key, [
            ...$this->recapMeta($row, $label),
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'item_id' => $item->id,
        ]);
        $effects = $this->recovery->applyRecapReceiveEffects($case, $actor, $this->rowDecision);
        $this->event($case, $actor, 'warehouse_received', null, DeviceRecoveryService::STATUS_RECEIVED, self::IMPORT_NOTE, $key.':received', [
            ...$this->recapMeta($row, $label),
            'item_id' => $item->id,
            ...$effects,
        ]);

        return $this->out('applied', "case #{$case->id} received, tiket #{$ticket->id} closed".(($effects['customer_terminated'] ?? false) ? ', customer terminated' : '').(($effects['customer_termination_deferred'] ?? false) ? ', terminate customer DITUNDA (pagar)' : ''), $warnings, ($effects['customer_termination_deferred'] ?? false) === true);
    }

    /**
     * Optional (row.terminate_customer): active/isolated/inactive -> terminated.
     * Status column + activity log only; no router/RADIUS/WA.
     *
     * @return array<string, mixed>
     */
    private function terminateOldCustomer(Customer $customer, DeviceRecoveryCase $case, User $actor, Customer $owner): array
    {
        $locked = Customer::withTrashed()->lockForUpdate()->find($customer->id);
        if (! $locked || ! in_array((string) $locked->status, DeviceRecoveryService::TERMINATE_ON_RECEIVE_FROM, true)) {
            return [];
        }
        $from = (string) $locked->status;
        $reason = 'auto: impor rekap gudang (case #'.$case->id.') -> pelanggan terminated';

        // Roadmap 1.3 PR-4b: only when the guard allows; otherwise a pending
        // termination (billing hold) the sweeper finalizes later. No verdict
        // (should not happen) = unknown = deferred.
        $decision = $this->rowDecision ?? $this->failClosedDecision();
        // A10: terminateOrDefer only terminates on an allow verdict (also wrapped inside the service).
        $outcome = LifecycleBackstop::allow([(int) $locked->id], 'recap_import_reused', fn () => $this->termination()->terminateOrDefer($locked, $decision, 'recap_reused', [
            'recovery_case_id' => $case->id,
            'ticket_id' => $case->ticket_id,
            'takeover_customer_id' => $owner->id,
        ]));
        if ($outcome['result'] === LifecycleTerminationService::RESULT_DEFERRED) {
            return [
                'customer_termination_deferred' => true,
                'customer_id' => $locked->id,
                'pending_termination_id' => $outcome['pending_termination_id'],
                'guard_verdict' => $outcome['guard_verdict'],
                'guard_reasons' => $outcome['guard_reasons'],
            ];
        }
        activity()
            ->causedBy($actor)
            ->performedOn($locked)
            ->event('terminated_recap_import')
            ->withProperties(['reason' => $reason, 'from' => $from, 'to' => 'terminated', 'recovery_case_id' => $case->id, 'ticket_id' => $case->ticket_id])
            ->log($reason);

        return ['customer_terminated' => true, 'customer_id' => $locked->id, 'customer_from_status' => $from];
    }

    private function customer(int $id): Customer
    {
        $customer = Customer::withTrashed()->find($id);
        if (! $customer) {
            $this->refuse("customer #{$id} tidak ditemukan");
        }

        return $customer;
    }

    /** "Already imported" check: only FINAL cases count (an open case still needs receive_existing). */
    private function customerHasSerialCase(int $customerId, string $serial): bool
    {
        return DeviceRecoveryItem::query()
            ->whereHas('recoveryCase', fn ($q) => $q
                ->where('customer_id', $customerId)
                ->whereIn('status', DeviceRecoveryService::FINAL_STATUSES))
            ->get(['captured_serial_number', 'expected_serial_number'])
            ->contains(fn (DeviceRecoveryItem $item): bool => $this->recovery->serialsMatch($item->captured_serial_number, $serial)
                || $this->recovery->serialsMatch($item->expected_serial_number, $serial));
    }

    private function assertNoOpenCase(int $customerId): void
    {
        $open = DeviceRecoveryCase::query()
            ->where('customer_id', $customerId)
            ->whereNotIn('status', DeviceRecoveryService::FINAL_STATUSES)
            ->orderBy('id')
            ->first();
        if ($open) {
            $this->refuse("customer #{$customerId} masih punya case terbuka #{$open->id} (status {$open->status}); gunakan receive_existing atau cancel_duplicate");
        }
    }

    /** Live customer (other than $exceptId) whose onu_serial equals the serial, if any. */
    private function liveSerialHolder(string $serial, int $exceptId): ?Customer
    {
        return Customer::query()
            ->whereKeyNot($exceptId)
            ->whereNotIn('status', DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES)
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(onu_serial, ''), '-', ''), ' ', ''), ':', ''), '.', '')) = ?", [$serial])
            ->first(['id', 'code']);
    }

    private function assertSerialFree(string $serial, int $exceptCaseId): void
    {
        $busy = DeviceRecoveryItem::query()
            ->where('captured_serial_number', $serial)
            ->whereHas('recoveryCase', fn ($q) => $q
                ->whereKeyNot($exceptCaseId)
                ->whereNotIn('status', [
                    DeviceRecoveryService::STATUS_RECEIVED,
                    DeviceRecoveryService::STATUS_CANCELLED,
                    DeviceRecoveryService::STATUS_REUSED,
                ]))
            ->exists();
        if ($busy) {
            $this->refuse("SN {$serial} tercatat pada case lain yang masih aktif");
        }
    }

    /** @param  array<string, mixed>  $row */
    private function createTicket(Customer $customer, User $actor, CarbonImmutable $at, string $label, array $row = []): SupportTicket
    {
        $ownerNote = trim((string) ($row['note'] ?? ''));
        $subject = 'Dismantle (impor rekap gudang)';
        $note = mb_substr(self::IMPORT_NOTE.' baris '.$label, 0, DismantleReason::NOTE_MAX);
        // Same code format as SupportTicket::creating, dated by the recap (WIB) day.
        $day = $at->setTimezone(self::TIMEZONE)->format('Ymd');
        $ticket = new SupportTicket([
            'code' => 'TCK-'.$day.'-'.strtoupper(Str::random(5)),
            'report_id' => 'RPT-'.$day.'-'.strtoupper(Str::random(4)),
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'gangguan' => $subject,
            'subject' => $subject,
            'keterangan' => self::IMPORT_NOTE,
            'type' => DeviceRecoveryService::DISMANTLE_TICKET_TYPE,
            'priority' => 'normal',
            'status' => 'closed',
            'created_by' => $actor->id,
            'resolved_at' => $at,
            'due_at' => $at,
            'dismantle_reason' => DismantleReason::OTHER,
            'dismantle_reason_note' => $note,
            'meta' => ['recap_import' => true],
        ]);
        $ticket->created_at = $at;
        $ticket->updated_at = $at;
        $ticket->save();

        $activity = new TicketActivity([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => 'created',
            'new_value' => ['recap_import' => true, 'status' => 'closed'],
            'description' => mb_substr('Tiket dibuat dari '.self::IMPORT_NOTE.' (baris '.$label.'); langsung closed.'.($ownerNote !== '' ? ' Catatan: '.$ownerNote : ''), 0, 255),
        ]);
        $activity->created_at = $at;
        $activity->updated_at = $at;
        $activity->save();

        return $ticket;
    }

    /** @param  array<string, mixed>  $meta */
    private function createCase(Customer $customer, SupportTicket $ticket, string $status, User $actor, CarbonImmutable $at, array $meta, ?string $exceptionReason): DeviceRecoveryCase
    {
        $case = new DeviceRecoveryCase([
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'status' => $status,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'started_at' => $at,
            'received_at' => $status === DeviceRecoveryService::STATUS_RECEIVED ? $at : null,
            'exception_reason' => $exceptionReason,
            'version' => 1,
            'meta' => $meta,
        ]);
        $case->created_at = $at;
        $case->updated_at = $at;
        $case->save();

        return $case;
    }

    private function createItem(DeviceRecoveryCase $case, ?string $expected, ?string $captured, ?string $expectedSource, User $actor, CarbonImmutable $at, array $meta, string $deviceType = 'onu'): DeviceRecoveryItem
    {
        return $case->items()->create([
            'device_type' => $deviceType,
            'expected_serial_number' => $expected,
            'expected_source' => $expectedSource,
            'captured_serial_number' => $captured,
            'status' => DeviceRecoveryService::ITEM_RECEIVED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'captured_by' => $actor->id,
            'captured_at' => $at,
            'verified_by' => $actor->id,
            'verified_at' => $at,
            'notes' => self::IMPORT_NOTE,
            'meta' => $meta,
        ]);
    }

    /** Ticket of a cancelled duplicate case: status cancelled + activity. No WA. */
    private function cancelTicket(DeviceRecoveryCase $case, User $actor): bool
    {
        $ticket = $case->ticket_id ? SupportTicket::query()->lockForUpdate()->find($case->ticket_id) : null;
        if (! $ticket || in_array($ticket->status, ['closed', 'cancelled'], true)) {
            return false;
        }
        $from = (string) $ticket->status;
        $ticket->forceFill(['status' => 'cancelled'])->save();
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => 'status_changed',
            'old_value' => ['status' => $from],
            'new_value' => ['status' => 'cancelled', 'auto' => true, 'recovery_case_id' => $case->id],
            'description' => "Status diubah: {$from} -> cancelled (duplikat; impor rekap gudang)",
        ]);

        return true;
    }

    /** The ticket closes at the recap date, not at import time. */
    private function stampTicketResolved(DeviceRecoveryCase $case, CarbonImmutable $at): void
    {
        if (! $case->ticket_id) {
            return;
        }
        SupportTicket::query()
            ->whereKey($case->ticket_id)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => $at]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function recapMeta(array $row, string $label): array
    {
        $meta = [
            'source' => self::IMPORT_SOURCE,
            'recap_source' => $this->recapSource,
            'recap_row' => $label,
            'movement_no' => $row['sm'] ?? null,
            'warehouse' => $row['warehouse'] ?? null,
            'pic' => $row['pic'] ?? null,
            'import_note' => self::IMPORT_NOTE,
        ];
        foreach (['device_label' => 'recap_device_label', 'note' => 'owner_note'] as $from => $to) {
            $value = trim((string) ($row[$from] ?? ''));
            if ($value !== '') {
                $meta[$to] = $value;
            }
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function caseMeta(?array $existing, array $row, string $label): array
    {
        $meta = $existing ?? [];
        $meta['previous_source'] = $meta['source'] ?? null;
        if ($meta['previous_source'] === null) {
            unset($meta['previous_source']);
        }

        return [...$meta, ...$this->recapMeta($row, $label)];
    }

    /** @param  array<string, mixed>  $metadata */
    private function event(DeviceRecoveryCase $case, User $actor, string $type, ?string $from, ?string $to, ?string $reason, string $key, array $metadata): void
    {
        DeviceRecoveryEvent::create([
            'recovery_case_id' => $case->id,
            'item_id' => $metadata['item_id'] ?? null,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'source' => self::EVENT_SOURCE,
            'idempotency_key' => $key,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Recap date = WIB calendar date at 12:00, stored as UTC (app timezone).
     *
     * @param  array<string, mixed>  $row
     */
    private function at(array $row): CarbonImmutable
    {
        $date = (string) ($row['date'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $this->refuse('date harus YYYY-MM-DD');
        }

        return CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' 12:00', self::TIMEZONE)
            ->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /** @param  list<string>  $warnings */
    private function out(string $result, string $note, array $warnings = [], bool $deferred = false): array
    {
        return ['result' => $result, 'note' => $note, 'warnings' => $warnings, 'deferred' => $deferred];
    }

    private function refuse(string $message): never
    {
        throw new InvalidArgumentException($message);
    }
}
