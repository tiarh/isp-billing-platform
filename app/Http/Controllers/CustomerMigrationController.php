<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Models\OltOnu;
use App\Services\CustomerMigrationImportPreviewService;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use App\Services\Migration\MigrationManualCompleteService;
use App\Services\SafeRemoteSnapshotDownloader;
use App\Services\WaChatIntelligenceService;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerMigrationController extends Controller
{
    public function index(
        Request $request,
        WaChatIntelligenceService $waChat,
        GuidedMigrationLookupService $guidedLookup
    ) {
        $status = trim((string) $request->query('status', 'active'));
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(10, (int) $request->query('per_page', 50)));
        $importFilter = trim((string) $request->query('import_filter', ''));

        if (! Schema::hasTable('customer_migrations')) {
            return Inertia::render('CustomerMigrations/Index', [
                'migrations' => [],
                'importRows' => [],
                'importStats' => ['total' => 0, 'migration' => 0, 'psb_new_candidate' => 0, 'needs_review' => 0, 'duplicate' => 0, 'rw_needs_review' => 0],
                'filters' => ['status' => $status, 'search' => $search],
                'pagination' => ['current_page' => 1, 'per_page' => $perPage, 'total' => 0, 'has_more' => false, 'next_page' => null, 'previous_page' => null],
                'importPagination' => ['current_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false, 'next_page' => null, 'previous_page' => null],
                'stats' => ['total' => 0, 'synced' => 0, 'waiting_sn' => 0, 'needs_review' => 0, 'failed' => 0, 'by_rw' => []],
                'overallStats' => ['raw_total' => 0, 'total' => 0, 'synced' => 0, 'db_synced' => 0, 'olt_active' => 0, 'pending' => 0, 'waiting_sn' => 0, 'needs_review' => 0, 'failed' => 0, 'technicians' => []],
                'tableReady' => false,
                'importReady' => Schema::hasTable('customer_migration_import_rows'),
                'activationSignals' => $waChat->signalsFor('activation', 6),
                'guidedLookup' => $this->guidedLookupMeta($request, $guidedLookup),
            ]);
        }

        // Snapshot/live reconciliation is read-only. Resolve the effective
        // identity set before pagination so an old review row cannot hide a
        // newer synced row or make one migration appear twice.
        $queryStatus = in_array($status, [CustomerMigration::STATUS_SYNCED, CustomerMigration::STATUS_NEEDS_REVIEW], true)
            ? 'active'
            : $status;
        $base = $this->migrationQuery($queryStatus, $search)
            ->with([
                'customer:id,name',
                'router:id,name',
                'oldOlt:id,name',
                'newOlt:id,name',
            ]);

        $rowModels = $base
            ->reorder()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $rowModels = $this->latestMigrationRows($rowModels);

        // Use the unfiltered active set for KPI/read-model evidence. The
        // requested status/search only controls the visible table, while one
        // cached OLT snapshot is shared by every read-only calculation.
        $reportRows = $rowModels;
        if ($status !== 'active' || $search !== '') {
            $reportRows = $this->migrationQuery('active', '')
                ->reorder()
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get();
            $reportRows = $this->latestMigrationRows($reportRows);
        }
        $oltOnlineIds = $this->cachedReadOnlyOltOnlineIds($reportRows);
        $liveRowIds = $this->readOnlyLiveMigrationIds($reportRows, $oltOnlineIds);

        $scopeAreaIds = $request->user()?->hasAreaScope() ? $request->user()->accessibleAreaIds()->all() : null;
        $filteredRows = $rowModels->map(function (CustomerMigration $m) use ($liveRowIds, $scopeAreaIds) {
            $review = is_array($m->meta['migration_review'] ?? null) ? $m->meta['migration_review'] : [];
            $deviceAction = is_array($m->meta['device_action_approval'] ?? null) ? $m->meta['device_action_approval'] : [];
            $timeline = is_array($m->meta['timeline'] ?? null) ? array_slice($m->meta['timeline'], -5) : [];
            $liveVerified = in_array((int) $m->id, $liveRowIds, true);

            return [
                'id' => $m->id,
                'status' => $liveVerified ? CustomerMigration::STATUS_SYNCED : $m->displayStatus(),
                'db_status' => (string) $m->status,
                'can_manual_complete' => $scopeAreaIds === null || ($m->area_id !== null && in_array((int) $m->area_id, $scopeAreaIds, true)),
                'status_source' => $liveVerified ? 'olt_snapshot+pppoe' : ($m->isSynced() ? 'database' : 'database_pending'),
                'technician_name' => in_array((string) $m->source, ['wa', 'guided_lookup_wa'], true)
                    ? (trim((string) $m->sender_name) ?: null)
                    : null,
                'status_detail' => $liveVerified
                    ? 'ONU OLT online dan PPPoE tersedia; status DB belum diubah.'
                    : null,
                'source' => $m->source,
                'pppoe_user' => $m->pppoe_user,
                'full_name' => $m->full_name ?: $m->customer?->name,
                'customer_id' => $m->customer_id,
                'old_onu_ref' => $m->old_onu_ref,
                'old_sn' => $m->old_sn,
                'new_onu_ref' => $m->new_onu_ref,
                'new_pon_port' => $m->new_pon_port,
                'new_onu_id' => $m->new_onu_id,
                'new_sn' => $m->new_sn,
                'router' => $m->router?->name,
                'old_olt' => $m->oldOlt?->name,
                'new_olt' => $m->newOlt?->name,
                'vlan' => $m->vlan,
                'tr069_vlan' => $m->tr069_vlan,
                'last_error' => $this->redactedError($m->last_error),
                'review_status' => ($liveVerified || $m->isSynced()) ? null : ($review['match_status'] ?? null),
                'review_reasons' => ($liveVerified || $m->isSynced()) ? [] : ($review['reasons'] ?? []),
                'device_action' => [
                    'status' => $deviceAction['status'] ?? null,
                    'ledger_id' => $deviceAction['ledger_id'] ?? null,
                    'write_flags' => $deviceAction['write_flags'] ?? [],
                    'rollback_hint' => $deviceAction['rollback_hint'] ?? null,
                    'blocked_reason' => $deviceAction['blocked_reason'] ?? null,
                ],
                'timeline' => array_map(fn ($step) => [
                    'step' => $step['step'] ?? '-',
                    'status' => $step['status'] ?? '-',
                    'at' => isset($step['at']) ? Carbon::parse($step['at'])->timezone('Asia/Jakarta')->format('Y-m-d H:i') : null,
                ], $timeline),
                'created_at' => $m->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'olt_at' => $m->olt_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'old_onu_deleted_at' => $m->old_onu_deleted_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'synced_at' => $m->synced_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'rt' => $m->rt,
                'rw' => $m->rw,
            ];
        })->filter(fn (array $row): bool => $this->displayStatusMatches($row['status'], $status))
            ->values();
        $rowsTotal = $filteredRows->count();
        $rows = $filteredRows->forPage($page, $perPage)->values();

        $todayStats = $this->todayMigrationStats($reportRows, $oltOnlineIds);
        $overallStats = $this->overallMigrationStats($reportRows, $oltOnlineIds);

        return Inertia::render('CustomerMigrations/Index', [
            'migrations' => $rows,
            'importRows' => $this->importRows($request),
            'importPagination' => $this->importPagination($request),
            'importStats' => $this->importStats(),
            'filters' => ['status' => $status, 'search' => $search, 'import_filter' => $importFilter],
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $rowsTotal,
                'has_more' => $page * $perPage < $rowsTotal,
                'next_page' => $page * $perPage < $rowsTotal ? $page + 1 : null,
                'previous_page' => $page > 1 ? $page - 1 : null,
            ],
            'stats' => [
                ...$todayStats,
                'by_rw' => $this->migrationRwSummary($rowModels, $status, $liveRowIds),
            ],
            'overallStats' => $overallStats,
            'tableReady' => true,
            'importReady' => Schema::hasTable('customer_migration_import_rows'),
            'activationSignals' => $waChat->signalsFor('activation', 6),
            'guidedLookup' => $this->guidedLookupMeta($request, $guidedLookup),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $status = trim((string) $request->query('status', 'active'));
        $search = trim((string) $request->query('search', ''));
        $filename = 'migrasi-'.now('Asia/Jakarta')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($status, $search) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID', 'Status', 'Source', 'Tanggal Dibuat', 'Nama Pelanggan', 'Customer ID',
                'PPPoE', 'Daerah', 'RT', 'RW', 'Router', 'OLT Lama', 'OLT Baru',
                'ONU Lama Ref', 'SN Lama', 'ONU Baru Ref', 'SN Baru', 'VLAN Internet',
                'VLAN TR-069', 'OLT Selesai', 'ONU Lama Dihapus', 'Synced At', 'Catatan',
            ]);

            $this->migrationQuery($status, $search)
                ->with(['customer:id,name,pppoe_user', 'router:id,name', 'oldOlt:id,name', 'newOlt:id,name'])
                ->reorder('id')
                ->chunkById(200, function ($rows) use ($out) {
                    foreach ($rows as $migration) {
                        fputcsv($out, [
                            $migration->id,
                            $migration->displayStatus(),
                            $migration->source,
                            $this->formatWib($migration->created_at),
                            $migration->full_name ?: $migration->customer?->name,
                            $migration->customer_id,
                            $migration->pppoe_user,
                            $migration->daerah,
                            $migration->rt,
                            $migration->rw,
                            $migration->router?->name,
                            $migration->oldOlt?->name,
                            $migration->newOlt?->name,
                            $migration->old_onu_ref,
                            $migration->old_sn,
                            $migration->new_onu_ref,
                            $migration->new_sn,
                            $migration->vlan,
                            $migration->tr069_vlan,
                            $this->formatWib($migration->olt_at),
                            $this->formatWib($migration->old_onu_deleted_at),
                            $this->formatWib($migration->synced_at),
                            $this->redactedError($migration->last_error),
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function guidedLookupPreview(
        Request $request,
        GuidedMigrationLookupService $guidedLookup
    ): JsonResponse {
        abort_unless($guidedLookup->canPreview($request->user()), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'new_sn' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:120'],
            'odp' => ['nullable', 'string', 'max:160'],
            'rt' => ['nullable', 'string', 'max:20'],
            'rw' => ['nullable', 'string', 'max:20'],
            'onu_name' => ['nullable', 'string', 'max:160'],
            'olt' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $guidedLookup->preview($data);
        $result['candidates'] = array_map(function (array $candidate): array {
            $serial = trim((string) ($candidate['old_sn'] ?? ''));
            $candidate['old_sn'] = strlen($serial) < 8
                ? ($serial !== '' ? $serial : null)
                : substr($serial, 0, 4).'***'.substr($serial, -4);

            return $candidate;
        }, $result['candidates'] ?? []);

        return response()->json($result);
    }

    public function approveGuidedLookup(
        Request $request,
        GuidedMigrationLookupService $guidedLookup
    ) {
        abort_unless($guidedLookup->canPreview($request->user()), 403);
        if (! $guidedLookup->enabled()) {
            return back()->withErrors(['guided_lookup' => 'Guided Migration masih OFF. Approval DB-only diblok.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'new_sn' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'customer_code' => ['nullable', 'string', 'max:80'],
            'area' => ['nullable', 'string', 'max:120'],
            'odp' => ['nullable', 'string', 'max:160'],
            'rt' => ['nullable', 'string', 'max:20'],
            'rw' => ['nullable', 'string', 'max:20'],
            'candidate_number' => ['required', 'integer', 'min:1', 'max:5'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $preview = $guidedLookup->preview($data);
        $candidate = collect($preview['candidates'] ?? [])
            ->first(fn (array $row) => (int) ($row['number'] ?? 0) === (int) $data['candidate_number']);

        if (! $candidate) {
            return back()->withErrors(['guided_lookup' => 'Kandidat tidak ditemukan. Jalankan preview ulang.']);
        }
        if (($candidate['conflicts'] ?? []) !== []) {
            return back()->withErrors(['guided_lookup' => 'Kandidat masih conflict. Selesaikan review dulu, belum bisa approve DB-only.']);
        }

        $key = 'guided-approval:'.substr(hash('sha256', json_encode([
            'input' => $preview['input'] ?? [],
            'customer_id' => $candidate['customer_id'] ?? null,
            'new_sn' => $this->normalizeSerial((string) $data['new_sn']),
        ], JSON_UNESCAPED_SLASHES)), 0, 24);

        $migration = DB::transaction(function () use ($candidate, $data, $key, $request, $preview): CustomerMigration {
            $existing = CustomerMigration::where('message_id', $key)->first();
            if ($existing) {
                return $existing;
            }

            $now = OpsTime::now();
            $newSn = $this->normalizeSerial((string) $data['new_sn']);
            $customer = Customer::query()
                ->select(['id', 'area_id', 'router_id', 'olt_id'])
                ->find($candidate['customer_id'] ?? null);
            $before = [
                'input' => $preview['input'] ?? [],
                'candidate' => $this->candidateSnapshot($candidate),
            ];
            $after = [
                'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
                'source' => 'guided_lookup_operator',
                'customer_id' => $candidate['customer_id'] ?? null,
                'new_sn' => $newSn,
                'review_status' => 'confirmed_pending_approval',
            ];

            return CustomerMigration::create([
                'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
                'source' => 'guided_lookup_operator',
                'message_id' => $key,
                'customer_id' => $candidate['customer_id'] ?? null,
                'pppoe_user' => $candidate['pppoe_user'] ?? null,
                'full_name' => $candidate['name'] ?? ($data['name'] ?? null),
                'phone' => $data['phone'] ?? null,
                'rt' => $candidate['rt'] ?? ($data['rt'] ?? null),
                'rw' => $candidate['rw'] ?? ($data['rw'] ?? null),
                'odp' => $candidate['odp'] ?? ($data['odp'] ?? null),
                'daerah' => $candidate['area'] ?? ($data['area'] ?? null),
                'area_id' => $customer?->area_id,
                'old_router_id' => $customer?->router_id,
                'old_olt_id' => $customer?->olt_id,
                'old_onu_ref' => $candidate['old_onu_ref'] ?? null,
                'old_sn' => $candidate['old_sn'] ?? null,
                'router_id' => $customer?->router_id,
                'new_sn' => $newSn,
                'last_error' => 'Guided migration DB-only; menunggu approval perangkat manual.',
                'meta' => [
                    'migration_review' => [
                        'match_status' => 'confirmed_pending_approval',
                        'reasons' => ['guided_lookup_operator_approved'],
                        'source' => 'guided_lookup',
                    ],
                    'guided_operator_approval' => [
                        'status' => 'confirmed_db_only',
                        'actor_id' => $request->user()?->id,
                        'actor_name' => $request->user()?->name,
                        'approved_at' => $now->toIso8601String(),
                        'reason' => $data['reason'],
                        'idempotency_key' => $key,
                        'candidate_number' => (int) $data['candidate_number'],
                        'confidence' => $candidate['confidence'] ?? null,
                        'score' => $candidate['score'] ?? null,
                        'sources' => $candidate['sources'] ?? [],
                        'before_snapshot' => $before,
                        'after_snapshot' => $after,
                        'rollback_hint' => "Set customer_migrations.status=cancelled untuk message_id {$key}; tidak ada customer/perangkat yang diubah.",
                        'device_write' => false,
                        'customer_write' => false,
                    ],
                    'timeline' => [[
                        'step' => 'guided_lookup_operator_approval',
                        'status' => 'confirmed_db_only',
                        'at' => $now->toIso8601String(),
                    ]],
                ],
            ]);
        });

        return back()->with('success', "Guided approval migrasi #{$migration->id} tersimpan DB-only.");
    }

    public function importPreview(
        Request $request,
        CustomerMigrationImportPreviewService $import,
        SafeRemoteSnapshotDownloader $downloader
    ) {
        $data = $request->validate([
            'file' => ['nullable', 'file', 'mimes:csv,txt,xlsx', 'max:20480'],
            'source_url' => ['nullable', 'url'],
            'source_label' => ['nullable', 'string', 'max:160'],
        ]);
        if (! ($data['file'] ?? null) && ! ($data['source_url'] ?? null)) {
            return back()->withErrors(['file' => 'Upload CSV/XLSX atau isi URL snapshot.']);
        }

        $path = null;
        if ($request->hasFile('file')) {
            $path = $request->file('file')->store('imports/migrasi-preview');
            $fullPath = Storage::path($path);
        } else {
            $download = $downloader->download((string) $data['source_url']);
            if ($download === null) {
                return back()->withErrors(['source_url' => 'Download URL gagal. Upload file snapshot secara manual.']);
            }
            $path = 'imports/migrasi-preview/url-'.date('YmdHis').'.xlsx';
            Storage::put($path, $download);
            $fullPath = Storage::path($path);
        }

        $result = $import->importDryRunFromPath($fullPath, $request->user()?->id, $data['source_label'] ?? ($data['source_url'] ?? basename((string) $path)));
        if ($result['imported'] === 0 && $result['errors'] !== []) {
            return back()->withErrors(['file' => implode(' ', $result['errors'])]);
        }

        return back()->with('success', "Dry-run import: {$result['imported']} row, skip {$result['skipped']}. Batch {$result['batch_key']}");
    }

    public function updateImportRow(
        Request $request,
        CustomerMigrationImportRow $row,
        CustomerMigrationImportPreviewService $import
    ) {
        $data = $request->validate([
            'classification' => ['required', 'in:migration,psb_new_candidate,needs_review'],
            'rw' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $import->correctRow(
            $row,
            $data['classification'],
            $data['rw'] ?? $row->rw,
            $data['reason'],
            $request->user()?->id
        );

        return back()->with('success', "Row import #{$row->id} dikoreksi.");
    }

    public function bulkCorrectImportRows(Request $request, CustomerMigrationImportPreviewService $import)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:customer_migration_import_rows,id'],
            'classification' => ['required', 'in:migration,psb_new_candidate,needs_review'],
            'rw' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $count = $import->bulkCorrect($data['ids'], $data['classification'], $data['rw'] ?? null, $data['reason'], $request->user()?->id);

        return back()->with('success', "{$count} row staging dikoreksi.");
    }

    public function applyImportRows(Request $request, CustomerMigrationImportPreviewService $import)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:customer_migration_import_rows,id'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $result = $import->applyRows($data['ids'], $request->user()?->id, $data['reason']);
        $message = "Apply staging: {$result['applied']} sukses, {$result['skipped']} skip.";
        if ($result['errors'] !== []) {
            return back()->with('error', $message.' '.implode(' ', array_slice($result['errors'], 0, 5)));
        }

        return back()->with('success', $message);
    }

    public function retry(CustomerMigration $migration, CustomerMigrationService $service)
    {
        // A WA guided draft has no Excel evidence: Retry only refreshes the
        // candidate list and never runs the Excel review.
        $guided = $service->retryGuidedFromWeb($migration);
        if ($guided['handled']) {
            return back()->with($guided['ok'] ? 'success' : 'error', $guided['message']);
        }

        $service->processMigration($migration);
        $migration->refresh();

        if ($service->partialExecutionState($migration) !== null) {
            // Device writes already happened: retry never re-reviews or resets
            // the draft. Continue via SN correction + execute (continuation).
            return back()->with('error', "Migrasi #{$migration->id} sudah sebagian dieksekusi; review tidak diulang. "
                .(data_get($migration->meta, 'partial_execution.message') ?: '')
                ." Koreksi SN: #migrasi sn #{$migration->id} <SN>, lalu #migrasi eksekusi #{$migration->id}.");
        }

        if ($migration->status === CustomerMigration::STATUS_FAILED) {
            return back()->with('error', "Retry migrasi #{$migration->id} gagal: ".($this->redactedError($migration->last_error) ?: 'lihat kolom catatan.'));
        }

        return back()->with('success', "Retry migrasi #{$migration->id} diproses. Status: {$migration->status}.");
    }

    public function cancel(CustomerMigration $migration, CustomerMigrationService $service)
    {
        $partial = $service->partialExecutionState($migration);
        if ($partial !== null) {
            // A half-migrated customer must not be cancelled silently: the
            // source secret may already be disabled. NOC uses the WA force
            // command (`#migrasi batal paksa #ID`) together with the rollback.
            return back()->with('error', "Migrasi #{$migration->id} sudah sebagian dieksekusi; batal ditolak. "
                .(data_get($migration->meta, 'partial_execution.rollback_hint') ?: '')
                .' Lanjutkan migrasi, atau batal paksa via WA: #migrasi batal paksa #'.$migration->id.'.');
        }

        $migration->status = CustomerMigration::STATUS_CANCELLED;
        $migration->last_error = 'Dibatalkan dari menu Migrasi';
        $migration->save();

        return back()->with('success', "Migrasi #{$migration->id} dibatalkan.");
    }

    public function prepareDeviceAction(Request $request, CustomerMigration $migration, CustomerMigrationService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);

        try {
            $updated = $service->prepareDeviceActionApproval($migration, $request->user(), $data['reason']);

            return back()->with('success', "Dry-run migrasi #{$updated->id} siap. Ledger action dibuat, belum ada write perangkat.");
        } catch (\Throwable $e) {
            return back()->withErrors(['migration_action' => $this->redactedError($e->getMessage())]);
        }
    }

    public function executeDeviceAction(Request $request, CustomerMigration $migration, CustomerMigrationService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:8', 'max:500']]);

        try {
            $updated = $service->executeApprovedDeviceAction($migration, $request->user(), $data['reason']);
            if (data_get($updated->meta, 'device_action_approval.status') === 'blocked_kill_switch') {
                return back()->withErrors(['migration_action' => 'Eksekusi ditahan: mode aman perangkat aktif atau saklar migrasi belum ON. Belum ada perubahan di perangkat.']);
            }

            return back()->with('success', "Execute migrasi #{$updated->id}: {$updated->status}.");
        } catch (\Throwable $e) {
            return back()->withErrors(['migration_action' => $this->redactedError($e->getMessage())]);
        }
    }

    /** Read-only evidence for the "Tandai selesai" dialog. */
    public function manualCompletePreview(Request $request, CustomerMigration $migration, MigrationManualCompleteService $manual): JsonResponse
    {
        $this->authorizeManualComplete($request, $migration);

        return response()->json($manual->preview($migration));
    }

    /** DB-only close with a written reason (same effect as the auto-reconcile; no device / WA). */
    public function manualComplete(Request $request, CustomerMigration $migration, MigrationManualCompleteService $manual)
    {
        $this->authorizeManualComplete($request, $migration);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:'.MigrationManualCompleteService::REASON_MIN, 'max:'.MigrationManualCompleteService::REASON_MAX],
        ]);

        $result = $manual->complete($migration, $request->user(), $data['reason']);

        if ($request->expectsJson()) {
            return response()->json($result, $result['ok'] ? 200 : 422);
        }
        if ($result['ok']) {
            return back()->with('success', $result['message']);
        }

        return $result['code'] === 'sudah_selesai'
            ? back()->with('success', $result['message'])
            : back()->withErrors(['migration_action' => $result['message']]);
    }

    protected function authorizeManualComplete(Request $request, CustomerMigration $migration): void
    {
        $user = $request->user();
        abort_unless($user?->isAdminLike(), 403);
        if ($user->hasAreaScope()) {
            abort_unless($migration->area_id !== null && $user->accessibleAreaIds()->contains((int) $migration->area_id), 403);
        }
    }

    protected function importRows(Request $request): array
    {
        if (! Schema::hasTable('customer_migration_import_rows')) {
            return [];
        }

        $page = max(1, (int) $request->query('import_page', 1));
        $query = $this->importQuery($request);

        return $query->simplePaginate(50, ['*'], 'import_page', $page)->getCollection()->map(fn (CustomerMigrationImportRow $row) => [
            'id' => $row->id,
            'batch_key' => $row->batch_key,
            'source_label' => $row->source_label,
            'row_number' => $row->row_number,
            'classification' => $row->classification,
            'classification_source' => $row->classification_source,
            'project' => $row->project,
            'area' => $row->area,
            'sub_area' => $row->sub_area,
            'rw' => $row->rw,
            'rw_needs_review' => $row->rw_needs_review,
            'full_name' => $row->full_name,
            'pppoe_user' => $row->pppoe_user,
            'sn_lama' => $row->sn_lama,
            'sn_baru' => $row->sn_baru,
            'sn_baru_source' => $row->sn_baru_source,
            'old_onu_ref' => $row->old_onu_ref,
            'match_status' => $row->match_status,
            'match_notes' => $row->match_notes,
            'override_reason' => $row->override_reason,
            'status' => $row->status,
            'applied_target_type' => $row->applied_target_type,
            'applied_target_id' => $row->applied_target_id,
            'applied_error' => $row->applied_error,
            'imported_at' => $row->imported_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'corrected_at' => $row->corrected_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'applied_at' => $row->applied_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
            'candidate_customers' => array_values($row->meta['candidate_customers'] ?? []),
            'last_correction' => collect($row->meta['correction_audit'] ?? [])->last(),
        ])->all();
    }

    protected function importQuery(Request $request): Builder
    {
        $filter = trim((string) $request->query('import_filter', ''));
        $query = CustomerMigrationImportRow::query()->orderByDesc('id');
        if ($filter !== '' && $filter !== 'all') {
            match ($filter) {
                'migration', 'psb_new_candidate', 'needs_review' => $query->where('classification', $filter),
                'duplicate', 'not_migrated', 'migrated' => $query->where('match_status', $filter),
                'rw_needs_review' => $query->where('rw_needs_review', true),
                default => null,
            };
        }

        return $query;
    }

    protected function importPagination(Request $request): array
    {
        if (! Schema::hasTable('customer_migration_import_rows')) {
            return ['current_page' => 1, 'per_page' => 50, 'total' => 0, 'has_more' => false, 'next_page' => null, 'previous_page' => null];
        }

        $page = max(1, (int) $request->query('import_page', 1));
        $perPage = 50;
        $total = $this->importQuery($request)->count();

        return [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'has_more' => $page * $perPage < $total,
            'next_page' => $page * $perPage < $total ? $page + 1 : null,
            'previous_page' => $page > 1 ? $page - 1 : null,
        ];
    }

    /**
     * @return array{available: bool, feature_enabled: bool, allowlist: array<string, mixed>, consumer_enabled: false, queue: string}
     */
    protected function guidedLookupMeta(
        Request $request,
        GuidedMigrationLookupService $guidedLookup
    ): array {
        return [
            'available' => $guidedLookup->canPreview($request->user()),
            'feature_enabled' => $guidedLookup->enabled(),
            'allowlist' => $guidedLookup->allowlistMeta(),
            'consumer_enabled' => false,
            'queue' => GuidedMigrationLookupService::QUEUE,
        ];
    }

    protected function importStats(): array
    {
        if (! Schema::hasTable('customer_migration_import_rows')) {
            return ['total' => 0, 'migration' => 0, 'psb_new_candidate' => 0, 'needs_review' => 0, 'duplicate' => 0, 'rw_needs_review' => 0];
        }

        return [
            'total' => CustomerMigrationImportRow::count(),
            'migration' => CustomerMigrationImportRow::where('classification', 'migration')->count(),
            'psb_new_candidate' => CustomerMigrationImportRow::where('classification', 'psb_new_candidate')->count(),
            'needs_review' => CustomerMigrationImportRow::where('classification', 'needs_review')->orWhere('match_status', 'needs_review')->count(),
            'duplicate' => CustomerMigrationImportRow::where('match_status', 'duplicate')->count(),
            'rw_needs_review' => CustomerMigrationImportRow::where('rw_needs_review', true)->count(),
        ];
    }

    protected function normalizeRw(?string $rw): string
    {
        $value = trim((string) $rw);
        if ($value !== '' && preg_match('/(?:rw\s*)?(\d{1,3})/i', $value, $m)) {
            return 'RW '.((int) $m[1]);
        }

        return '(review)';
    }

    protected function normalizeGroupNumber(?string $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && preg_match('/(\d{1,3})/', $value, $m)) {
            return str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT);
        }

        return $value !== '' ? $value : '(kosong)';
    }

    /**
     * @return array{total:int, synced:int, success_actual:int, olt_active:int, waiting_sn:int, needs_review:int, failed:int}
     */
    protected function todayMigrationStats(Collection $allRows, ?array $oltOnlineIds = null): array
    {
        [$startUtc, $endUtc] = OpsTime::dayBounds();
        $rows = $allRows->filter(function (CustomerMigration $row) use ($startUtc, $endUtc): bool {
            return $row->created_at !== null
                && $row->created_at->betweenIncluded($startUtc, $endUtc);
        })->sortByDesc('updated_at')->sortByDesc('id')->values();
        $rows = $this->latestMigrationRows($rows);
        $oltOnlineIds ??= $this->cachedReadOnlyOltOnlineIds($rows);
        $liveIds = $this->readOnlyLiveMigrationIds($rows, $oltOnlineIds);
        $dbSyncedIds = $rows->filter(fn (CustomerMigration $row): bool => $row->isSynced())
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $actualIds = array_values(array_unique(array_merge($dbSyncedIds, $liveIds)));

        return [
            'total' => $rows->count(),
            'synced' => count($dbSyncedIds),
            'success_actual' => count($actualIds),
            'olt_active' => count($oltOnlineIds),
            'waiting_sn' => $rows->filter(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_WAITING_SN
                && ! in_array((int) $row->id, $actualIds, true))->count(),
            'needs_review' => $rows->filter(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_NEEDS_REVIEW
                && ! in_array((int) $row->id, $liveIds, true))->count(),
            'failed' => $rows->where('status', CustomerMigration::STATUS_FAILED)->count(),
        ];
    }

    /**
     * Unified, deduplicated totals for the migration page.
     *
     * This intentionally uses the database/read model only. Device snapshots
     * are reserved for the smaller "today" operational counters above so a
     * page refresh cannot turn into a full OLT scan.
     *
     * @return array{raw_total:int, total:int, synced:int, db_synced:int, olt_active:int, pending:int, waiting_sn:int, needs_review:int, failed:int, technicians:array<int, array{name:string,total:int,synced:int,pending:int}>}
     */
    protected function overallMigrationStats(Collection $rows, ?array $oltLiveIds = null): array
    {
        $rawTotal = $rows->count();
        $rows = $this->latestMigrationRows($rows);

        // OLT rows are a cached/read-model snapshot; never query the device from
        // the page. A live-looking DB status alone is not enough for the KPI.
        $oltLiveIds ??= $this->cachedReadOnlyOltOnlineIds($rows);
        $dbSynced = $rows->filter(fn (CustomerMigration $row): bool => $row->isSynced());
        $synced = $rows->filter(fn (CustomerMigration $row): bool => $row->isSynced()
            || in_array((int) $row->id, $oltLiveIds, true));
        $failed = $rows->where('status', CustomerMigration::STATUS_FAILED);
        $needsReview = $rows->filter(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_NEEDS_REVIEW && ! $synced->contains('id', $row->id));
        $waitingSn = $rows->filter(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_WAITING_SN && ! $synced->contains('id', $row->id));
        $technicians = $rows
            ->filter(fn (CustomerMigration $row): bool => in_array((string) $row->source, ['wa', 'guided_lookup_wa'], true) && filled(trim((string) $row->sender_name)))
            ->groupBy(fn (CustomerMigration $row): string => trim((string) $row->sender_name))
            ->map(fn (Collection $items, string $name): array => [
                'name' => $name,
                'total' => $items->count(),
                'synced' => $items->filter(fn (CustomerMigration $row): bool => $row->isSynced() || in_array((int) $row->id, $oltLiveIds, true))->count(),
                'pending' => $items->reject(fn (CustomerMigration $row): bool => $row->isSynced() || in_array((int) $row->id, $oltLiveIds, true) || $row->status === CustomerMigration::STATUS_FAILED)->count(),
            ])
            ->sort(function (array $left, array $right): int {
                $totalOrder = $right['total'] <=> $left['total'];

                return $totalOrder !== 0
                    ? $totalOrder
                    : strcmp($left['name'], $right['name']);
            })
            ->values()
            ->all();

        return [
            'raw_total' => $rawTotal,
            'total' => $rows->count(),
            'synced' => $synced->count(),
            'db_synced' => $dbSynced->count(),
            'olt_active' => count($oltLiveIds),
            'pending' => $rows->reject(fn (CustomerMigration $row): bool => $row->isSynced() || in_array((int) $row->id, $oltLiveIds, true) || $row->status === CustomerMigration::STATUS_FAILED)->count(),
            'waiting_sn' => $waitingSn->count(),
            'needs_review' => $needsReview->count(),
            'failed' => $failed->count(),
            'technicians' => $technicians,
        ];
    }

    /**
     * Latest row per customer/PPPoE/SN identity for read-only reporting.
     *
     * @param  Collection<int, CustomerMigration>  $rows
     * @return Collection<int, CustomerMigration>
     */
    protected function latestMigrationRows(Collection $rows): Collection
    {
        $seen = [];
        $latest = [];
        foreach ($rows as $row) {
            $key = filled($row->customer_id)
                ? 'customer:'.$row->customer_id
                : (filled($row->pppoe_user)
                    ? 'pppoe:'.strtoupper(trim((string) $row->pppoe_user))
                    : (filled($row->new_sn)
                        ? 'sn:'.strtoupper(trim((string) $row->new_sn))
                        : 'migration:'.$row->id));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $latest[] = $row;
            }
        }

        return collect($latest);
    }

    /**
     * @param  Collection<int, CustomerMigration>  $rows
     * @return list<int>
     */
    protected function readOnlyOltOnlineIds(Collection $rows): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [];
        }

        $candidates = $rows->filter(fn (CustomerMigration $row): bool => filled($row->new_sn));
        if ($candidates->isEmpty()) {
            return [];
        }

        $oltIds = $candidates->pluck('new_olt_id')->filter()->unique()->values()->all();
        $onus = OltOnu::query()->whereIn('olt_id', $oltIds)
            ->get(['olt_id', 'serial_number', 'state', 'phase_state', 'rx_power_dbm']);
        $onusByKey = [];
        foreach ($onus as $onu) {
            $key = (int) $onu->olt_id.'|'.strtoupper(preg_replace('/\s+/', '', (string) $onu->serial_number) ?? '');
            if (! isset($onusByKey[$key])) {
                $onusByKey[$key] = $onu;
            }
        }

        $online = [];
        foreach ($candidates as $row) {
            $sn = strtoupper(preg_replace('/\s+/', '', (string) $row->new_sn) ?? '');
            $onu = $onusByKey[(int) $row->new_olt_id.'|'.$sn] ?? null;
            if ($onu && OltOnu::normalizeOperationalState($onu->state, $onu->phase_state, $onu->rx_power_dbm) === 'online') {
                $online[] = (int) $row->id;
            }
        }

        return array_values(array_unique($online));
    }

    /**
     * @param  Collection<int, CustomerMigration>  $rows
     * @return list<int>
     */
    protected function readOnlyLiveMigrationIds(Collection $rows, ?array $oltOnlineIds = null): array
    {
        $oltOnlineIds ??= $this->cachedReadOnlyOltOnlineIds($rows);
        $online = array_fill_keys(array_map('intval', $oltOnlineIds), true);

        return $rows->filter(fn (CustomerMigration $row): bool => $row->status === CustomerMigration::STATUS_NEEDS_REVIEW
            && (filled($row->customer_id) || filled($row->pppoe_user))
            && isset($online[(int) $row->id]))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Read-only OLT evidence is short-lived because collectors own freshness.
     * The fingerprint changes when the migration read model changes, while the
     * TTL prevents three identical page computations from repeating the query.
     *
     * @param  Collection<int, CustomerMigration>  $rows
     * @return list<int>
     */
    protected function cachedReadOnlyOltOnlineIds(Collection $rows): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [];
        }

        $version = sha1(json_encode([
            $rows->count(),
            (string) $rows->max('id'),
            (string) $rows->max('updated_at'),
        ], JSON_UNESCAPED_SLASHES));

        return Cache::remember(
            'migration.read_only_olt_online_ids.'.$version,
            now()->addSeconds(20),
            fn (): array => $this->readOnlyOltOnlineIds($rows),
        );
    }

    /**
     * @return list<array{rw: string, total: int, synced: int, rts: array<string, array{rt: string, total: int, synced: int}>}>
     */
    protected function migrationRwSummary(Collection $rows, string $status = 'active', ?array $liveIds = null): array
    {
        $summary = [];
        $liveIds ??= $this->readOnlyLiveMigrationIds($rows);
        foreach ($rows as $row) {
            $effectiveStatus = (in_array((int) $row->id, $liveIds, true) || $row->isSynced())
                ? CustomerMigration::STATUS_SYNCED
                : $row->displayStatus();

            if (! $this->displayStatusMatches($effectiveStatus, $status)) {
                continue;
            }

            $rw = $this->normalizeGroupNumber($row->rw);
            $rt = $this->normalizeGroupNumber($row->rt);
            if (! isset($summary[$rw])) {
                $summary[$rw] = ['rw' => $rw, 'total' => 0, 'synced' => 0, 'rts' => []];
            }
            if (! isset($summary[$rw]['rts'][$rt])) {
                $summary[$rw]['rts'][$rt] = ['rt' => $rt, 'total' => 0, 'synced' => 0];
            }

            $summary[$rw]['total']++;
            $summary[$rw]['rts'][$rt]['total']++;
            if ($effectiveStatus === CustomerMigration::STATUS_SYNCED) {
                $summary[$rw]['synced']++;
                $summary[$rw]['rts'][$rt]['synced']++;
            }
        }

        uasort($summary, fn ($a, $b) => $a['rw'] === '(kosong)'
            ? 1
            : ($b['rw'] === '(kosong)' ? -1 : strnatcmp($a['rw'], $b['rw']))
        );

        foreach ($summary as &$rwData) {
            uasort($rwData['rts'], fn ($a, $b) => $a['rt'] === '(kosong)'
                ? 1
                : ($b['rt'] === '(kosong)' ? -1 : strnatcmp($a['rt'], $b['rt']))
            );
        }

        return array_values($summary);
    }

    protected function displayStatusMatches(string $effectiveStatus, string $requestedStatus): bool
    {
        if ($requestedStatus === '' || $requestedStatus === 'active') {
            return $effectiveStatus !== CustomerMigration::STATUS_CANCELLED;
        }

        if ($requestedStatus === 'all') {
            return true;
        }

        return $effectiveStatus === $requestedStatus;
    }

    /**
     * last_error and device exceptions can carry raw router/OLT output. Strip
     * credential-shaped tokens before they reach the browser, CSV or flash.
     */
    protected function redactedError(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        return SensitiveDataRedactor::redact($message);
    }

    protected function migrationQuery(string $status, string $search)
    {
        $query = CustomerMigration::query()
            ->select([
                'id', 'area_id', 'status', 'source', 'group_jid', 'sender_jid', 'sender_name', 'message_id',
                'customer_id', 'pppoe_user', 'full_name', 'rt', 'rw', 'daerah',
                'old_olt_id', 'old_onu_ref', 'old_sn', 'router_id', 'new_olt_id', 'new_onu_ref',
                'new_pon_port', 'new_onu_id', 'new_sn', 'vlan', 'tr069_vlan', 'last_error', 'meta',
                'olt_at', 'old_onu_deleted_at', 'synced_at', 'created_at', 'updated_at',
            ])
            ->orderByDesc('id');

        if ($status === 'active') {
            $query->where('status', '!=', CustomerMigration::STATUS_CANCELLED);
        } elseif ($status === CustomerMigration::STATUS_SYNCED) {
            $query->where(function ($q) {
                $q->where('status', CustomerMigration::STATUS_SYNCED)
                    ->orWhereNotNull('synced_at');
            });
        } elseif ($status === CustomerMigration::STATUS_WAITING_SN) {
            $query->where('status', CustomerMigration::STATUS_WAITING_SN)
                ->whereNull('synced_at');
        } elseif ($status !== '' && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], '', $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('pppoe_user', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('new_sn', 'like', $like)
                    ->orWhere('old_sn', 'like', $like)
                    ->orWhere('old_onu_ref', 'like', $like)
                    ->orWhere('new_onu_ref', 'like', $like);
            });
        }

        return $query;
    }

    protected function formatWib($value): ?string
    {
        return $value ? Carbon::parse($value)->timezone('Asia/Jakarta')->format('Y-m-d H:i:s') : null;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>
     */
    protected function candidateSnapshot(array $candidate): array
    {
        return [
            'number' => $candidate['number'] ?? null,
            'customer_id' => $candidate['customer_id'] ?? null,
            'customer_code' => $candidate['customer_code'] ?? null,
            'name' => $candidate['name'] ?? null,
            'pppoe_user' => $candidate['pppoe_user'] ?? null,
            'area' => $candidate['area'] ?? null,
            'odp' => $candidate['odp'] ?? null,
            'rt' => $candidate['rt'] ?? null,
            'rw' => $candidate['rw'] ?? null,
            'old_sn' => $candidate['old_sn'] ?? null,
            'old_onu_ref' => $candidate['old_onu_ref'] ?? null,
            'old_onu_resolution' => $candidate['old_onu_resolution'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'score' => $candidate['score'] ?? null,
            'match_reasons' => $candidate['match_reasons'] ?? [],
            'sources' => $candidate['sources'] ?? [],
            'freshness' => $candidate['freshness'] ?? [],
        ];
    }

    protected function normalizeSerial(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
    }
}
