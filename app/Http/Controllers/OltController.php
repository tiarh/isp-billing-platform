<?php

namespace App\Http\Controllers;

use App\Exceptions\HuaweiOltSessionBusyException;
use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Http\Requests\OltRequest;
use App\Jobs\ProcessGenieAcsInformJob;
use App\Jobs\RefreshOltSnapshotJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltOperationLog;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerConnectivityService;
use App\Services\GenieAcsService;
use App\Services\HuaweiOnuMaintenanceService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Olt\OltProvisioningGuard;
use App\Services\OltCollectorService;
use App\Services\OltHiosoService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltSnapshotBuilder;
use App\Services\OltSnapshotStatsService;
use App\Services\OltZteC300Service;
use App\Services\OnuLiveReadService;
use App\Support\OltOperationLock;
use App\Support\OltResponseSanitizer;
use App\Support\OltVendor;
use App\Support\SensitiveDataRedactor;
use App\Support\SimpleXlsxWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\Process\Process;
use Throwable;

class OltController extends Controller
{
    private function provisioningGate(Olt $olt, Request $request, string $operation): ?JsonResponse
    {
        if ($request->attributes->get('olt_provisioning_gate_checked') === true) {
            return null;
        }

        try {
            $result = app(OltProvisioningGuard::class)->provision($olt, [
                'commands' => [$operation],
                'approval' => $request->input('approval') ?: $request->header('X-OLT-Approval'),
                'approved_at' => $request->input('approved_at') ?: $request->header('X-OLT-Approved-At'),
                'signature' => $request->input('signature') ?: $request->header('X-OLT-Signature'),
            ]);
        } catch (OltProvisioningBlockedException) {
            return $this->provisioningBlockedResponse();
        }

        if ($result['dry_run']) {
            return $this->provisioningBlockedResponse('dry_run');
        }

        return null;
    }

