<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\DeviceRecoveryCase;
use App\Models\Kabel;
use App\Models\OdpOdc;
use App\Models\Splitter;
use App\Services\DeviceRecoveryService;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use ZipArchive;

class InventarisController extends Controller
{
    public function index(Request $request, DeviceRecoveryService $recoveryService)
    {
        $search = $request->query('search', '');
        // Default single type — never dump ODP+ODC+kabel+splitter all at once into HTML
        $type = $request->query('type', 'odp'); // odp, odc, kabel, splitter, all, recovery
        $recoveryTab = $type === 'recovery';
        $areaFilter = $request->query('area', '');
        // "all" only shows stats; list uses odp as safe default to keep data-page small
        $listType = in_array($type, ['odp', 'odc', 'kabel', 'splitter'], true) ? $type : 'odp';
        $user = $request->user();

        $areasQuery = Area::select('id', 'name', 'code')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, $user);
        $areas = $areasQuery->get();

        $scopedAreaIds = ($user && $user->hasAreaScope())
            ? $user->accessibleAreaIds()->all()
            : null;

        if ($scopedAreaIds !== null) {
            if ($areaFilter !== '' && $areaFilter !== null) {
                abort_unless(in_array((int) $areaFilter, array_map('intval', $scopedAreaIds), true), 403);
            } elseif ($scopedAreaIds === []) {
                $areaFilter = '__none__';
            }
        }

        // Stats summary — asset counts only, no customer data
        $odpStats = OdpOdc::where('type', 'ODP');
        $odcStats = OdpOdc::whereIn('type', ['ODC', 'POP']);
        $kabelStats = Kabel::query();
        $splitterStats = Splitter::query();
        if (is_array($scopedAreaIds)) {
            $odpStats->whereIn('area_id', $scopedAreaIds);
            $odcStats->whereIn('area_id', $scopedAreaIds);
            $kabelStats->whereIn('area_id', $scopedAreaIds);
            $splitterStats->whereIn('area_id', $scopedAreaIds);
        }
        $stats = [
            'odp' => $odpStats->count(),
            'odc' => $odcStats->count(),
            'kabel' => $kabelStats->count(),
            'splitter' => $splitterStats->count(),
        ];

        // Pre-compute active_ports per ODP/ODC from splitter used_ports (asset-only, no customer table)
        $splitterUsageQuery = Splitter::selectRaw('parent_odp_odc_id, SUM(used_ports) as total_used')
            ->groupBy('parent_odp_odc_id');
        if (is_array($scopedAreaIds)) {
            $splitterUsageQuery->whereIn('area_id', $scopedAreaIds);
        }
        $splitterUsageByParent = $splitterUsageQuery->pluck('total_used', 'parent_odp_odc_id');

        // Build query based on type filter
        $odpOdcQuery = OdpOdc::with('area');
        $kabelQuery = Kabel::with('area');
        $splitterQuery = Splitter::with(['area', 'parentOdpOdc']);

        if (is_array($scopedAreaIds)) {
            $odpOdcQuery->whereIn('area_id', $scopedAreaIds);
            $kabelQuery->whereIn('area_id', $scopedAreaIds);
            $splitterQuery->whereIn('area_id', $scopedAreaIds);
        }

        // Apply area filter
        if ($areaFilter && $areaFilter !== '__none__') {
            $odpOdcQuery->where('area_id', $areaFilter);
            $kabelQuery->where('area_id', $areaFilter);
            $splitterQuery->where('area_id', $areaFilter);
        } elseif ($areaFilter === '__none__') {
            $odpOdcQuery->whereRaw('1 = 0');
            $kabelQuery->whereRaw('1 = 0');
            $splitterQuery->whereRaw('1 = 0');
        }

