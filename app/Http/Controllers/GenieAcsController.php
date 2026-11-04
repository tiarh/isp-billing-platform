<?php

namespace App\Http\Controllers;

use App\Models\GenieAcsDevice;
use App\Models\Setting;
use App\Services\GenieAcsService;
use App\Support\AreaScope;
use App\Support\GenieAcsParameterPolicy;
use App\Support\OpsTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class GenieAcsController extends Controller
{
    public function page(Request $request, GenieAcsService $service): Response
    {
        $devices = $this->deviceQuery($request)
            ->paginate($this->perPage($request))
            ->withQueryString();

        $devices->getCollection()->transform(fn (GenieAcsDevice $device) => $service->formatListItem($device));

        $lastSyncedAt = GenieAcsDevice::query()->max('last_seen_sync_at');

        return Inertia::render('GenieAcs/Index', [
            'devices' => $devices,
            'summary' => $service->summary(),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $request->string('status')->toString(),
                'vendor' => $request->string('vendor')->toString(),
                'linked' => $request->string('linked')->toString(),
                'snapshot' => $request->string('snapshot')->toString(),
            ],
            'genieacsUrl' => Setting::get('genieacs_url'),
            'genieacsPanelUrl' => (string) config('site.genieacs_panel_url'),
            'cwmpUrl' => Setting::get('genieacs_cwmp_url', config('site.genieacs_cwmp_url')),
            'lastSyncedAt' => OpsTime::iso($lastSyncedAt),
        ]);
    }

    public function index(Request $request, GenieAcsService $service)
    {
        $devices = $this->deviceQuery($request)
            ->paginate($this->perPage($request));

        $devices->getCollection()->transform(fn (GenieAcsDevice $device) => $service->formatListItem($device));

        return response()->json($devices);
    }

    /** Rows per page for the GenieACS device list (large fleets need >100). */
    private function perPage(Request $request): int
    {
        $default = (int) $request->input('limit', 100);

        return min(max($default, 1), 500);
    }

    public function show(GenieAcsDevice $device, GenieAcsService $service)
    {
        $this->authorizeDevice($device);
        $device->load('customer:id,code,name,pppoe_user,onu_serial,status');

        return response()->json($service->formatDetail($device));
    }

    public function sync(GenieAcsService $service)
    {
        @set_time_limit(60);
        @ini_set('memory_limit', '256M');
        // Browser and scheduler must share one lock; otherwise a click can
        // overlap the cron snapshot and amplify DB/NBI load.
        $lock = Cache::lock('genieacs:snapshot-sync', 90);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'genieacs_sync_in_progress',
                'error_code' => 'genieacs_sync_in_progress',
                'message' => 'Sync GenieACS masih berjalan. Dashboard tetap memakai snapshot terakhir.',
                'stale' => true,
                'synced' => 0,
                'summary' => $service->summary(),
                'last_synced_at' => OpsTime::iso(GenieAcsDevice::query()->max('updated_at')),
            ], 202);
        }

        try {
            // Auto-tag runs in its own scheduled command; never hold the browser
            // request on dozens of POST/DELETE calls to GenieACS.
            $result = $service->syncDevices([
                'auto_tag' => false,
                'auto_tag_limit' => 0,
            ]);

            // A non-empty local snapshot cannot legitimately be replaced by an
            // empty NBI response. Keep the previous data and report stale rather
            // than telling NOC that a zero-device sync succeeded.
            if (($result['fetched'] ?? null) === 0 && GenieAcsDevice::query()->exists()) {
                return response()->json([
                    'error' => 'genieacs_empty_snapshot',
                    'error_code' => 'genieacs_empty_snapshot',
                    'message' => 'GenieACS mengembalikan snapshot kosong; data lama dipertahankan. Cek NBI 106 sebelum mencoba lagi.',
                    'stale' => true,
                    'synced' => 0,
                    'fetched' => 0,
                    'summary' => $service->summary(),
                    'last_synced_at' => OpsTime::iso(GenieAcsDevice::query()->max('updated_at')),
                ], 202);
            }

            return response()->json($result);
        } catch (\Throwable $e) {
            Log::warning('[GenieACS] Browser sync failed; serving stale status instead of 500', [
                'exception_class' => $e::class,
            ]);

            return response()->json([
                'error' => 'genieacs_sync_failed',
                'error_code' => 'genieacs_sync_failed',
                'message' => 'Sync GenieACS belum berhasil dari web 102. Dashboard tetap memakai snapshot terakhir; tunggu scheduler/worker atau cek koneksi GenieACS.',
                'stale' => true,
                'synced' => 0,
                'summary' => $service->summary(),
                'last_synced_at' => OpsTime::iso(GenieAcsDevice::query()->max('updated_at')),
            ], 202);
        } finally {
            $lock->release();
        }
    }

    public function reboot(GenieAcsDevice $device, GenieAcsService $service)
    {
        $this->authorizeDevice($device);

        return response()->json($service->reboot($device));
    }

    public function setParameter(Request $request, GenieAcsDevice $device, GenieAcsService $service)
    {
        $this->authorizeDevice($device);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\A[A-Za-z][A-Za-z0-9_.:-]*\z/'],
            'value' => ['nullable'],
        ]);

        $name = (string) $validated['name'];
        $value = $validated['value'] ?? null;

        // S5: only customer Wi-Fi settings are writable from this HTTP endpoint;
        // ManagementServer.*, credentials, WAN, firmware, etc. are rejected before
        // reaching the ACS. The check lives here (not in GenieAcsService) so
        // internal service callers (PSB / migration / jobs) are unaffected.
        if (! GenieAcsParameterPolicy::allows($name)) {
            activity('genieacs.parameter')
                ->causedBy($request->user())
                ->performedOn($device)
                ->withProperties(['parameter' => $name, 'outcome' => 'rejected'])
                ->log('genieacs.parameter_rejected');

            return response()->json([
                'error' => 'parameter_not_allowed',
                'message' => 'Parameter TR-069 ini tidak boleh diubah dari eBilling (hanya pengaturan Wi-Fi).',
            ], 422);
        }

        $result = $service->setParameter($device, $name, $value);

        activity('genieacs.parameter')
            ->causedBy($request->user())
            ->performedOn($device)
            ->withProperties([
                'parameter' => $name,
                'value' => GenieAcsParameterPolicy::isSecret($name) ? '[redacted]' : (is_scalar($value) ? $value : null),
                'outcome' => 'queued',
            ])
            ->log('genieacs.parameter_set');

        return response()->json($result);
    }

    protected function deviceQuery(Request $request): Builder
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->string('status')->toString();
        $vendor = $request->string('vendor')->toString();
        $linked = $request->string('linked')->toString();
        $snapshot = $request->string('snapshot')->toString();

        $query = GenieAcsDevice::query();
        if ($request->user()?->hasAreaScope()) {
            $areaIds = $request->user()->accessibleAreaIds()->all();
            $query->whereHas('customer', fn (Builder $customer) => $customer
                ->withTrashed()
                ->whereIn('area_id', $areaIds));
        }

        return $query
            ->with('customer:id,code,name,pppoe_user')
            ->when($snapshot === 'not_found', fn (Builder $query) => $query->where('snapshot_status', 'not_found'))
            ->when($snapshot !== 'not_found', function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->whereNull('snapshot_status')
                        ->orWhere('snapshot_status', 'active');
                });
            })
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $inner) use ($search) {
                    $inner->where('device_id', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('ip_tr069', 'like', "%{$search}%")
                        ->orWhere('ssid', 'like', "%{$search}%")
                        ->orWhere('ssid_5g', 'like', "%{$search}%")
                        ->orWhere('pppoe_user', 'like', "%{$search}%")
                        ->orWhere('pelanggan', 'like', "%{$search}%")
                        ->orWhere('genieacs_customer_name', 'like', "%{$search}%")
                        ->orWhereHas('customer', function (Builder $customer) use ($search) {
                            $customer->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%")
                                ->orWhere('pppoe_user', 'like', "%{$search}%");
                        });
                });
            })
            ->when($status === 'online', fn (Builder $query) => $query->online())
            ->when($status === 'offline', fn (Builder $query) => $query->offline())
            ->when($vendor === 'fiberhome', fn (Builder $query) => $query->fiberhome())
            ->when($linked === 'yes', fn (Builder $query) => $query->linked())
            ->when($linked === 'no', fn (Builder $query) => $query->unlinked())
            ->latest('last_inform_at')
            ->latest('id');
    }

    private function authorizeDevice(GenieAcsDevice $device): void
    {
        $user = request()->user();
        if (! $user?->hasAreaScope()) {
            return;
        }

        $customer = $device->customer()->withTrashed()->first();
        abort_unless($customer, 403);
        AreaScope::authorizeCustomer($customer, $user);
    }
}