    private function provisioningBlockedResponse(string $code = 'approval_required'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'olt_provisioning_'.$code,
            'message' => 'OLT write blocked by the provisioning safety gate. No device write was performed.',
        ], 423);
    }

    public function index(Request $request)
    {
        $query = Olt::query()
            ->select([
                'id',
                'name',
                'code',
                'vendor',
                'management_ip',
                'management_protocol',
                'management_port',
                'location',
                'area_id',
                'router_id',
                'created_at',
            ])
            ->with(['area:id,name', 'router:id,name']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('management_ip', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        $allowedSorts = ['name', 'code', 'management_ip', 'location', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $limit = min(max((int) $request->input('limit', 20), 1), 100);

        $olts = $query
            ->orderBy($sort, $direction)
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Olts/Index', [
            'olts' => $olts,
            'filters' => [
                'search' => $request->search,
                'limit' => $limit,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Export the cached ONU inventory for every OLT. This is deliberately
     * read-only: exports must never trigger a device refresh from the browser.
     */
    public function export(Olt $olt)
    {
        $snapshot = app(OltSnapshotStatsService::class)
            ->enrich($this->resolveCachedSnapshot($olt));
        $meta = $snapshot['meta'] ?? [];
        $matchedByRef = collect($snapshot['matched_customers'] ?? [])
            ->filter(fn ($row) => is_array($row) && filled($row['customer_id'] ?? null) && filled($row['onu_ref'] ?? null))
            ->keyBy(fn (array $row) => $this->normalizeOnuRefKey($row['onu_ref']));
        $customerIds = $matchedByRef->pluck('customer_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $customers = $customerIds->isEmpty()
            ? collect()
            : Customer::query()
                ->whereIn('id', $customerIds->all())
                ->get(['id', 'name', 'code', 'pppoe_user', 'phone', 'address'])
                ->keyBy('id');
        $snapshotId = (string) ($meta['snapshot_id'] ?? 'unknown');
        $filename = 'olt-'.Str::slug($olt->name ?: 'inventory').'-snapshot-'.substr($snapshotId, 0, 12).'.xlsx';

        $rows = collect($snapshot['onus'] ?? [])
            ->filter(fn ($onu) => is_array($onu))
            ->sortBy('onu_ref')
            ->map(function (array $onu) use ($olt, $meta, $matchedByRef, $customers): array {
                $customerId = (int) ($matchedByRef->get($this->normalizeOnuRefKey($onu['onu_ref'] ?? ''))['customer_id'] ?? 0);
                $customer = $customers->get($customerId);
                $serial = strtoupper(trim((string) ($onu['serial_number'] ?? '')));

                return [
                    $olt->name, $olt->code, $olt->location,
                    $meta['snapshot_id'] ?? null, $meta['captured_at'] ?? ($meta['collected_at'] ?? null),
                    $meta['source'] ?? null, $meta['collector_status'] ?? null, $meta['stale'] ? 'stale' : 'fresh',
                    $onu['onu_ref'] ?? null, $onu['pon_port'] ?? null, $onu['onu_name'] ?? null,
                    $onu['serial_number'] ?? null,
                    str_starts_with($serial, 'FHTT') ? 'FHTT/FiberHome' : 'Non-FHTT',
                    $onu['state'] ?? null, $onu['phase_state'] ?? null, $onu['rx_power_dbm'] ?? null,
                    $onu['distance_m'] ?? null, $customer?->name, $customer?->code,
                    $customer?->pppoe_user, $customer?->phone, $customer?->address,
                ];
            })->values()->all();

        $path = SimpleXlsxWriter::create($filename, [
            'OLT', 'Kode OLT', 'Lokasi OLT', 'Snapshot ID', 'Captured At',
            'Source', 'Collector Status', 'Freshness', 'ONU Ref', 'PON Port',
            'ONU Name', 'SN', 'Tipe SN', 'Status', 'Phase', 'Redaman dBm',
            'Jarak Meter', 'Nama Pelanggan', 'Kode Pelanggan', 'PPPoE',
            'No HP', 'Alamat',
        ], $rows);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    public function create()
    {
        return Inertia::render('Olts/Create', [
            'areas' => Area::query()->orderBy('name')->get(['id', 'name']), 'ui_examples' => (array) config('site.ui_examples'),
            'routers' => Router::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(OltRequest $request)
    {
        Olt::create($this->normalizedPayload($request->validated()));

        return redirect()->route('olts.index')
            ->with('success', 'OLT created successfully.');
    }

    public function show(Olt $olt)
    {
        $olt->load([
            'area:id,name',
            'router:id,name',
            'customers' => function ($query) {
                $query
                    ->select([
                        'id',
                        'code',
                        'name',
                        'pppoe_user',
                        'status',
                        'olt_id',
                        'olt_port_label',
                        'onu_serial',
                        'olt_status',
                        'onu_rx_power_dbm',
                        'onu_tx_power_dbm',
                        'fiber_distance_m',
                        'olt_last_synced_at',
                    ])
                    ->orderBy('olt_port_label')
                    ->orderBy('name');
            },
        ]);

        return Inertia::render('Olts/Show', [
            'olt' => $this->serializeOlt($olt),
            'huaweiUiWrite' => [
                'enabled' => self::huaweiUiWriteEnabled(),
                'superadmin' => (bool) request()->user()?->isSuperAdmin(),
                'save_pending_since' => $this->isHuaweiOlt($olt) ? Cache::get(self::huaweiSavePendingKey($olt)) : null,
            ],
        ]);
    }

    public function edit(Olt $olt)
    {
        return Inertia::render('Olts/Edit', [
            'olt' => $this->serializeOlt($olt, true),
            'areas' => Area::query()->orderBy('name')->get(['id', 'name']), 'ui_examples' => (array) config('site.ui_examples'),
            'routers' => Router::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(OltRequest $request, Olt $olt)
    {
        $validated = $this->normalizedPayload($request->validated());

        if (($validated['password'] ?? null) === null || $validated['password'] === '') {
            unset($validated['password']);
        }
        if (($validated['snmp_community'] ?? null) === null || $validated['snmp_community'] === '') {
            unset($validated['snmp_community']);
        }

        $olt->update($validated);

        return redirect()->route('olts.index')
            ->with('success', 'OLT updated successfully.');
    }

    public function destroy(Olt $olt)
    {
        $olt->delete();

        return redirect()->route('olts.index')
            ->with('success', 'OLT deleted successfully.');
    }

    public function testConnection(Olt $olt)
    {
        if (! $olt->management_ip) {
            return back()->with('error', 'Set the OLT management IP first.');
        }

        $protocol = $olt->management_protocol ?: 'ssh';
        $port = $olt->management_port ?: $this->defaultPortForProtocol($protocol);

        try {
            if (in_array($protocol, ['http', 'https'], true)) {
                $url = "{$protocol}://{$olt->management_ip}:{$port}";
                $client = Http::timeout(8)
                    ->withoutRedirecting()
                    ->withOptions(['verify' => false]);

                if ($olt->username || $olt->password) {
                    $client = $client->withBasicAuth($olt->username ?: '', $olt->password ?: '');
                }

                $response = $client->get($url);

                $status = $response->status();
                if (in_array($status, [200, 301, 302, 401, 403], true)) {
                    return back()->with('success', "OLT web reachable via {$url} (HTTP {$status}).");
                }

                return back()->with('error', "OLT web responded via {$url}, but status is HTTP {$status}.");
            }

            if ($protocol === 'snmp') {
                $process = new Process([
                    'snmpwalk',
                    '-v2c',
                    '-c',
                    $olt->snmp_community ?: 'public',
                    '-On',
                    '-t',
                    '2',
                    '-r',
                    '1',
                    "{$olt->management_ip}:{$port}",
                    '.1.3.6.1.2.1.1',
                ]);
                $process->setTimeout(12);
                $process->run();

                $output = trim($process->getOutput()."\n".$process->getErrorOutput());
                if (! $process->isSuccessful() || preg_match('/(timeout|no response|unknown host|authentication failure)/i', $output)) {
                    return back()->with('error', 'OLT SNMP failed: '.($output ?: 'No SNMP response.'));
                }

                return back()->with('success', "OLT SNMP reachable via {$olt->management_ip}:{$port}.");
            }

            $transport = 'tcp';
            $target = sprintf('%s://%s:%d', $transport, $olt->management_ip, $port);

            $errno = 0;
            $errstr = '';
            $socket = @stream_socket_client($target, $errno, $errstr, 5);

            if (! $socket) {
                return back()->with('error', "OLT connection failed: {$errstr} ({$errno})");
            }

            fclose($socket);

            return back()->with('success', "OLT connection succeeded via {$protocol} to {$olt->management_ip}:{$port}.");
        } catch (Throwable $e) {
            return back()->with('error', "OLT connection failed: {$e->getMessage()}");
        }
    }

    public function gponSnapshot(Request $request, Olt $olt)
    {
        $this->recoverStaleOltSyncStatus($olt);
        $olt->refresh();

        $cachedSnapshot = $this->resolveCachedSnapshot($olt);
        $forceRefresh = $request->boolean('refresh');

        if (! empty($cachedSnapshot['onus']) && ! $this->shouldUseEnterpriseRefresh($olt)) {
            $cachedSnapshot = $this->restoreSnapshotRxPowerFromCustomers($olt, $cachedSnapshot);
        }

        $cachedSnapshot = app(OltSnapshotStatsService::class)->enrich($cachedSnapshot);

        if ($forceRefresh) {
            return $this->queueGponSnapshotRefreshResponse($olt, $cachedSnapshot);
        }

        if (! $forceRefresh && ! empty($cachedSnapshot['onus'])) {
            $cachedSnapshot['meta']['served_from_cache'] = true;
            $cachedSnapshot['meta']['last_attempt_at'] = now()->toISOString();
            $cachedSnapshot['meta'] = $this->appendOltSyncMeta($olt, $cachedSnapshot['meta']);

            return response()->json($this->redactCommandOutputs($cachedSnapshot));
        }

        $snapshot = [
            'onus' => [],
            'matched_customers' => [],
            'meta' => $this->appendOltSyncMeta($olt, [
                'served_from_cache' => false,
                'refresh_queued' => false,
                'refresh_inline' => false,
                'stale' => true,
                'error_code' => 'olt_snapshot_unavailable',
                'message' => 'Snapshot GPON belum tersedia. Jalankan refresh lewat queue/worker, bukan dari request browser web 102.',
                'last_attempt_at' => now()->toISOString(),
            ]),
        ];

        return response()->json($this->redactCommandOutputs($snapshot), 503);
    }

    protected function queueGponSnapshotRefreshResponse(Olt $olt, array $cachedSnapshot)
    {
        $hasSnapshot = ! empty($cachedSnapshot['onus']);
        $snapshot = $hasSnapshot ? $cachedSnapshot : [
            'onus' => [],
            'matched_customers' => [],
            'meta' => [],
        ];

        $queued = false;
        $error = null;

        try {
            $lockKey = 'olt:gpon-refresh:queued:'.$olt->id;
            $queued = Cache::add($lockKey, true, now()->addMinutes(3));

            if ($queued) {
                if (Schema::hasColumn('olts', 'sync_status')) {
                    $olt->forceFill(['sync_status' => 'queued', 'last_sync_error' => null])->save();
                }

                RefreshOltSnapshotJob::dispatch($olt->id);
            }
        } catch (Throwable $e) {
            report($e);
            $queued = false;
            $error = $e->getMessage();
        }

        $snapshot['meta'] = $this->appendOltSyncMeta($olt, array_merge($snapshot['meta'] ?? [], [
            'served_from_cache' => $hasSnapshot,
            'refresh_queued' => $queued,
            'refresh_inline' => false,
            'stale' => true,
            'error_code' => $hasSnapshot ? null : 'olt_snapshot_unavailable',
            'message' => $hasSnapshot
                ? 'Refresh GPON diantrikan. Snapshot terakhir tetap ditampilkan.'
                : 'Snapshot GPON belum tersedia. Refresh diantrikan jika worker/device plane siap.',
            'last_attempt_at' => now()->toISOString(),
            'last_error' => $error,
        ]));
        $snapshot = app(OltSnapshotStatsService::class)->enrich($snapshot);

        return response()->json(
            $this->redactCommandOutputs($snapshot),
            $hasSnapshot ? 200 : ($queued ? 202 : 503)
        );
    }

    public function refreshSnapshotForOlt(Olt $olt, ?array $cachedSnapshot = null): array
    {
        $cachedSnapshot ??= $this->snapshotArray($olt->last_gpon_snapshot);

        if (! empty($cachedSnapshot['onus']) && ! $this->shouldUseEnterpriseRefresh($olt)) {
            $cachedSnapshot = $this->restoreSnapshotRxPowerFromCustomers($olt, $cachedSnapshot);
        }

        $cachedSnapshot = app(OltSnapshotStatsService::class)->enrich($cachedSnapshot);

        if ($this->shouldUseEnterpriseRefresh($olt)) {
            return $this->refreshEnterpriseSnapshotForOlt($olt, $cachedSnapshot);
        }

        $snapshot = $this->oltService($olt)->collectSnapshot($olt);
        if (empty($snapshot['onus']) && ! empty($cachedSnapshot['onus'])) {
            $cachedSnapshot['meta']['warning'] = 'Collector OLT belum berhasil mengambil data terbaru, menampilkan snapshot terakhir yang masih valid.';
            $cachedSnapshot['meta']['last_attempt_at'] = now()->toISOString();
            $cachedSnapshot['meta']['served_from_cache'] = true;

            return $cachedSnapshot;
        }

        $snapshot = $this->prepareSnapshotRx($olt, $snapshot, $cachedSnapshot);
        $snapshot['matched_customers'] = $this->syncSnapshotToCustomers($olt, $snapshot);
        $snapshot['meta']['matched_customers_count'] = count($snapshot['matched_customers']);
        $snapshot['meta']['updated_customers_count'] = collect($snapshot['matched_customers'])
            ->where('changed', true)
            ->count();

        if (Schema::hasColumn('olts', 'last_gpon_snapshot') && Schema::hasColumn('olts', 'last_gpon_synced_at')) {
            $olt->forceFill([
                'last_gpon_snapshot' => $this->redactCommandOutputs($snapshot),
                'last_gpon_synced_at' => now(),
            ])->save();
        }

        return $snapshot;
    }

    protected function shouldUseEnterpriseRefresh(Olt $olt): bool
    {
        return OltVendor::isEnterprise($olt) && Schema::hasTable('olt_onus');
    }

    protected function refreshEnterpriseSnapshotForOlt(Olt $olt, array $cachedSnapshot): array
    {
        try {
            $snapshot = app(OltCollectorService::class)->runInteractiveRefresh($olt);
        } catch (Throwable $e) {
            if (! empty($cachedSnapshot['onus'])) {
                $cachedSnapshot['meta']['warning'] = 'Refresh enterprise gagal, menampilkan snapshot terakhir.';
                $cachedSnapshot['meta']['last_error'] = $e->getMessage();
                $cachedSnapshot['meta']['last_attempt_at'] = now()->toISOString();
                $cachedSnapshot['meta']['served_from_cache'] = true;

                return $cachedSnapshot;
            }

            throw $e;
        }

        if (empty($snapshot['onus']) && ! empty($cachedSnapshot['onus'])) {
            $cachedSnapshot['meta']['warning'] = 'SNMP refresh tidak mengembalikan ONU, menampilkan snapshot terakhir.';
            $cachedSnapshot['meta']['last_attempt_at'] = now()->toISOString();
            $cachedSnapshot['meta']['served_from_cache'] = true;

            return $cachedSnapshot;
        }

        return $snapshot;
    }

    protected function appendOltSyncMeta(Olt $olt, array $meta): array
    {
        if (! Schema::hasColumn('olts', 'sync_status')) {
            return $meta;
        }

        $status = (string) ($olt->sync_status ?? 'idle');
        $meta['sync_status'] = $status;
        $meta['collector_status'] ??= $status;
        $meta['sync_in_progress'] = in_array($status, ['queued', 'running'], true);
        $meta['last_error_code'] ??= filled($olt->last_sync_error ?? null) ? 'collector_failed' : null;
        $meta['stale'] = (bool) ($meta['stale'] ?? false)
            || in_array($status, ['queued', 'running', 'failed'], true);
        $meta['last_error'] ??= $olt->last_sync_error;

        if ($meta['sync_in_progress']) {
            $meta['refresh_queued'] = true;
        }

        return $meta;
    }

    protected function recoverStaleOltSyncStatus(Olt $olt): void
    {
        if (! Schema::hasColumn('olts', 'sync_status')) {
            return;
        }

        $status = (string) ($olt->sync_status ?? 'idle');
        if (! in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $reference = $olt->updated_at ?? $olt->last_gpon_synced_at;
        if ($reference && $reference->gte(now()->subMinutes(20))) {
            return;
        }

        $olt->forceFill([
            'sync_status' => 'failed',
            'last_sync_error' => 'Sync tertahan lebih dari 20 menit — status direset otomatis. Silakan refresh lagi.',
        ])->save();
    }

    protected function preserveCachedRxPower(array $snapshot, array $cachedSnapshot): array
    {
        return $this->preserveCachedOnuMetadata($snapshot, $cachedSnapshot);
    }

    protected function preserveCachedOnuMetadata(array $snapshot, array $cachedSnapshot, bool $preserveRx = true): array
    {
        if (empty($snapshot['onus']) || empty($cachedSnapshot['onus'])) {
            return $snapshot;
        }

        $cachedByRef = [];
        foreach ($cachedSnapshot['onus'] as $cachedOnu) {
            $ref = $this->normalizeOnuRefLabel($cachedOnu['onu_ref'] ?? null);
            if (! $ref) {
                continue;
            }

            $cachedByRef[$ref] = [
                'rx_power_dbm' => (($cachedOnu['rx_power_dbm'] ?? null) !== null && (float) $cachedOnu['rx_power_dbm'] <= 0)
                    ? (float) $cachedOnu['rx_power_dbm']
                    : null,
                'serial_number' => $cachedOnu['serial_number'] ?? null,
                'onu_name' => $cachedOnu['onu_name'] ?? null,
            ];
        }

        if ($cachedByRef === []) {
            return $snapshot;
        }

        $preservedRx = 0;
        $preservedSerial = 0;
        $preservedName = 0;

        foreach ($snapshot['onus'] as &$onu) {
            $ref = $this->normalizeOnuRefLabel($onu['onu_ref'] ?? null);
            if (! $ref || ! array_key_exists($ref, $cachedByRef)) {
                continue;
            }

            $cached = $cachedByRef[$ref];

            if ($preserveRx && ($onu['rx_power_dbm'] ?? null) === null && $cached['rx_power_dbm'] !== null) {
                $onu['rx_power_dbm'] = $cached['rx_power_dbm'];
                $preservedRx++;
            }

            if (empty($onu['serial_number']) && ! empty($cached['serial_number'])) {
                $onu['serial_number'] = $cached['serial_number'];
                $preservedSerial++;
            }

            if ((empty($onu['onu_name']) || $this->isGenericOnuName($onu['onu_name'] ?? null))
                && ! empty($cached['onu_name'])
                && ! $this->isGenericOnuName($cached['onu_name'])) {
                $onu['onu_name'] = $cached['onu_name'];
                $preservedName++;
            }
        }
        unset($onu);

        if ($preservedRx > 0) {
            $snapshot['meta']['rxpower_preserved_from_cache'] = $preservedRx;
            $snapshot['meta']['rxpower_synced_at'] = $cachedSnapshot['meta']['rxpower_synced_at'] ?? $snapshot['meta']['rxpower_synced_at'] ?? null;
            $snapshot['meta']['rxpower_stats'] = $cachedSnapshot['meta']['rxpower_stats'] ?? $snapshot['meta']['rxpower_stats'] ?? null;
        }

        if ($preservedSerial > 0) {
            $snapshot['meta']['serial_preserved_from_cache'] = $preservedSerial;
        }

        if ($preservedName > 0) {
            $snapshot['meta']['name_preserved_from_cache'] = $preservedName;
        }

        return $snapshot;
    }

    public function prepareSnapshotRx(Olt $olt, array $snapshot, ?array $cachedSnapshot = null): array
    {
        $isEnterprise = ($snapshot['meta']['collector'] ?? '') === 'olt_onus';

        if ($cachedSnapshot) {
            $snapshot = $this->preserveCachedOnuMetadata($snapshot, $cachedSnapshot, preserveRx: ! $isEnterprise);
        }

        if ($isEnterprise) {
            return $snapshot;
        }

        return $this->restoreSnapshotFromCustomers($olt, $snapshot);
    }

    protected function restoreSnapshotRxPowerFromCustomers(Olt $olt, array $snapshot): array
    {
        return $this->restoreSnapshotFromCustomers($olt, $snapshot);
    }

    protected function restoreSnapshotFromCustomers(Olt $olt, array $snapshot): array
    {
        if (empty($snapshot['onus'])) {
            return $snapshot;
        }

        $customers = Customer::query()
            ->where('olt_id', $olt->id)
            ->where(function ($query) {
                $query->whereNotNull('onu_rx_power_dbm')
                    ->orWhereNotNull('onu_serial')
                    ->orWhereNotNull('pppoe_user');
            })
            ->get(['pppoe_user', 'olt_port_label', 'onu_serial', 'onu_rx_power_dbm']);

        if ($customers->isEmpty()) {
            return $snapshot;
        }

        $byRef = [];
        $byPppoe = [];
        $bySerial = [];

        foreach ($customers as $customer) {
            $payload = [
                'rx' => ($customer->onu_rx_power_dbm !== null && (float) $customer->onu_rx_power_dbm <= 0)
                    ? (float) $customer->onu_rx_power_dbm
                    : null,
                'serial' => $customer->onu_serial,
                'name' => $customer->pppoe_user,
            ];

            $ref = $this->normalizeOnuRefLabel($customer->olt_port_label);
            if ($ref) {
                $byRef[$ref] = $payload;
            }

            $pppoe = $this->normalizeIdentifier($customer->pppoe_user);
            if ($pppoe) {
                $byPppoe[$pppoe] = $payload;
            }

            $serial = $this->normalizeSerial($customer->onu_serial);
            if ($serial) {
                $bySerial[$serial] = $payload;
            }
        }

        $restoredRx = 0;
        $restoredSerial = 0;
        $restoredName = 0;

        foreach ($snapshot['onus'] as &$onu) {
            $ref = $this->normalizeOnuRefLabel($onu['onu_ref'] ?? null);
            $pppoe = $this->normalizeIdentifier($onu['onu_name'] ?? null);
            $serial = $this->normalizeSerial($onu['serial_number'] ?? null);

            $customerData = null;
            if ($ref && array_key_exists($ref, $byRef)) {
                $customerData = $byRef[$ref];
            } elseif ($serial && array_key_exists($serial, $bySerial)) {
                $customerData = $bySerial[$serial];
            } elseif ($pppoe && array_key_exists($pppoe, $byPppoe)) {
                $customerData = $byPppoe[$pppoe];
            }

            if ($customerData === null) {
                continue;
            }

            if (($onu['rx_power_dbm'] ?? null) === null && $customerData['rx'] !== null) {
                $onu['rx_power_dbm'] = $customerData['rx'];
                $restoredRx++;
            }

            if (empty($onu['serial_number']) && ! empty($customerData['serial'])) {
                $onu['serial_number'] = $customerData['serial'];
                $restoredSerial++;
            }

            if ((empty($onu['onu_name']) || $this->isGenericOnuName($onu['onu_name'] ?? null))
                && ! empty($customerData['name'])
                && ! $this->isGenericOnuName($customerData['name'])) {
                $onu['onu_name'] = $customerData['name'];
                $restoredName++;
            }
        }
        unset($onu);

        if ($restoredRx > 0) {
            $snapshot['meta']['rxpower_restored_from_customers'] = $restoredRx;
            $snapshot['meta']['rxpower_synced_at'] = $snapshot['meta']['rxpower_synced_at'] ?? now()->toISOString();
        }

        if ($restoredSerial > 0) {
            $snapshot['meta']['serial_restored_from_customers'] = $restoredSerial;
        }

        if ($restoredName > 0) {
            $snapshot['meta']['name_restored_from_customers'] = $restoredName;
        }

        if ($restoredRx > 0 || $restoredSerial > 0 || $restoredName > 0) {
            if (Schema::hasColumn('olts', 'last_gpon_snapshot')) {
                $olt->forceFill(['last_gpon_snapshot' => $snapshot])->save();
            }
        }

        return $snapshot;
    }

    protected function isGenericOnuName(?string $name): bool
    {
        if (! $name) {
            return true;
        }

        return (bool) preg_match('/^ONU-\d+:\d+$/i', trim($name));
    }

    public function onuDetail(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'onu_ref' => ['required', 'string', 'max:64'],
        ]);

        return $this->runOltOperation($olt, 'onu_detail', $validated['onu_ref'], $validated, function () use ($olt, $validated) {
            $result = $this->oltService($olt)->onuDetail($olt, $validated['onu_ref']);

            return $this->enrichOnuDetailResponse($olt, $validated['onu_ref'], $result);
        });
    }

    /**
     * Normalize live detail: RX-based online, DB fallback for SN/name, attach matched customer.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function enrichOnuDetailResponse(Olt $olt, string $onuRef, array $result): array
    {
        $ref = $this->normalizeOnuRefLabel($onuRef) ?? strtolower(trim($onuRef));
        $onu = is_array($result['onu'] ?? null) ? $result['onu'] : [];

        $db = null;
        if (Schema::hasTable('olt_onus')) {
            $db = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) = ?', [$ref])
                ->first();
        }

        if ($db) {
            if (empty($onu['serial_number']) && $db->serial_number) {
                $onu['serial_number'] = $db->serial_number;
            }
            if (empty($onu['onu_name']) && $db->onu_name) {
                $onu['onu_name'] = $db->onu_name;
            }
            if (($onu['distance_m'] ?? null) === null && $db->distance_m !== null) {
                $onu['distance_m'] = $db->distance_m;
            }
            if (($onu['rx_power_dbm'] ?? null) === null && $db->rx_power_dbm !== null) {
                $onu['rx_power_dbm'] = (float) $db->rx_power_dbm;
            }
            if (empty($onu['phase_state']) && $db->phase_state) {
                $onu['phase_state'] = $db->phase_state;
            }
        }

        $rx = isset($onu['rx_power_dbm']) ? (float) $onu['rx_power_dbm'] : null;
        $onu['state'] = OltOnu::normalizeOperationalState(
            $onu['state'] ?? null,
            $onu['phase_state'] ?? null,
            $rx
        );
        $onu['onu_ref'] = $onu['onu_ref'] ?? $ref;
        $result['onu'] = $onu;
        $result['onu_ref'] = $ref;

        // Matched customer for "Buka profil" (same keys as snapshot matched_customers)
        if (empty($result['matched'])) {
            $customer = Customer::query()
                ->where('olt_id', $olt->id)
                ->where(function ($q) use ($ref, $onu) {
                    $q->whereRaw('LOWER(olt_port_label) = ?', [$ref]);
                    $sn = isset($onu['serial_number']) ? strtoupper(trim((string) $onu['serial_number'])) : '';
                    if ($sn !== '') {
                        $q->orWhereRaw('UPPER(onu_serial) = ?', [$sn]);
                    }
                    $name = isset($onu['onu_name']) ? mb_strtolower(trim((string) $onu['onu_name'])) : '';
                    if ($name !== '') {
                        $q->orWhereRaw('LOWER(pppoe_user) = ?', [$name]);
                    }
                })
                ->first(['id', 'name', 'pppoe_user']);

            if ($customer) {
                $result['matched'] = [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'onu_ref' => $ref,
                    'rx_power_dbm' => $rx,
                    'distance_m' => $onu['distance_m'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * Rename ONU on OLT chassis (CLI name) + ALWAYS sync GenieACS primary tag to same name.
     * Body: onu_ref OR sn, name
     */
    public function renameOnu(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'onu_ref' => ['nullable', 'string', 'max:64'],
            'sn' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:64'],
            // accepted but ignored — ACS sync is always on
            'sync_acs' => ['nullable', 'boolean'],
        ]);

        $name = $this->normalizeOnuDisplayName($validated['name']);
        if ($name === '') {
            return response()->json(['success' => false, 'error' => 'Nama ONU kosong / tidak valid.'], 422);
        }

        $onuRef = trim((string) ($validated['onu_ref'] ?? ''));
        $sn = strtoupper(preg_replace('/\s+/', '', (string) ($validated['sn'] ?? '')) ?? '');

        // Prefer SN when present: it is safer than a stale/clicked ref on duplicate-looking names.
        if ($sn !== '' && Schema::hasTable('olt_onus')) {
            $row = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where(function ($q) use ($sn) {
                    $q->whereRaw('UPPER(serial_number) = ?', [$sn])
                        ->orWhereRaw("UPPER(REPLACE(serial_number, ' ', '')) = ?", [$sn]);
                })
                ->orderByDesc('id')
                ->first();
            if ($row && ! empty($row->onu_ref)) {
                $onuRef = (string) $row->onu_ref;
            }
        }

        // Fill SN from inventory when only onu_ref given (for ACS tag)
        if ($onuRef !== '' && $sn === '' && Schema::hasTable('olt_onus')) {
            $normRef = strtolower(preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef);
            $row = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) = ?', [$normRef])
                ->first();
            if ($row && ! empty($row->serial_number)) {
                $sn = strtoupper((string) $row->serial_number);
            }
        }

        if ($onuRef === '') {
            return response()->json([
                'success' => false,
                'error' => 'Isi ONU ref (contoh 1/3/3:40) atau SN yang sudah ada di inventory OLT.',
            ], 422);
        }

        $validated['onu_ref'] = $onuRef;
        $validated['name'] = $name;
        $validated['sn'] = $sn;
        $validated['sync_acs'] = true;

        if ($this->isHuaweiOlt($olt)) {
            return $this->huaweiRenameOnu($request, $olt, $onuRef, $sn, $name, $validated);
        }

        if ($blocked = $this->provisioningGate($olt, $request, 'rename-onu')) {
            return $blocked;
        }

        return $this->runOltOperation($olt, 'rename_onu', $onuRef, $validated, function () use ($olt, $onuRef, $name, $sn) {
            $result = $this->oltService($olt)->renameOnu($olt, $onuRef, $name);

            // Inventory eBilling
            $invUpdated = false;
            if (Schema::hasTable('olt_onus')) {
                $normRef = strtolower(preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef);
                $invUpdated = OltOnu::query()
                    ->where('olt_id', $olt->id)
                    ->whereRaw('LOWER(onu_ref) = ?', [$normRef])
                    ->update([
                        'onu_name' => $name,
                        'name_source' => 'manual_rename',
                        'updated_at' => now(),
                    ]) > 0;
            }

            // ALWAYS rename ACS primary tag to match OLT name (prune old tags via pushTagForSerial)
            $acs = $this->syncAcsTagAfterOltRename($sn, $name);

            $acsLabel = ($acs['pushed'] ?? false)
                ? 'ACS tag OK'
                : (($acs['queued'] ?? false) ? 'ACS queued' : 'ACS pending');

            return array_merge(is_array($result) ? $result : ['success' => true], [
                'success' => true,
                'name' => $name,
                'onu_ref' => $onuRef,
                'sn' => $sn !== '' ? $sn : null,
                'inventory_updated' => $invUpdated,
                'acs_tag' => $acs,
                'acs_synced' => (bool) ($acs['pushed'] ?? false),
                'message' => "ONU {$onuRef} → name {$name}"
                    .($invUpdated ? ' · inventory OK' : '')
                    .' · '.$acsLabel,
            ]);
        });
    }

    /**
     * Force ACS primary tag = OLT name. Queues retry if device not in ACS yet.
     *
     * @return array<string, mixed>
     */
    protected function syncAcsTagAfterOltRename(string $sn, string $name): array
    {
        if ($sn === '') {
            return [
                'pushed' => false,
                'skipped' => true,
                'message' => 'SN tidak diketahui — isi SN agar tag ACS ikut diganti',
            ];
        }

        try {
            $genie = app(GenieAcsService::class);
            $acs = $genie->pushTagForSerial($sn, $name);
            $acs['serial'] = $sn;
            $acs['tag'] = $name;
            $acs['auto'] = true;

            if (! empty($acs['pushed'])) {
                return $acs;
            }

            // Device belum inform / tag gagal — antrikan retry (sama post-provision)
            try {
                ProcessGenieAcsInformJob::dispatch([
                    'serial' => $sn,
                    'tag' => $name,
                    'event' => 'olt_rename',
                ])->delay(now()->addSeconds(5));
                $acs['queued'] = true;
                $acs['message'] = $acs['message']
                    ?? 'Tag ACS dijadwalkan (device mungkin belum inform)';
            } catch (Throwable $dispatchErr) {
                $acs['queued'] = false;
                $acs['message'] = ($acs['message'] ?? 'ACS pending').' · queue: '.$dispatchErr->getMessage();
            }

            return $acs;
        } catch (Throwable $e) {
            return [
                'pushed' => false,
                'pending' => true,
                'queued' => false,
                'serial' => $sn,
                'tag' => $name,
                'auto' => true,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Lookup ONU by SN or ref for rename UI (read-only).
     */
    public function lookupOnuForRename(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'sn' => ['nullable', 'string', 'max:64'],
            'onu_ref' => ['nullable', 'string', 'max:64'],
        ]);
        $sn = strtoupper(preg_replace('/\s+/', '', (string) ($validated['sn'] ?? '')) ?? '');
        $onuRef = trim((string) ($validated['onu_ref'] ?? ''));

        if ($sn === '' && $onuRef === '') {
            return response()->json(['success' => false, 'error' => 'Isi SN atau ONU ref.'], 422);
        }

        $row = null;
        if (Schema::hasTable('olt_onus')) {
            $q = OltOnu::query()->where('olt_id', $olt->id);
            if ($sn !== '') {
                $row = (clone $q)->whereRaw("UPPER(REPLACE(serial_number, ' ', '')) = ?", [$sn])->orderByDesc('id')->first();
            }
            if (! $row && $onuRef !== '') {
                $normRef = strtolower(preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef);
                $row = (clone $q)->whereRaw('LOWER(onu_ref) = ?', [$normRef])->first();
            }
        }

        if (! $row) {
            return response()->json([
                'success' => false,
                'error' => 'ONU tidak ketemu di inventory eBilling. Isi ONU ref manual (contoh 1/3/3:40) dari OLT.',
                'hint' => 'Inventory diisi lewat sync OLT / setelah provision.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'onu_ref' => $row->onu_ref,
            'sn' => $row->serial_number,
            'onu_name' => $row->onu_name,
            'pon_port' => $row->pon_port ?? null,
            'status' => $row->status ?? null,
            'rx_power_dbm' => $row->rx_power_dbm ?? null,
        ]);
    }

    /** Normalize like GenieACS / PSB: UPPER + underscore, alnum only. */
    protected function normalizeOnuDisplayName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = str_replace([' ', '-'], '_', $name);
        $name = preg_replace('/[^A-Z0-9_.]/', '', $name) ?? '';
        $name = preg_replace('/_+/', '_', $name) ?? $name;

        return trim($name, '_');
    }

    public function rebootOnu(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'onu_ref' => ['required', 'string', 'max:64'],
        ]);

        if ($this->isHuaweiOlt($olt)) {
            return $this->huaweiRebootOnu($request, $olt, $validated);
        }

        if ($blocked = $this->provisioningGate($olt, $request, 'reboot-onu')) {
            return $blocked;
        }

        return $this->runOltOperation($olt, 'reboot_onu', $validated['onu_ref'], $validated, function () use ($olt, $validated) {
            return $this->oltService($olt)->rebootOnu($olt, $validated['onu_ref']);
        });
    }

    public function setOnuAdminState(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'onu_ref' => ['required', 'string', 'max:64'],
            'enabled' => ['required', 'boolean'],
        ]);

        $operation = $validated['enabled'] ? 'enable_onu' : 'disable_onu';

        if ($blocked = $this->provisioningGate($olt, $request, 'admin-state')) {
            return $blocked;
        }

        return $this->runOltOperation($olt, $operation, $validated['onu_ref'], $validated, function () use ($olt, $validated) {
            return $this->oltService($olt)->setOnuAdminState($olt, $validated['onu_ref'], (bool) $validated['enabled']);
        });
    }

    public function deleteOnu(Request $request, Olt $olt)
    {
        if ($request->isJson() && is_array($request->json()->all())) {
            $request->merge($request->json()->all());
        }

        if (! $request->filled('onu_ref') && $request->query('onu_ref')) {
            $request->merge(['onu_ref' => $request->query('onu_ref')]);
        }

        $validated = $request->validate([
            'onu_ref' => ['required', 'string', 'max:64'],
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'onu_ref' => 'ONU port',
        ]);

        $huawei = $this->isHuaweiOlt($olt);
        if ($huawei && ($blocked = $this->huaweiWriteGate($request, $olt))) {
            return $blocked;
        }

        if ($blocked = $this->provisioningGate($olt, $request, 'delete-onu')) {
            return $blocked;
        }

        $huaweiSerial = null;
        if ($huawei) {
            $huaweiSerial = $this->huaweiInventorySerial($olt, $validated['onu_ref']);
            if ($huaweiSerial === null) {
                return $this->huaweiSerialUnknownResponse();
            }
        }

        // Roadmap 1.3 lifecycle guard: PPPoE active / ONU working / traffic in
        // the last 2 days / unknown => refused; only a superadmin with a written
        // reason may override (UI only, logged to the activity log).
        $override = (bool) ($validated['override'] ?? false);
        $reason = trim((string) ($validated['override_reason'] ?? ''));
        unset($validated['override'], $validated['override_reason']);

        $guard = app(CustomerLifecycleGuard::class);
        $context = GuardContext::manual('olt_ui_delete_onu');
        $decision = $this->evaluateOnuDeleteGuard($olt, $validated['onu_ref'], $guard, $context);
        if (! $decision->allowed()) {
            $actor = $request->user();
            $canOverride = $actor !== null && $actor->isSuperAdmin();
            if (! $override) {
                return $this->guardDeniedResponse($decision, $canOverride, 'lifecycle_guard_denied', 409);
            }

            $auth = $guard->authorizeOverride($actor, $reason, $decision, $context);
            if (! $auth['ok']) {
                $status = $auth['error'] === 'superadmin_required' ? 403 : 422;
                $message = match ($auth['error']) {
                    'superadmin_required' => 'Hanya superadmin yang boleh override pagar ONU.',
                    'reason_too_short' => 'Alasan override minimal '.(int) config('lifecycle_guard.override_reason_min', 20).' karakter.',
                    default => 'Override tidak diizinkan.',
                };

                return $this->guardDeniedResponse($decision, $canOverride, 'lifecycle_guard_override_'.$auth['error'], $status, $message);
            }

            activity()
                ->causedBy($actor)
                ->performedOn($olt)
                ->event('olt_onu_delete_guard_override')
                ->withProperties([
                    'onu_ref' => $validated['onu_ref'],
                    'reason' => $auth['reason'],
                    'guard' => $decision->toArray(),
                ])
                ->log('Superadmin override pagar ONU: hapus ONU '.$validated['onu_ref'].' di '.$olt->name);
            $validated['guard_override'] = true;
        }

        if ($huawei) {
            return $this->huaweiDeleteOnu($request, $olt, $validated, (string) $huaweiSerial);
        }

        return $this->runOltOperation($olt, 'delete_onu', $validated['onu_ref'], $validated, function () use ($olt, $validated) {
            $result = $this->oltService($olt)->deleteOnu($olt, $validated['onu_ref']);
            $result['db_purged'] = $this->purgeOnuFromDatabase($olt, $validated['onu_ref']);
            $result['snapshot_purged'] = $this->purgeOnuFromSnapshotCaches($olt, $validated['onu_ref']);
            $result['gpon_snapshot'] = $this->resolvePurgedSnapshotForClient($olt, $validated['onu_ref']);

            return $result;
        });
    }

    /**
     * Owners of the ONU at $onuRef: every customer (soft-deleted and terminated
     * included, hex/ASCII-aware) of the live serial AND the inventory serial.
     * Guard verdict = worst over all of them. No serial / any failure = unknown.
     */
    private function evaluateOnuDeleteGuard(Olt $olt, string $onuRef, CustomerLifecycleGuard $guard, GuardContext $context): GuardDecision
    {
        $unknown = static fn (string $message): GuardDecision => GuardDecision::fromSignals('delete_onu_manual', [
            GuardSignal::unknown(GuardSignal::ONU, 'onu_unknown', $message),
        ]);

        try {
            $vendor = strtolower(trim((string) ($olt->vendor ?? '')));
            $hasLiveRead = $vendor === '' || ! (str_contains($vendor, 'hioso') || str_contains($vendor, 'htb'));
            $liveRead = $hasLiveRead ? app(OnuLiveReadService::class)->read($olt, $onuRef) : null;

            $serials = [];
            if (Schema::hasTable('olt_onus')) {
                $inventory = CustomerLifecycleGuard::normalizeSerial(
                    OltOnu::query()->where('olt_id', $olt->id)->whereRaw('LOWER(onu_ref) = ?', [strtolower(trim($onuRef))])->value('serial_number')
                );
                if ($inventory !== '') {
                    $serials[$inventory] = true;
                }
            }
            if ($liveRead !== null && ($liveRead['ok'] ?? false)) {
                $live = CustomerLifecycleGuard::normalizeSerial($liveRead['serial'] ?? null);
                if ($live !== '') {
                    $serials[$live] = true;
                }
            }
            if ($serials === []) {
                return $unknown('Serial ONU tidak dapat ditentukan; pemilik ONU tidak diketahui.');
            }

            $customers = [];
            foreach (array_keys($serials) as $serial) {
                foreach (CustomerLifecycleGuard::ownersOfSerial($serial) as $owner) {
                    $customers[$owner->id] = $owner;
                }
            }

            $decisions = [];
            foreach ($customers === [] ? [null] : array_values($customers) as $customer) {
                $decisions[] = $guard->canDeleteOnuRef(CustomerLifecycleGuard::MODE_MANUAL, $customer, $liveRead, $context, null, $olt->vendor);
            }

            return CustomerLifecycleGuard::worst($decisions);
        } catch (Throwable $e) {
            Log::warning('[OLT] delete-onu guard failed', ['olt_id' => $olt->id, 'exception_class' => $e::class]);

            return $unknown('Pagar ONU gagal memeriksa status ONU/pemilik; ONU tidak dihapus.');
        }
    }

    private function guardDeniedResponse(GuardDecision $decision, bool $canOverride, string $code, int $status, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => $code,
            'error' => $message ?? ('ONU tidak dihapus: '.$decision->message()),
            'message' => $message ?? ('ONU tidak dihapus: '.$decision->message()),
            'guard' => [
                'verdict' => $decision->verdict,
                'reasons' => $decision->reasonCodes(),
                'message' => $decision->message(),
            ],
            'can_override' => $canOverride,
            'override_reason_min' => (int) config('lifecycle_guard.override_reason_min', 20),
        ], $status);
    }

    protected function resolvePurgedSnapshotForClient(Olt $olt, string $purgedOnuRef): array
    {
        $olt->refresh();
        $ref = $this->normalizeOnuRefKey($purgedOnuRef);
        $snapshot = $this->snapshotArray($olt->last_gpon_snapshot);

        if (empty($snapshot['onus']) && Schema::hasTable('olt_onus')) {
            $snapshot = app(OltSnapshotBuilder::class)->build($olt);
        }

        if ($ref !== '') {
            $updated = $this->removeOnuFromSnapshotArray($snapshot, $ref);
            if ($updated !== null) {
                $snapshot = $updated;
            }
        }

        $snapshot['meta'] ??= [];
        $snapshot['meta']['served_from_cache'] = true;
        $snapshot['meta']['purged_onu_ref'] = $ref;
        $snapshot['meta']['last_attempt_at'] = now()->toISOString();

        return $this->redactCommandOutputs($snapshot);
    }

    public function purgeOnuFromSnapshotCaches(Olt $olt, string $onuRef): int
    {
        $ref = $this->normalizeOnuRefKey($onuRef);
        if ($ref === '') {
            return 0;
        }

        $purged = 0;

        if (Schema::hasColumn('olts', 'last_gpon_snapshot')) {
            $snapshot = $this->snapshotArray($olt->last_gpon_snapshot);
            $updated = $this->removeOnuFromSnapshotArray($snapshot, $ref);
            if ($updated !== null) {
                $olt->forceFill(['last_gpon_snapshot' => $this->redactCommandOutputs($updated)])->save();
                $purged++;
            }
        }

        return $purged;
    }

    protected function removeOnuFromSnapshotArray(array $snapshot, string $ref): ?array
    {
        $onus = $snapshot['onus'] ?? [];
        if (! is_array($onus) || $onus === []) {
            return null;
        }

        $filteredOnus = array_values(array_filter(
            $onus,
            fn ($onu) => $this->normalizeOnuRefKey(is_array($onu) ? ($onu['onu_ref'] ?? '') : '') !== $ref
        ));

        if (count($filteredOnus) === count($onus)) {
            return null;
        }

        $snapshot['onus'] = $filteredOnus;

        if (! empty($snapshot['matched_customers']) && is_array($snapshot['matched_customers'])) {
            $snapshot['matched_customers'] = array_values(array_filter(
                $snapshot['matched_customers'],
                fn ($customer) => $this->normalizeOnuRefKey(is_array($customer) ? ($customer['onu_ref'] ?? '') : '') !== $ref
            ));
        }

        $snapshot['meta'] ??= [];
        $snapshot['meta']['onus_count'] = count($filteredOnus);
        $snapshot['meta']['matched_customers_count'] = count($snapshot['matched_customers'] ?? []);
        $snapshot['meta']['purged_onu_ref'] = $ref;
        $snapshot['meta']['purged_at'] = now()->toISOString();

        return $snapshot;
    }

    protected function normalizeOnuRefKey(?string $onuRef): string
    {
        $ref = strtolower(preg_replace('/^gpon-onu_/i', '', trim((string) $onuRef)) ?? '');

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $ref) ? $ref : '';
    }

    protected function purgeOnuFromDatabase(Olt $olt, string $onuRef): int
    {
        if (! Schema::hasTable('olt_onus')) {
            return 0;
        }

        $ref = $this->normalizeOnuRefKey($onuRef);
        if ($ref === '') {
            return 0;
        }

        return OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereRaw('LOWER(onu_ref) = ?', [$ref])
            ->delete();
    }

    public function authorizeOnu(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'pon_port' => ['required', 'string', 'max:64'],
            'onu_id' => ['required', 'integer', 'min:1', 'max:128'],
            'serial_number' => ['required', 'string', 'max:64'],
            'onu_type' => ['nullable', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:255'],
            'line_profile' => ['nullable', 'string', 'max:128'],
            'service_profile' => ['nullable', 'string', 'max:128'],
        ]);

        $onuRef = "{$validated['pon_port']}:{$validated['onu_id']}";

        if ($blocked = $this->provisioningGate($olt, $request, 'authorize-onu')) {
            return $blocked;
        }

        return $this->runOltOperation($olt, 'authorize_onu', $onuRef, $validated, function () use ($olt, $validated) {
            return $this->oltService($olt)->authorizeOnu($olt, $validated);
        });
    }

    protected function defaultPortForProtocol(string $protocol): int
    {
        return match ($protocol) {
            'telnet' => 23,
            'snmp' => 161,
            'http' => 80,
            'https' => 443,
            default => 22,
        };
    }

    protected function normalizedPayload(array $payload): array
    {
        $protocol = $payload['management_protocol'] ?? null;
        if ($protocol && empty($payload['management_port'])) {
            $payload['management_port'] = $this->defaultPortForProtocol($protocol);
        }

        $payload['vendor'] = $payload['vendor'] ?? 'zte_c300';

        return $payload;
    }

    protected function serializeOlt(Olt $olt, bool $includePasswordField = false): array
    {
        $hasCustomerGroups = $olt->relationLoaded('customers');

        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'code' => $olt->code,
            'vendor' => $olt->vendor ?? 'zte_c300',
            'area_id' => $olt->area_id,
            'router_id' => $olt->router_id,
            'management_ip' => $olt->management_ip,
            'management_protocol' => $olt->management_protocol,
            'management_port' => $olt->management_port,
            'username' => $olt->username,
            'password' => $includePasswordField ? '' : null,
            'snmp_community' => null,
            'snmp_community_configured' => filled($olt->snmp_community),
            'location' => $olt->location,
            'notes' => $olt->notes,
            'area' => $olt->area ? ['id' => $olt->area->id, 'name' => $olt->area->name] : null,
            'router' => $olt->router ? ['id' => $olt->router->id, 'name' => $olt->router->name] : null,
            'customer_count' => $hasCustomerGroups ? $olt->customers->count() : 0,
            'pon_port_groups' => $hasCustomerGroups ? $this->serializePonPortGroups($olt) : [],
            'last_gpon_snapshot' => null,
            'last_gpon_synced_at' => $olt->last_gpon_synced_at?->toISOString(),
            'gpon_onus_count' => $this->gponOnusCount($olt),
            'sync_status' => $olt->sync_status ?? 'idle',
            'last_sync_error' => $olt->last_sync_error,
        ];
    }

    protected function serializePonPortGroups(Olt $olt): array
    {
        $customersByPon = $olt->customers
            ->groupBy(fn ($customer) => $this->normalizePonPort($customer->olt_port_label) ?: 'unassigned');

        $groups = collect($this->gponPonPorts())->map(function (array $port) use ($customersByPon) {
            return [
                'label' => $port['label'],
                'value' => $port['value'],
                'customers' => $customersByPon->get($port['label'], collect())->map(fn ($customer) => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'status' => $customer->status,
                    'olt_port_label' => $customer->olt_port_label,
                    'onu_serial' => $customer->onu_serial,
                    'olt_status' => $customer->olt_status,
                    'onu_rx_power_dbm' => $customer->onu_rx_power_dbm,
                    'onu_tx_power_dbm' => $customer->onu_tx_power_dbm,
                    'fiber_distance_m' => $customer->fiber_distance_m,
                    'olt_last_synced_at' => $customer->olt_last_synced_at?->toISOString(),
                ])->values(),
            ];
        });

        $unassigned = $customersByPon->get('unassigned', collect());
        if ($unassigned->isNotEmpty()) {
            $groups->push([
                'label' => 'Unassigned',
                'value' => '',
                'customers' => $unassigned->map(fn ($customer) => [
                    'id' => $customer->id,
                    'code' => $customer->code,
                    'name' => $customer->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'status' => $customer->status,
                    'olt_port_label' => $customer->olt_port_label,
                    'onu_serial' => $customer->onu_serial,
                    'olt_status' => $customer->olt_status,
                    'onu_rx_power_dbm' => $customer->onu_rx_power_dbm,
                    'onu_tx_power_dbm' => $customer->onu_tx_power_dbm,
                    'fiber_distance_m' => $customer->fiber_distance_m,
                    'olt_last_synced_at' => $customer->olt_last_synced_at?->toISOString(),
                ])->values(),
            ]);
        }

        return $groups->values()->all();
    }

    protected function gponPonPorts(): array
    {
        $ports = [];

        foreach ([2, 3] as $slot) {
            for ($pon = 1; $pon <= 16; $pon++) {
                $label = "1/{$slot}/{$pon}";
                $ports[] = [
                    'label' => $label,
                    'value' => "gpon-olt_{$label}",
                ];
            }
        }

        return $ports;
    }

    protected function normalizePonPort(?string $portLabel): ?string
    {
        if (! $portLabel) {
            return null;
        }

        if (preg_match('/(?:gpon-(?:olt|onu)_)?(\d+\/\d+\/\d+)(?::\d+)?/i', $portLabel, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function syncSnapshotToCustomers(Olt $olt, array &$snapshot, bool $persist = true): array
    {
        $connectivity = app(CustomerConnectivityService::class);

        $customers = Customer::query()
            ->where(function ($query) use ($olt) {
                $query->where('olt_id', $olt->id);
                if ($olt->router_id) {
                    $query->orWhere(function ($unassigned) use ($olt) {
                        $unassigned->whereNull('olt_id')->where('router_id', $olt->router_id);
                    });
                }
            })
            ->where(function ($query) {
                $query->whereNotNull('pppoe_user')
                    ->orWhereNotNull('onu_serial');
            })
            ->get([
                'id',
                'code',
                'name',
                'pppoe_user',
                'olt_id',
                'olt_port_label',
                'onu_serial',
                'olt_status',
                'onu_rx_power_dbm',
                'fiber_distance_m',
                'olt_last_synced_at',
            ]);

        $customersByPppoe = $customers
            ->filter(fn (Customer $customer) => $this->normalizeIdentifier($customer->pppoe_user) !== null)
            ->keyBy(fn (Customer $customer) => $this->normalizeIdentifier($customer->pppoe_user));

        $customersByCode = $customers
            ->filter(fn (Customer $customer) => $this->normalizeIdentifier($customer->code) !== null)
            ->keyBy(fn (Customer $customer) => $this->normalizeIdentifier($customer->code));

        $customersBySerial = $customers
            ->filter(fn (Customer $customer) => $this->normalizeSerial($customer->onu_serial) !== null)
            ->keyBy(fn (Customer $customer) => $this->normalizeSerial($customer->onu_serial));

        $customersByOnuRef = $customers
            ->filter(fn (Customer $customer) => $this->normalizeOnuRefLabel($customer->olt_port_label) !== null)
            ->keyBy(fn (Customer $customer) => $this->normalizeOnuRefLabel($customer->olt_port_label));

        $matched = [];
        $matchedCustomerIds = [];
        $onus = collect($snapshot['onus'] ?? [])
            ->sortByDesc(fn (array $onu) => $connectivity->isPonOnline($onu['state'] ?? null) ? 1 : 0)
            ->values()
            ->all();

        foreach ($onus as &$onu) {
            $pppoeName = $this->normalizeIdentifier($onu['onu_name'] ?? null);
            $serial = $this->normalizeSerial($onu['serial_number'] ?? null);
            $onuRef = $this->normalizeOnuRefLabel($onu['onu_ref'] ?? null);
            $matchMethod = null;
            $ponOnline = $connectivity->isPonOnline($onu['state'] ?? null);
            $nameCandidates = array_values(array_unique(array_filter([
                $pppoeName,
                $pppoeName ? preg_replace('/^onu[-_]/', '', $pppoeName) : null,
            ])));

            $customer = null;
            foreach ($nameCandidates as $candidate) {
                if ($customersByPppoe->has($candidate)) {
                    $customer = $customersByPppoe->get($candidate);
                    $matchMethod = 'pppoe';
                    break;
                }
                if ($customersByCode->has($candidate)) {
                    $customer = $customersByCode->get($candidate);
                    $matchMethod = 'code';
                    break;
                }
            }

            if (! $customer && $serial && $customersBySerial->has($serial)) {
                /** @var Customer $customer */
                $customer = $customersBySerial->get($serial);
                $matchMethod = 'serial';
                $onu['onu_name'] = $onu['onu_name'] ?: $customer->pppoe_user;
            } elseif (! $customer && $onuRef && $customersByOnuRef->has($onuRef)) {
                /** @var Customer $refCustomer */
                $refCustomer = $customersByOnuRef->get($onuRef);
                $refIdentifiers = array_values(array_filter([
                    $this->normalizeIdentifier($refCustomer->pppoe_user),
                    $this->normalizeIdentifier($refCustomer->code),
                ]));
                $refSerial = $this->normalizeSerial($refCustomer->onu_serial);
                $nameConflict = $pppoeName && ! in_array($pppoeName, $refIdentifiers, true);
                $serialConflict = ! $pppoeName && $serial && $refSerial && $serial !== $refSerial;

                if ($nameConflict || $serialConflict) {
                    $onu['match_conflict'] = 'reused_onu_ref';

                    continue;
                }

                $customer = $refCustomer;
                $matchMethod = 'onu_ref';
                $onu['onu_name'] = $onu['onu_name'] ?: $customer->pppoe_user;
            } elseif (! $customer) {
                continue;
            }

            if (! $ponOnline && $customer->pppoe_user
                && $this->findOnlineOnuByPppoeInSnapshot($onus, $customer->pppoe_user) !== null) {
                continue;
            }

            if (! $ponOnline && isset($matchedCustomerIds[$customer->id])) {
                continue;
            }

            $onu['onu_name'] = $onu['onu_name'] ?: ($customer->pppoe_user ?: $customer->name);
            if (empty($onu['serial_number']) && $this->normalizeSerial($customer->onu_serial) !== null) {
                $onu['serial_number'] = $customer->onu_serial;
            }

            if (! $ponOnline) {
                $rxPower = null;
            } elseif (array_key_exists('rx_power_dbm', $onu)) {
                $rxPower = $onu['rx_power_dbm'];
            } else {
                $rxPower = $customer->onu_rx_power_dbm;
            }

            $customer->forceFill([
                'olt_id' => $olt->id,
                'olt_port_label' => $onu['onu_ref'] ?? null,
                'onu_serial' => $onu['serial_number'] ?? $customer->onu_serial,
                'olt_status' => $onu['state'] ?? $customer->olt_status,
                'onu_rx_power_dbm' => $rxPower,
                'fiber_distance_m' => $onu['distance_m'] ?? $customer->fiber_distance_m,
                'olt_last_synced_at' => now(),
            ]);

            $changed = $customer->isDirty([
                'olt_id',
                'olt_port_label',
                'onu_serial',
                'olt_status',
                'onu_rx_power_dbm',
                'fiber_distance_m',
            ]);

            if ($changed && $persist) {
                $customer->save();
            }

            $matchedCustomerIds[$customer->id] = true;

            $matched[] = [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'pppoe_user' => $customer->pppoe_user,
                'onu_ref' => $onu['onu_ref'] ?? null,
                'rx_power_dbm' => $onu['rx_power_dbm'] ?? null,
                'distance_m' => $onu['distance_m'] ?? null,
                'changed' => $changed,
                'match_method' => $matchMethod,
            ];
        }
        unset($onu);
        $snapshot['onus'] = $onus;

        return $matched;
    }

    /**
     * @param  list<array<string, mixed>>  $onus
     * @return array<string, mixed>|null
     */
    protected function findOnlineOnuByPppoeInSnapshot(array $onus, string $pppoeUser): ?array
    {
        $pppoe = $this->normalizeIdentifier($pppoeUser);
        if ($pppoe === null) {
            return null;
        }

        $connectivity = app(CustomerConnectivityService::class);

        foreach ($onus as $onu) {
            if ($this->normalizeIdentifier($onu['onu_name'] ?? null) !== $pppoe) {
                continue;
            }

            if ($connectivity->isPonOnline($onu['state'] ?? null)) {
                return $onu;
            }
        }

        return null;
    }

    protected function ensureSnapshotMatchedCustomers(Olt $olt, array $snapshot, array $candidates = []): array
    {
        if (! empty($snapshot['matched_customers'])) {
            $snapshot['meta']['matched_customers_count'] = count($snapshot['matched_customers']);

            return $snapshot;
        }

        foreach ($candidates as $candidate) {
            if (empty($candidate['matched_customers'])) {
                continue;
            }

            $merged = $this->mergeMatchedCustomersForSnapshot($snapshot, $candidate['matched_customers']);
            if ($merged !== []) {
                $snapshot['matched_customers'] = $merged;
                $snapshot['meta']['matched_customers_count'] = count($merged);
                $snapshot['meta']['matched_customers_source'] = 'merged_cache';

                return $snapshot;
            }
        }

        $snapshot['matched_customers'] = $this->syncSnapshotToCustomers($olt, $snapshot, persist: false);
        $snapshot['meta']['matched_customers_count'] = count($snapshot['matched_customers']);
        $snapshot['meta']['matched_customers_source'] = 'computed_readonly';

        return $snapshot;
    }

    /**
     * @param  list<array<string, mixed>>  $matchedCustomers
     * @return list<array<string, mixed>>
     */
    protected function mergeMatchedCustomersForSnapshot(array $snapshot, array $matchedCustomers): array
    {
        $refs = collect($snapshot['onus'] ?? [])
            ->pluck('onu_ref')
            ->filter()
            ->map(fn ($ref) => strtolower((string) $ref))
            ->flip();

        return collect($matchedCustomers)
            ->filter(fn (array $row) => isset($row['onu_ref']) && $refs->has(strtolower((string) $row['onu_ref'])))
            ->values()
            ->all();
    }

    protected function normalizeIdentifier(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);
        if ($value === '' || $value === '-') {
            return null;
        }

        return mb_strtolower($value);
    }

    protected function normalizeOnuRefLabel(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/^gpon-onu_/i', '', $value) ?? $value;

        return preg_match('/^\d+\/\d+\/\d+:\d+$/', $value) ? $value : null;
    }

    protected function normalizeSerial(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = strtoupper(trim($value));
        if ($value === '' || $value === '-') {
            return null;
        }

        return $value;
    }

    protected function runOltOperation(Olt $olt, string $operation, ?string $onuRef, array $payload, callable $callback)
    {
        try {
            $result = $callback();

            $this->writeOltOperationLog([
                'olt_id' => $olt->id,
                'user_id' => auth()->id(),
                'operation' => $operation,
                'onu_ref' => $onuRef,
                'status' => 'success',
                'payload' => $this->redactSensitivePayload($payload),
                'result' => $this->redactCommandOutputs($result),
            ]);

            return response()->json($this->redactCommandOutputs($result));
        } catch (Throwable $e) {
            $this->writeOltOperationLog([
                'olt_id' => $olt->id,
                'user_id' => auth()->id(),
                'operation' => $operation,
                'onu_ref' => $onuRef,
                'status' => 'failed',
                'payload' => $this->redactSensitivePayload($payload),
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);

            return response()->json([
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ], 422);
        }
    }

    /**
     * Operation auditing must never turn a completed device action into a
     * false failure when the audit row is briefly locked by a snapshot job.
     */
    protected function writeOltOperationLog(array $attributes): void
    {
        try {
            OltOperationLog::create($attributes);
        } catch (Throwable $e) {
            Log::warning('[OLT] operation audit deferred', [
                'olt_id' => $attributes['olt_id'] ?? null,
                'operation' => $attributes['operation'] ?? null,
                'onu_ref' => $attributes['onu_ref'] ?? null,
                'error' => Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?: 'audit write failed', 240, ''),
            ]);
        }
    }

    protected function redactSensitivePayload(array $payload): array
    {
        return app(OltResponseSanitizer::class)->sanitize($payload);
    }

    protected function redactCommandOutputs(array $result): array
    {
        unset($result['commands'], $result['outputs'], $result['raw'], $result['detail_raw']);

        return app(OltResponseSanitizer::class)->sanitize($result);
    }

    // ------------------------------------------------------------------
    // Huawei MA5608T ONU maintenance (delete / rename / reboot / save).
    // Superadmin only, behind the `huawei_olt_ui_write_enabled` setting.
    // ZTE / HiOSO never reach these methods.
    // ------------------------------------------------------------------

    protected function isHuaweiOlt(Olt $olt): bool
    {
        $vendor = strtolower((string) ($olt->vendor ?? ''));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    public static function huaweiUiWriteEnabled(): bool
    {
        return filter_var(Setting::get('huawei_olt_ui_write_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function huaweiSavePendingKey(Olt $olt): string
    {
        return 'huawei-olt-save-pending:'.(int) $olt->getKey();
    }

    /** Flag first (423), then superadmin (403). Both before any gate or SSH. */
    private function huaweiWriteGate(Request $request, Olt $olt): ?JsonResponse
    {
        if (! self::huaweiUiWriteEnabled()) {
            return response()->json([
                'success' => false,
                'error_code' => 'huawei_ui_write_disabled',
                'error' => 'Tulis Huawei dimatikan (saklar owner). Tidak ada perubahan.',
                'message' => 'Tulis Huawei dimatikan (saklar owner). Tidak ada perubahan.',
            ], 423);
        }

        $actor = $request->user();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return response()->json([
                'success' => false,
                'error_code' => 'superadmin_required',
                'error' => 'Hanya superadmin yang boleh mengubah ONU Huawei dari halaman OLT.',
                'message' => 'Hanya superadmin yang boleh mengubah ONU Huawei dari halaman OLT.',
            ], 403);
        }

        return null;
    }

    private function huaweiSerialUnknownResponse(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'serial_unknown',
            'error' => 'SN ONU tidak diketahui; sinkron OLT dulu. Tidak ada perubahan.',
            'message' => 'SN ONU tidak diketahui; sinkron OLT dulu. Tidak ada perubahan.',
        ], 422);
    }

    private function huaweiInventorySerial(Olt $olt, string $onuRef): ?string
    {
        if (! Schema::hasTable('olt_onus')) {
            return null;
        }
        $ref = strtolower(preg_replace('/^gpon-onu_/i', '', trim($onuRef)) ?? $onuRef);
        $serial = trim((string) OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereRaw('LOWER(onu_ref) = ?', [$ref])
            ->value('serial_number'));

        return $serial === '' ? null : $serial;
    }

    private function huaweiSerialKey(string $serial): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $serial) ?? '');
    }

    /**
     * @param  callable(): array<string, mixed>  $callback  returns a service result array
     */
    private function runHuaweiOperation(Olt $olt, string $operation, string $onuRef, array $payload, callable $callback): JsonResponse
    {
        $log = fn (string $status, array $extra) => $this->writeOltOperationLog([
            'olt_id' => $olt->id,
            'user_id' => auth()->id(),
            'operation' => $operation,
            'onu_ref' => $onuRef,
            'status' => $status,
            'payload' => $this->redactSensitivePayload($payload),
        ] + $extra);

        $busy = function () use ($log) {
            $message = 'OLT sedang dipakai (PSB/migrasi/hapus lain), coba lagi +-1 menit. Tidak ada perubahan.';
            $log('failed', ['error' => $message]);

            return response()->json([
                'success' => false,
                'error_code' => 'olt_busy',
                'error' => $message,
                'message' => $message,
            ], 409);
        };

        try {
            $run = OltOperationLock::run($olt, 10, function () use ($callback) {
                try {
                    return ['result' => $callback()];
                } catch (HuaweiOltSessionBusyException) {
                    return ['busy' => true];
                }
            }, $onuRef);
        } catch (OltProvisioningBlockedException) {
            return $this->provisioningBlockedResponse();
        } catch (Throwable $e) {
            $log('failed', ['error' => SensitiveDataRedactor::redact($e->getMessage())]);

            return response()->json([
                'success' => false,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ], 422);
        }

        if ($run['busy'] || ($run['value']['busy'] ?? false)) {
            return $busy();
        }

        $result = (array) ($run['value']['result'] ?? []);
        if (($result['save_pending'] ?? false) === true) {
            // Survives a page reload until a SAVE OLT succeeds.
            Cache::put(self::huaweiSavePendingKey($olt), now()->toISOString(), now()->addDays(30));
        } elseif ($operation === 'save_config' && ($result['success'] ?? false) === true) {
            Cache::forget(self::huaweiSavePendingKey($olt));
        }
        if (($result['success'] ?? false) === true) {
            $log('success', ['result' => $this->redactCommandOutputs($result)]);

            return response()->json($this->redactCommandOutputs($result));
        }

        $code = (string) ($result['error_code'] ?? 'huawei_operation_failed');
        $log('failed', [
            'result' => $this->redactCommandOutputs($result),
            'error' => SensitiveDataRedactor::redact((string) ($result['message'] ?? $code)),
        ]);
        $status = match ($code) {
            'dry_run' => 423,
            'serial_mismatch', 'onu_online', 'service_port_still_present', 'service_port_scope_mismatch', 'partial_service_ports_removed',
            'still_present_after_delete', 'rename_not_verified' => 409,
            default => 422,
        };

        return response()->json($this->redactCommandOutputs($result), $status);
    }

    private function huaweiDeleteOnu(Request $request, Olt $olt, array $validated, string $expectedSerial): JsonResponse
    {
        $onuRef = (string) $validated['onu_ref'];
        $override = (bool) ($validated['guard_override'] ?? false);

        return $this->runHuaweiOperation($olt, 'delete_onu', $onuRef, $validated, function () use ($request, $olt, $onuRef, $expectedSerial, $override) {
            $result = app(HuaweiOnuMaintenanceService::class)
                ->deleteOnuWithServicePorts($olt, $onuRef, $expectedSerial, $override);

            $dbPurged = 0;
            if (($result['success'] ?? false) === true && ($result['verified_absent'] ?? false) === true) {
                $result['db_purged'] = $dbPurged = $this->purgeHuaweiOnuRow($olt, $onuRef, $expectedSerial);
                $result['snapshot_purged'] = $this->purgeOnuFromSnapshotCaches($olt, $onuRef);
                $result['gpon_snapshot'] = $this->resolvePurgedSnapshotForClient($olt, $onuRef);
            } elseif (($result['success'] ?? false) === true) {
                $result['db_purged'] = 0;
                $result['warnings'] = ['ONU belum terbukti hilang pada read-back OLT; inventory tidak dibersihkan. Sinkron OLT untuk memastikan.'];
            }

            if (($result['device_write'] ?? false) === true || ($result['already_absent'] ?? false) === true) {
                activity()
                    ->causedBy($request->user())
                    ->performedOn($olt)
                    ->event('olt_onu_huawei_ui_delete')
                    ->withProperties([
                        'onu_ref' => $onuRef,
                        'serial' => $expectedSerial,
                        'success' => (bool) ($result['success'] ?? false),
                        'error_code' => $result['error_code'] ?? null,
                        'removed_service_ports' => $result['removed_service_ports'] ?? [],
                        'rebuild_hint' => $result['rebuild_hint'] ?? [],
                        'save_pending' => (bool) ($result['save_pending'] ?? false),
                        'guard_override' => $override,
                        'verified_absent' => (bool) ($result['verified_absent'] ?? false),
                    ])
                    ->log('Hapus ONU Huawei '.$onuRef.' di '.$olt->name);
            }

            if (($result['success'] ?? false) === true && ($result['save_pending'] ?? false) === true) {
                $result['warnings'] = array_merge($result['warnings'] ?? [], ['Perubahan aktif tetapi belum disimpan (save tertunda). Gunakan Simpan ulang.']);
            }

            return $result;
        });
    }

    private function purgeHuaweiOnuRow(Olt $olt, string $onuRef, string $expectedSerial): int
    {
        if (! Schema::hasTable('olt_onus')) {
            return 0;
        }
        $ref = $this->normalizeOnuRefKey($onuRef);
        if ($ref === '') {
            return 0;
        }

        $purged = 0;
        $rows = OltOnu::query()->where('olt_id', $olt->id)->whereRaw('LOWER(onu_ref) = ?', [$ref])->get();
        foreach ($rows as $row) {
            // ref AND SN: a row for the same ONT-ID with another serial belongs
            // to a newer ONT (PSB reused the ID) and must stay.
            if ($this->huaweiSerialKey((string) $row->serial_number) === $this->huaweiSerialKey($expectedSerial)) {
                $row->delete();
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * @return array{warnings: list<string>, suggested_name: ?string}
     */
    private function huaweiRenameWarnings(Olt $olt, string $onuRef, string $serial, string $name): array
    {
        $warnings = [];
        $suggested = null;

        if (Schema::hasTable('olt_onus')) {
            $ref = strtolower(preg_replace('/^gpon-onu_/i', '', trim($onuRef)) ?? $onuRef);
            $dup = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) != ?', [$ref])
                ->whereRaw('UPPER(onu_name) = ?', [$name])
                ->count();
            if ($dup > 0) {
                $warnings[] = "Nama {$name} sudah dipakai ONU lain di OLT ini.";
            }
        }

        $key = $this->huaweiSerialKey($serial);
        if ($key !== '') {
            $customer = Customer::query()
                ->whereRaw("UPPER(REPLACE(REPLACE(onu_serial, ' ', ''), '-', '')) = ?", [$key])
                ->orderByDesc('id')
                ->first();
            if ($customer && filled($customer->pppoe_user)) {
                $suggested = $this->normalizeOnuDisplayName((string) $customer->pppoe_user);
                if ($suggested !== '' && $suggested !== $name) {
                    $warnings[] = "Nama berbeda dari PPPoE pelanggan ({$suggested}); pencocokan pelanggan berdasarkan nama bisa berubah.";
                }
            }
        }

        return ['warnings' => $warnings, 'suggested_name' => $suggested];
    }

    private function huaweiRenameOnu(Request $request, Olt $olt, string $onuRef, string $sn, string $name, array $validated): JsonResponse
    {
        if ($blocked = $this->huaweiWriteGate($request, $olt)) {
            return $blocked;
        }
        if ($blocked = $this->provisioningGate($olt, $request, 'rename-onu')) {
            return $blocked;
        }

        $inventorySerial = $this->huaweiInventorySerial($olt, $onuRef);
        if ($inventorySerial === null) {
            return $this->huaweiSerialUnknownResponse();
        }
        if ($sn !== '' && $this->huaweiSerialKey($sn) !== $this->huaweiSerialKey($inventorySerial)) {
            return response()->json([
                'success' => false,
                'error_code' => 'serial_mismatch',
                'error' => 'SN yang dikirim berbeda dengan SN inventory untuk ONU ini. Tidak ada perubahan.',
                'message' => 'SN yang dikirim berbeda dengan SN inventory untuk ONU ini. Tidak ada perubahan.',
            ], 409);
        }
        $serial = $inventorySerial;
        $hints = $this->huaweiRenameWarnings($olt, $onuRef, $serial, $name);

        return $this->runHuaweiOperation($olt, 'rename_onu', $onuRef, $validated, function () use ($request, $olt, $onuRef, $serial, $name, $hints) {
            $result = app(HuaweiOnuMaintenanceService::class)->renameOnuDescription($olt, $onuRef, $serial, $name);
            if (($result['success'] ?? false) !== true || ($result['verified_name'] ?? false) !== true) {
                return $result;
            }

            $normRef = strtolower(preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef);
            $invUpdated = Schema::hasTable('olt_onus') && OltOnu::query()
                ->where('olt_id', $olt->id)
                ->whereRaw('LOWER(onu_ref) = ?', [$normRef])
                ->update([
                    'onu_name' => $name,
                    'name_source' => 'manual_rename',
                    'updated_at' => now(),
                ]) > 0;

            $acs = $this->syncAcsTagAfterOltRename($serial, $name);
            $acsLabel = ($acs['pushed'] ?? false) ? 'ACS tag OK' : (($acs['queued'] ?? false) ? 'ACS queued' : 'ACS pending');

            activity()
                ->causedBy($request->user())
                ->performedOn($olt)
                ->event('olt_onu_huawei_ui_rename')
                ->withProperties([
                    'onu_ref' => $onuRef,
                    'serial' => $serial,
                    'old_name' => $result['old_name'] ?? null,
                    'new_name' => $name,
                    'unchanged' => (bool) ($result['unchanged'] ?? false),
                    'save_pending' => (bool) ($result['save_pending'] ?? false),
                ])
                ->log('Rename ONU Huawei '.$onuRef.' di '.$olt->name);

            $warnings = $hints['warnings'];
            if (($result['save_pending'] ?? false) === true) {
                $warnings[] = 'Nama aktif tetapi belum disimpan (save tertunda). Gunakan Simpan ulang.';
            }

            return array_merge($result, [
                'name' => $name,
                'sn' => $serial,
                'inventory_updated' => $invUpdated,
                'acs_tag' => $acs,
                'acs_synced' => (bool) ($acs['pushed'] ?? false),
                'warnings' => $warnings,
                'suggested_name' => $hints['suggested_name'],
                'message' => "ONU {$onuRef} → name {$name}".($invUpdated ? ' · inventory OK' : '').' · '.$acsLabel,
            ]);
        });
    }

    private function huaweiRebootOnu(Request $request, Olt $olt, array $validated): JsonResponse
    {
        if ($blocked = $this->huaweiWriteGate($request, $olt)) {
            return $blocked;
        }
        if ($blocked = $this->provisioningGate($olt, $request, 'reboot-onu')) {
            return $blocked;
        }

        $onuRef = (string) $validated['onu_ref'];
        $serial = $this->huaweiInventorySerial($olt, $onuRef);
        if ($serial === null) {
            return $this->huaweiSerialUnknownResponse();
        }

        return $this->runHuaweiOperation($olt, 'reboot_onu', $onuRef, $validated, function () use ($request, $olt, $onuRef, $serial) {
            $result = app(HuaweiOnuMaintenanceService::class)->rebootOnuChecked($olt, $onuRef, $serial);

            if (($result['device_write'] ?? false) === true) {
                activity()
                    ->causedBy($request->user())
                    ->performedOn($olt)
                    ->event('olt_onu_huawei_ui_reboot')
                    ->withProperties([
                        'onu_ref' => $onuRef,
                        'serial' => $serial,
                        'success' => (bool) ($result['success'] ?? false),
                        'verified_registered' => (bool) ($result['verified_registered'] ?? false),
                    ])
                    ->log('Reboot ONU Huawei '.$onuRef.' di '.$olt->name);
            }

            return $result;
        });
    }

    /**
     * Re-run only `save` on a Huawei OLT after a "save pending" result.
     */
    public function saveConfig(Request $request, Olt $olt)
    {
        if (! $this->isHuaweiOlt($olt)) {
            return response()->json(['success' => false, 'error' => 'Simpan ulang hanya untuk OLT Huawei.'], 422);
        }
        if ($blocked = $this->huaweiWriteGate($request, $olt)) {
            return $blocked;
        }
        if ($blocked = $this->provisioningGate($olt, $request, 'save-config')) {
            return $blocked;
        }

        return $this->runHuaweiOperation($olt, 'save_config', 'olt-'.$olt->id, [], function () use ($request, $olt) {
            $result = app(HuaweiOnuMaintenanceService::class)->saveConfig($olt);

            activity()
                ->causedBy($request->user())
                ->performedOn($olt)
                ->event('olt_huawei_ui_save_config')
                ->withProperties(['success' => (bool) ($result['success'] ?? false)])
                ->log('Simpan ulang konfigurasi Huawei di '.$olt->name);

            return $result;
        });
    }

    protected function oltService(Olt $olt): OltZteC300Service|OltHiosoService|OltHuaweiMa5608tService
    {
        $vendor = strtolower((string) ($olt->vendor ?? 'zte_c300'));

        return match (true) {
            str_contains($vendor, 'hioso'), str_contains($vendor, 'htb') => app(OltHiosoService::class),
            str_contains($vendor, 'huawei'), str_contains($vendor, 'ma5608') => app(OltHuaweiMa5608tService::class),
            default => app(OltZteC300Service::class),
        };
    }

    protected function resolveCachedSnapshotForClient(Olt $olt): array
    {
        $olt->refresh();
        $snapshot = $this->resolveCachedSnapshot($olt);

        if (! empty($snapshot['onus']) && ! $this->shouldUseEnterpriseRefresh($olt)) {
            $snapshot = $this->restoreSnapshotRxPowerFromCustomers($olt, $snapshot);
        }

        $snapshot = app(OltSnapshotStatsService::class)->enrich($snapshot);

        $snapshot['meta'] ??= [];
        $snapshot['meta']['served_from_cache'] = true;
        $snapshot['meta']['last_attempt_at'] = now()->toISOString();

        return $this->redactCommandOutputs($snapshot);
    }

    protected function resolveCachedSnapshot(Olt $olt): array
    {
        $fromBuilder = app(OltSnapshotBuilder::class)->build($olt);

        if ($this->shouldUseEnterpriseRefresh($olt) && ! empty($fromBuilder['onus'])) {
            return $this->ensureSnapshotMatchedCustomers($olt, $fromBuilder);
        }

        $candidates = [];

        if (! empty($fromBuilder['onus'])) {
            $candidates[] = $fromBuilder;
        }

        $fromColumn = $this->snapshotArray($olt->last_gpon_snapshot);
        if (! empty($fromColumn['onus'])) {
            $candidates[] = $fromColumn;
        }

        if ($candidates === []) {
            return $fromColumn;
        }

        usort($candidates, fn (array $left, array $right) => count($right['onus'] ?? []) <=> count($left['onus'] ?? []));

        return $this->ensureSnapshotMatchedCustomers($olt, $candidates[0], $candidates);
    }

    protected function gponOnusCount(Olt $olt): int
    {
        $snapshot = $this->snapshotArray($olt->last_gpon_snapshot);
        $count = count($snapshot['onus'] ?? []);

        if ($count > 0) {
            return $count;
        }

        if (Schema::hasTable('olt_onus')) {
            $count = OltOnu::query()->where('olt_id', $olt->id)->count();
            if ($count > 0) {
                return $count;
            }
        }

        $fromBuilder = app(OltSnapshotBuilder::class)->build($olt);

        return count($fromBuilder['onus'] ?? []);
    }

    protected function snapshotArray(mixed $snapshot): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