        // Apply search
        if ($search) {
            $odpOdcQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('olt_port', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('area', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
            });
            $kabelQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhereHas('area', fn ($aq) => $aq->where('name', 'like', "%{$search}%"));
            });
            $splitterQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%")
                    ->orWhereHas('area', fn ($aq) => $aq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('parentOdpOdc', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
            });
        }

        // Load only the selected list type (one at a time) — prevents megabyte HTML data-page.
        // Cap list payload — map UIs can filter by area; full dump bloated data-page
        $listLimit = 200;

        $odps = ! $recoveryTab && $listType === 'odp'
            ? (clone $odpOdcQuery)->where('type', 'ODP')->orderBy('name')->limit($listLimit)->get()->map(function ($item) use ($splitterUsageByParent) {
                $active = (int) ($splitterUsageByParent[$item->id] ?? 0);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'area' => $item->area?->name,
                    'area_id' => $item->area_id,
                    'total_ports' => $item->total_ports,
                    'active_ports' => $active,
                    'available_ports' => max(0, ($item->total_ports ?? 0) - $active),
                    'status' => $item->status ?? 'active',
                    'lat' => $item->lat,
                    'lng' => $item->lng,
                    'type' => 'ODP',
                ];
            })
            : [];

        $odcs = ! $recoveryTab && $listType === 'odc'
            ? (clone $odpOdcQuery)->where('type', '!=', 'ODP')->orderBy('name')->limit($listLimit)->get()->map(function ($item) use ($splitterUsageByParent) {
                $active = (int) ($splitterUsageByParent[$item->id] ?? 0);

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'area' => $item->area?->name,
                    'area_id' => $item->area_id,
                    'olt_port' => $item->olt_port,
                    'total_ports' => $item->total_ports,
                    'active_ports' => $active,
                    'available_ports' => max(0, ($item->total_ports ?? 0) - $active),
                    'status' => $item->status ?? 'active',
                    'lat' => $item->lat,
                    'lng' => $item->lng,
                    'type' => $item->type,
                ];
            })
            : [];

        $kabels = ! $recoveryTab && $listType === 'kabel'
            ? (clone $kabelQuery)->orderBy('name')->limit($listLimit)->get()->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'area' => $item->area?->name,
                'area_id' => $item->area_id,
                'type' => $item->type,
                'length_km' => $item->length_km,
                'status' => $item->status,
            ])
            : [];

        $splitters = ! $recoveryTab && $listType === 'splitter'
            ? (clone $splitterQuery)->orderBy('name')->limit($listLimit)->get()->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'area' => $item->area?->name,
                'area_id' => $item->area_id,
                'type' => $item->type,
                'total_ports' => $item->total_ports,
                'used_ports' => $item->used_ports,
                'available_ports' => $item->available_ports,
                'status' => $item->status,
                'parent_odp_odc' => $item->parentOdpOdc?->name,
            ])
            : [];

        $recovery = [
            'total' => 0,
            'by_status' => [],
            'cases' => [],
        ];
        if ($recoveryTab && Schema::hasTable('device_recovery_cases')) {
            $recoveryQuery = DeviceRecoveryCase::query()
                ->with([
                    'ticket:id,code',
                    'customer:id,name',
                    'area:id,name',
                    'assignee:id,name',
                    'items:id,recovery_case_id,expected_serial_number,captured_serial_number',
                ])
                ->whereNotIn('status', [DeviceRecoveryService::STATUS_CANCELLED, DeviceRecoveryService::STATUS_REUSED]);

            if (is_array($scopedAreaIds)) {
                $recoveryQuery->whereIn('area_id', $scopedAreaIds);
            }
            if ($areaFilter && $areaFilter !== '__none__') {
                $recoveryQuery->where('area_id', $areaFilter);
            } elseif ($areaFilter === '__none__') {
                $recoveryQuery->whereRaw('1 = 0');
            }
            if ($user?->isTeknisi()) {
                $recoveryQuery->where('assigned_to', $user->id);
            }
            if ($search !== '') {
                $recoveryQuery->where(function ($query) use ($search) {
                    $query->whereHas('ticket', fn ($ticket) => $ticket->where('code', 'like', "%{$search}%"))
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('items', fn ($item) => $item
                            ->where('expected_serial_number', 'like', "%{$search}%")
                            ->orWhere('captured_serial_number', 'like', "%{$search}%"));
                });
            }

            $recoveryCases = (clone $recoveryQuery)
                ->orderByDesc('updated_at')
                ->limit(20)
                ->get();
            $statusCounts = (clone $recoveryQuery)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $recovery['total'] = (clone $recoveryQuery)->count();
            $recovery['by_status'] = $statusCounts->map(fn ($count, $status): array => [
                'status' => $status,
                'label' => $recoveryService->statusPresentation((string) $status)['group'],
                'count' => (int) $count,
            ])->values()->all();
            $recovery['cases'] = $recoveryCases->map(function (DeviceRecoveryCase $case) use ($recoveryService): array {
                $presentation = $recoveryService->statusPresentation($case->status);

                return [
                    'id' => $case->id,
                    'ticket_code' => $case->ticket?->code,
                    'customer_name' => $case->customer?->name,
                    'area' => $case->area?->name,
                    'assigned_to' => $case->assignee?->name,
                    'item_count' => $case->items->count(),
                    'serial_numbers' => $case->items->map(fn ($item): ?string => $item->captured_serial_number ?: $item->expected_serial_number)->filter()->values()->all(),
                    'status' => $case->status,
                    'status_group' => $presentation['group'],
                    'substatus' => $presentation['substatus'],
                    'updated_at' => $case->updated_at?->timezone('Asia/Jakarta')->toIso8601String(),
                ];
            })->values()->all();
        }

        return Inertia::render('Inventaris/Index', [
            'stats' => $stats,
            'odps' => $odps,
            'odcs' => $odcs,
            'kabels' => $kabels,
            'splitters' => $splitters,
            'recovery' => $recovery,
            'areas' => $areas,
            'filters' => [
                'search' => $search,
                'type' => $recoveryTab ? 'recovery' : $listType,
                'area' => $areaFilter,
            ],
        ]);
    }

    public function export(Request $request, DeviceRecoveryService $recoveryService)
    {
        $type = $request->query('type', 'all');
        $areaFilter = $request->query('area', '');
        $user = $request->user();
        $scopedAreaIds = $user?->hasAreaScope() ? $user->accessibleAreaIds()->all() : null;

        abort_unless(in_array($type, ['all', 'odp', 'odc', 'kabel', 'splitter', 'recovery'], true), 422);
        if ($areaFilter !== '' && ! ctype_digit((string) $areaFilter)) {
            abort(422, 'Filter area tidak valid.');
        }
        AreaScope::authorizeAreaId($areaFilter !== '' ? (int) $areaFilter : null, $user);
        if ($type === 'recovery') {
            abort_unless(
                Schema::hasTable('device_recovery_cases') && Schema::hasTable('device_recovery_items'),
                409,
                'Export perangkat kembali belum tersedia.'
            );
        }

        $filename = 'inventaris_'.now()->format('Y-m-d_His').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function () use ($type, $areaFilter, $scopedAreaIds, $recoveryService) {
            $file = fopen('php://output', 'w');
            $safeCsv = static function ($value): string {
                $value = (string) ($value ?? '');

                return preg_match('/^[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
            };

            // Pre-compute active_ports from splitter used_ports (asset-only, no customer table)
            $splitterUsageQuery = Splitter::selectRaw('parent_odp_odc_id, SUM(used_ports) as total_used')
                ->groupBy('parent_odp_odc_id');
            if (is_array($scopedAreaIds)) {
                $splitterUsageQuery->whereIn('area_id', $scopedAreaIds);
            }
            $splitterUsageByParent = $splitterUsageQuery->pluck('total_used', 'parent_odp_odc_id');

            // ODP
            if ($type === 'all' || $type === 'odp') {
                fputcsv($file, ['=== ODP ===']);
                fputcsv($file, ['Name', 'Area', 'Total Ports', 'Active Ports', 'Available Ports', 'Status', 'Lat', 'Lng']);
                $query = OdpOdc::with('area')->where('type', 'ODP');
                if (is_array($scopedAreaIds)) {
                    $query->whereIn('area_id', $scopedAreaIds);
                }
                if ($areaFilter) {
                    $query->where('area_id', $areaFilter);
                }
                foreach ($query->cursor() as $item) {
                    $active = (int) ($splitterUsageByParent[$item->id] ?? 0);
                    fputcsv($file, [
                        $item->name,
                        $item->area?->name,
                        $item->total_ports,
                        $active,
                        max(0, ($item->total_ports ?? 0) - $active),
                        $item->status ?? 'active',
                        $item->lat,
                        $item->lng,
                    ]);
                }
                fputcsv($file, []);
            }

            // ODC/POP
            if ($type === 'all' || $type === 'odc') {
                fputcsv($file, ['=== ODC/POP ===']);
                fputcsv($file, ['Name', 'Area', 'OLT Port', 'Total Ports', 'Active Ports', 'Available Ports', 'Status', 'Lat', 'Lng']);
                $query = OdpOdc::with('area')->where('type', '!=', 'ODP');
                if (is_array($scopedAreaIds)) {
                    $query->whereIn('area_id', $scopedAreaIds);
                }
                if ($areaFilter) {
                    $query->where('area_id', $areaFilter);
                }
                foreach ($query->cursor() as $item) {
                    $active = (int) ($splitterUsageByParent[$item->id] ?? 0);
                    fputcsv($file, [
                        $item->name,
                        $item->area?->name,
                        $item->olt_port,
                        $item->total_ports,
                        $active,
                        max(0, ($item->total_ports ?? 0) - $active),
                        $item->status ?? 'active',
                        $item->lat,
                        $item->lng,
                    ]);
                }
                fputcsv($file, []);
            }

            // Kabel
            if ($type === 'all' || $type === 'kabel') {
                fputcsv($file, ['=== Kabel ===']);
                fputcsv($file, ['Name', 'Area', 'Type', 'Length (km)', 'Status']);
                $query = Kabel::with('area');
                if (is_array($scopedAreaIds)) {
                    $query->whereIn('area_id', $scopedAreaIds);
                }
                if ($areaFilter) {
                    $query->where('area_id', $areaFilter);
                }
                foreach ($query->cursor() as $item) {
                    fputcsv($file, [
                        $item->name,
                        $item->area?->name,
                        $item->type,
                        $item->length_km,
                        $item->status,
                    ]);
                }
                fputcsv($file, []);
            }

            // Splitter
            if ($type === 'all' || $type === 'splitter') {
                fputcsv($file, ['=== Splitter ===']);
                fputcsv($file, ['Name', 'Area', 'Type', 'Total Ports', 'Used Ports', 'Available Ports', 'Status', 'Parent ODP/ODC']);
                $query = Splitter::with(['area', 'parentOdpOdc']);
                if (is_array($scopedAreaIds)) {
                    $query->whereIn('area_id', $scopedAreaIds);
                }
                if ($areaFilter) {
                    $query->where('area_id', $areaFilter);
                }
                foreach ($query->cursor() as $item) {
                    fputcsv($file, [
                        $item->name,
                        $item->area?->name,
                        $item->type,
                        $item->total_ports,
                        $item->used_ports,
                        $item->available_ports,
                        $item->status,
                        $item->parentOdpOdc?->name,
                    ]);
                }
            }

            if ($type === 'recovery') {
                fputcsv($file, ['=== Perangkat Kembali ===']);
                fputcsv($file, ['Tiket', 'Kode Pelanggan', 'Pelanggan', 'Area', 'Status', 'Substatus', 'Teknisi', 'Jenis Perangkat', 'SN Expected', 'SN Captured', 'Kondisi', 'Status Item', 'Diperbarui']);

                $query = DeviceRecoveryCase::query()
                    ->with([
                        'ticket:id,code',
                        'customer:id,code,name',
                        'area:id,name',
                        'assignee:id,name',
                        'items:id,recovery_case_id,device_type,expected_serial_number,captured_serial_number,status,condition,updated_at',
                    ])
                    ->whereNotIn('status', [DeviceRecoveryService::STATUS_CANCELLED, DeviceRecoveryService::STATUS_REUSED]);
                if (is_array($scopedAreaIds)) {
                    $query->whereIn('area_id', $scopedAreaIds);
                }
                if ($areaFilter) {
                    $query->where('area_id', $areaFilter);
                }

                $query->orderBy('id')->chunkById(200, function ($cases) use ($file, $recoveryService, $safeCsv): void {
                    foreach ($cases as $case) {
                        $presentation = $recoveryService->statusPresentation($case->status);
                        $items = $case->items;
                        if ($items->isEmpty()) {
                            $items = [null];
                        }

                        foreach ($items as $item) {
                            $itemStatus = match ($item?->status) {
                                DeviceRecoveryService::ITEM_PENDING => 'Pending',
                                DeviceRecoveryService::ITEM_CARRIED => 'Dibawa teknisi',
                                DeviceRecoveryService::ITEM_RECEIVED => 'Diterima gudang',
                                DeviceRecoveryService::ITEM_EXCEPTION => 'Exception',
                                default => $item?->status,
                            };
                            fputcsv($file, [
                                $safeCsv($case->ticket?->code),
                                $safeCsv($case->customer?->code),
                                $safeCsv($case->customer?->name),
                                $safeCsv($case->area?->name),
                                $safeCsv($presentation['group']),
                                $safeCsv($presentation['substatus']),
                                $safeCsv($case->assignee?->name),
                                $safeCsv($item?->device_type),
                                $safeCsv($item?->expected_serial_number),
                                $safeCsv($item?->captured_serial_number),
                                $safeCsv($item?->condition),
                                $safeCsv($itemStatus),
                                $safeCsv($case->updated_at ? OpsTime::local($case->updated_at)->format('Y-m-d H:i:s') : null),
                            ]);
                        }
                    }
                });
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function importPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'type' => ['nullable', 'in:auto,odp,odc,kabel,splitter,kml'],
        ]);

        /** @var UploadedFile $file */
        $file = $data['file'];
        $type = $data['type'] ?? 'auto';
        $ext = strtolower($file->getClientOriginalExtension());

        $rows = in_array($ext, ['kml', 'kmz'], true)
            ? $this->previewKmlKmz($file)
            : $this->previewTableImport($file, $type);

        return response()->json([
            'ok' => true,
            'filename' => $file->getClientOriginalName(),
            'mode' => 'dry_run',
            'rows' => array_slice($rows, 0, 200),
            'summary' => [
                'total' => count($rows),
                'previewed' => min(count($rows), 200),
                'duplicates' => collect($rows)->where('status', 'duplicate')->count(),
                'needs_review' => collect($rows)->where('status', 'needs_review')->count(),
                'ready' => collect($rows)->where('status', 'ready')->count(),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previewTableImport(UploadedFile $file, string $forcedType): array
    {
        $rows = $this->readTabularRows($file);
        $areaByName = Area::query()->get(['id', 'name', 'code'])
            ->flatMap(fn (Area $area) => [
                strtolower($area->name) => $area->id,
                strtolower($area->code) => $area->id,
            ]);

        $existing = [
            'odp' => OdpOdc::where('type', 'ODP')->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]),
            'odc' => OdpOdc::where('type', '!=', 'ODP')->pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]),
            'kabel' => Kabel::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]),
            'splitter' => Splitter::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [strtolower($name) => $id]),
        ];

        return collect($rows)->take(500)->map(function (array $row, int $i) use ($forcedType, $areaByName, $existing) {
            $name = trim((string) ($row['name'] ?? $row['nama'] ?? $row['asset'] ?? ''));
            $rawType = strtolower(trim((string) ($row['type'] ?? $row['tipe'] ?? $forcedType)));
            $type = $this->normalizeImportType($rawType, $name);
            if ($forcedType !== 'auto') {
                $type = $forcedType === 'odc' ? 'odc' : $forcedType;
            }

            $areaRaw = strtolower(trim((string) ($row['area'] ?? $row['wilayah'] ?? $row['site'] ?? '')));
            $areaId = $areaByName[$areaRaw] ?? null;
            $duplicate = $name !== '' && isset($existing[$type][strtolower($name)]);
            $status = $name === '' || ! $areaId || ! in_array($type, ['odp', 'odc', 'kabel', 'splitter'], true)
                ? 'needs_review'
                : ($duplicate ? 'duplicate' : 'ready');

            return [
                'row' => $i + 2,
                'status' => $status,
                'type' => $type,
                'name' => $name,
                'area' => $areaRaw,
                'area_id' => $areaId,
                'duplicate_id' => $duplicate ? $existing[$type][strtolower($name)] : null,
                'lat' => $row['lat'] ?? $row['latitude'] ?? null,
                'lng' => $row['lng'] ?? $row['lon'] ?? $row['longitude'] ?? null,
                'total_ports' => $row['total_ports'] ?? $row['port'] ?? null,
                'note' => $status === 'needs_review' ? 'Nama/area/tipe belum lengkap atau belum match master area.' : null,
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readTabularRows(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'xlsx' && class_exists(ZipArchive::class)) {
            return $this->readXlsxRows($file->getRealPath());
        }

        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return [];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($header, array_pad($line, count($header), '')) ?: [];
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml') ?: '';
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml') ?: '';
        $zip->close();

        $shared = [];
        if ($sharedXml !== '') {
            $xml = simplexml_load_string($sharedXml);
            foreach ($xml->si ?? [] as $si) {
                $shared[] = trim((string) ($si->t ?? $si->r->t ?? ''));
            }
        }

        $xml = $sheet !== '' ? simplexml_load_string($sheet) : false;
        if (! $xml) {
            return [];
        }

        $lines = [];
        foreach ($xml->sheetData->row ?? [] as $row) {
            $line = [];
            foreach ($row->c as $cell) {
                $value = (string) ($cell->v ?? '');
                $line[] = ((string) $cell['t'] === 's') ? ($shared[(int) $value] ?? '') : $value;
            }
            $lines[] = $line;
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), array_shift($lines) ?: []);

        return collect($lines)
            ->map(fn ($line) => array_combine($header, array_pad($line, count($header), '')) ?: [])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previewKmlKmz(UploadedFile $file): array
    {
        $kml = file_get_contents($file->getRealPath()) ?: '';
        if (strtolower($file->getClientOriginalExtension()) === 'kmz' && class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            if ($zip->open($file->getRealPath()) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if (str_ends_with(strtolower($name), '.kml')) {
                        $kml = $zip->getFromIndex($i) ?: '';
                        break;
                    }
                }
                $zip->close();
            }
        }

        $xml = $kml !== '' ? simplexml_load_string($kml) : false;
        if (! $xml) {
            return [['row' => 1, 'status' => 'needs_review', 'type' => 'kml', 'name' => '', 'note' => 'KML/KMZ tidak bisa dibaca.']];
        }
        $xml->registerXPathNamespace('kml', 'http://www.opengis.net/kml/2.2');

        return collect($xml->xpath('//kml:Placemark') ?: [])->take(500)->map(function ($placemark, int $i) {
            $name = trim((string) $placemark->name);
            $hasPoint = (bool) $placemark->children('http://www.opengis.net/kml/2.2')->Point;
            $hasLine = (bool) $placemark->children('http://www.opengis.net/kml/2.2')->LineString;

            return [
                'row' => $i + 1,
                'status' => $name === '' ? 'needs_review' : 'ready',
                'type' => $hasLine ? 'kabel' : ($hasPoint ? $this->normalizeImportType('auto', $name) : 'kml'),
                'name' => $name,
                'area' => null,
                'area_id' => null,
                'duplicate_id' => null,
                'note' => 'Preview KMZ/KML hanya membaca nama/jenis/koordinat; warna core tidak dipakai.',
            ];
        })->values()->all();
    }

    private function normalizeImportType(string $type, string $name): string
    {
        $text = strtolower($type.' '.$name);

        return match (true) {
            str_contains($text, 'splitter') => 'splitter',
            str_contains($text, 'kabel'), str_contains($text, 'cable'), str_contains($text, 'feeder') => 'kabel',
            str_contains($text, 'odc'), str_contains($text, 'pop') => 'odc',
            default => 'odp',
        };
    }
}
