<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Http\Requests\CustomerStoreRequest;
use App\Http\Requests\CustomerUpdateRequest;
use App\Jobs\ProcessGenieAcsInformJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\Package;
use App\Models\Router;
use App\Services\BillingWaEventService;
use App\Services\GenieAcsService;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\Lifecycle\ManualLifecycleGate;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use App\Services\RouterPppoeCredentialService;
use App\Services\Routers\UnlinkedSecretPrefill;
use App\Support\AreaScope;
use App\Support\OpsTime;
use App\Support\SimpleXlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers with search and filters
     */
    public function index(Request $request)
    {
        // Owner Q14: a deferred termination only shows as a badge (status unchanged).
        // withExists = one subquery column, no N+1.
        $query = $this->customerIndexQuery($request)->withExists(['pendingTermination as has_pending_termination']);

        // Pagination
        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $customers = $query->paginate($limit)->withQueryString();

        $areasQuery = Area::select('id', 'name')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, $request->user());

        $archive = $request->boolean('archive') || $request->input('view') === 'archive';

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search', 'status', 'package_id', 'area_id', 'mikrotik_sync', 'unpaid_periods', 'sort', 'direction', 'limit', 'archive', 'view']),
            'view_mode' => $archive ? 'archive' : 'active',
            'archive_count' => $this->archiveCount($request),
            // Dropdown only — never dump full package rows into HTML data-page
            'packages' => Package::query()->select('id', 'name', 'price')->orderBy('name')->get(),
            'areas' => $areasQuery->get(),
        ]);
    }

    public function export(Request $request)
    {
        // Exports are always the operational customer set. Archive is a separate
        // review view and must never leak soft-deleted rows into an export.
        $query = $this->customerIndexQuery($request, false)
            ->with(['package:id,name,price,rate_limit', 'area:id,name', 'router:id,name'])
            ->select([
                'id',
                'code',
                'nik',
                'name',
                'phone',
                'address',
                'geo_lat',
                'geo_long',
                'pppoe_user',
                'package_id',
                'area_id',
                'router_id',
                'status',
                'mikrotik_sync_status',
                'mikrotik_synced_at',
                'mikrotik_sync_checked_at',
                'join_date',
                'due_day',
                'created_at',
            ])
            ->withCount([
                'invoices as unpaid_periods_count' => function ($query) {
                    $query->where('status', 'unpaid');
                },
            ]);

        // Stable ID pagination prevents rows being skipped while the export is
        // running. The identity guard protects legacy databases that contain
        // duplicate code/PPPoE rows even though current schema constraints do not.
        $rowNumber = 1;
        $seenIdentities = [];
        $rows = $query->reorder('id')->lazyById(1000)->map(function (Customer $customer) use (&$rowNumber, &$seenIdentities) {
            $identities = $this->customerExportIdentities($customer);
            foreach ($identities as $identity) {
                if (isset($seenIdentities[$identity])) {
                    return null;
                }
            }
            foreach ($identities as $identity) {
                $seenIdentities[$identity] = true;
            }

            $coordinates = $this->customerExportCoordinates($customer->geo_lat, $customer->geo_long);

            return [
                $rowNumber++,
                $customer->code,
                $customer->nik ?? '',
                $customer->name,
                $customer->address,
                $customer->phone,
                $customer->pppoe_user ?? '',
                $customer->area?->name ?? '',
                $coordinates['tikor'],
                $coordinates['lat'],
                $coordinates['lng'],
                $coordinates['maps'],
                $customer->package?->name ?? '',
                $customer->package?->rate_limit ?? '',
                $customer->package?->price ?? '',
                $customer->status,
                $customer->router?->name ?? '',
                $customer->mikrotik_sync_status ?? 'unknown',
                $customer->mikrotik_synced_at ? OpsTime::local($customer->mikrotik_synced_at)->format('Y-m-d H:i:s') : '',
                $customer->mikrotik_sync_checked_at ? OpsTime::local($customer->mikrotik_sync_checked_at)->format('Y-m-d H:i:s') : '',
                $customer->join_date?->toDateString() ?? '',
                $customer->due_day,
                $customer->unpaid_periods_count,
            ];
        })->filter();

        $path = SimpleXlsxWriter::create('customers-export.xlsx', [
            'No',
            'ID Pelanggan',
            'No KTP',
            'Nama Pelanggan',
            'Alamat',
            'Tlp',
            'PPPoE Username',
            'Area',
            'Tikor',
            'Latitude',
            'Longitude',
            'Link Maps',
            'Nama Langganan',
            'Keterangan Langganan',
            'Harga Langganan',
            'Status Pelanggan',
            'Router',
            'MikroTik Sync',
            'MikroTik Synced At',
            'MikroTik Checked At',
            'Tanggal Bergabung',
            'Jatuh Tempo',
            'Periode Belum Bayar',
        ], $rows);

        activity('customer-export')
            ->causedBy($request->user())
            ->withProperties([
                'filters' => [
                    'search_present' => $request->filled('search'),
                    'status' => in_array((string) $request->input('status'), [
                        CustomerStatus::PendingInstallation->value,
                        CustomerStatus::Active->value,
                        CustomerStatus::Inactive->value,
                        CustomerStatus::Isolated->value,
                        CustomerStatus::Suspended->value,
                        CustomerStatus::Terminated->value,
                    ], true) ? (string) $request->input('status') : null,
                    'package_id' => filter_var($request->input('package_id'), FILTER_VALIDATE_INT) ?: null,
                    'area_id' => filter_var($request->input('area_id'), FILTER_VALIDATE_INT) ?: null,
                    'mikrotik_sync' => in_array((string) $request->input('mikrotik_sync'), ['unknown', 'synced', 'missing'], true)
                        ? (string) $request->input('mikrotik_sync')
                        : null,
                    'unpaid_periods' => $request->input('unpaid_periods') === '3plus' ? '3plus' : null,
                    'sort' => in_array((string) $request->input('sort'), ['code', 'name', 'status', 'join_date', 'created_at', 'deleted_at'], true)
                        ? (string) $request->input('sort')
                        : null,
                    'direction' => in_array((string) $request->input('direction'), ['asc', 'desc'], true)
                        ? (string) $request->input('direction')
                        : null,
                    'archive_requested' => $request->boolean('archive'),
                ],
                'row_count' => max(0, $rowNumber - 1),
            ])
            ->log('customer_exported');

        return response()->download($path, 'customers-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * Format stored customer coordinates for the export. Teknisi paste the
     * Tikor value ("lat,lng") straight into Google Maps, so values are plain
     * dot-decimal strings (max 7 decimals, no locale separators, no exponent).
     * Missing, non-numeric, out-of-range, or 0,0 placeholder coordinates
     * export as empty cells.
     *
     * @return array{tikor: string, lat: string, lng: string, maps: string}
     */
    private function customerExportCoordinates(mixed $lat, mixed $lng): array
    {
        $empty = ['tikor' => '', 'lat' => '', 'lng' => '', 'maps' => ''];

        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return $empty;
        }

        $latitude = (float) $lat;
        $longitude = (float) $lng;
        if (! is_finite($latitude) || ! is_finite($longitude)
            || abs($latitude) > 90 || abs($longitude) > 180
            || ($latitude == 0.0 && $longitude == 0.0)) {
            return $empty;
        }

        $latText = $this->formatExportCoordinate($latitude);
        $lngText = $this->formatExportCoordinate($longitude);
        $tikor = $latText.','.$lngText;

        return [
            'tikor' => $tikor,
            'lat' => $latText,
            'lng' => $lngText,
            'maps' => 'https://maps.google.com/?q='.$tikor,
        ];
    }

    private function formatExportCoordinate(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 7, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }

    /** @return array<int, string> */
    private function customerExportIdentities(Customer $customer): array
    {
        $identities = [];
        foreach (['code', 'pppoe_user'] as $field) {
            $value = strtoupper(trim((string) $customer->getAttribute($field)));
            if ($value !== '') {
                $identities[] = $field.':'.$value;
            }
        }

        return $identities !== [] ? $identities : ['id:'.$customer->getKey()];
    }

    /**
     * Show the form for creating a new customer
     */
    public function create()
    {
        $areasQuery = Area::select('id', 'name')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, request()->user());

        return Inertia::render('Customers/Create', [
            'prefill' => app(UnlinkedSecretPrefill::class)->fromRequest(request()),
            'packages' => Package::select('id', 'name', 'price', 'mikrotik_profile', 'router_id')->get(),
            'areas' => $areasQuery->get(),
            'olts' => Olt::select('id', 'name', 'router_id')->orderBy('name')->get(),
            'routers' => Router::select('id', 'name')->get(),
        ]);
    }

    /**
     * Store a newly created customer in storage.
     */
    public function store(CustomerStoreRequest $request)
    {
        $validated = $request->validated();
        AreaScope::authorizeAreaId(isset($validated['area_id']) ? (int) $validated['area_id'] : null, $request->user());

        if (filled($validated['pppoe_password'] ?? null)) {
            throw ValidationException::withMessages([
                'pppoe_password' => 'Password PPPoE harus dibuat/diverifikasi melalui MikroTik, bukan diisi langsung ke database.',
            ]);
        }
        unset($validated['pppoe_password']);

        // Auto-generate join date if not provided
        $validated['join_date'] = now();

        // Dari daftar "secret belum terhubung": hubungkan ke secret, tanpa perintah ke MikroTik.
        $stagedId = (int) ($validated['router_staged_customer_id'] ?? 0);
        unset($validated['router_staged_customer_id']);

        // KTP photo: stored only when the customer is really created (no orphan file on a refused save)
        $make = function () use ($request, $validated): Customer {
            $file = $request->file('ktp_photo');
            $path = $file ? $file->storeAs('ktp/'.now()->format('Y/m'), 'customer-'.uniqid().'.'.$file->extension(), 'private') : null;

            return Customer::create($path ? $validated + ['ktp_photo_url' => $path] : $validated); // path in mapped URL column
        };
        $link = app(UnlinkedSecretPrefill::class);
        $customer = $stagedId > 0 ? $link->createLinked($stagedId, $make) : $make();
        if ($stagedId === 0) {
            $this->syncRadiusUser($customer);
        }

        try {
            app(BillingWaEventService::class)->notifyCustomerCreated($customer->fresh(['package', 'area']));
        } catch (\Throwable $e) {
            Log::warning('[BillingWa] notifyCustomerCreated failed: '.$e->getMessage());
        }

        if ($stagedId > 0) {
            return $link->redirectAfterLink($customer);
        }

        return redirect()->route('customers.index')
            ->with('success', 'Customer created successfully.');
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, request()->user());

        $customer->load([
            'package',
            'area',
            'router:id,name,connection_status,is_active',
            'olt:id,name,code,management_ip',
            // C3: hanya 24 invoice terakhir; riwayat lengkap ada di halaman Invoice.
            'invoices' => function ($q) {
                $q->latest('period')->latest('id')->limit(24);
            },
            // Status + reversal wajib ikut agar UI tidak menampilkan transaksi
            // yang dibatalkan/pending sebagai uang masuk.
            'invoices.transactions:id,invoice_id,amount,method,status,paid_at',
            'invoices.transactions.reversals:id,transaction_id,invoice_id,amount,reason,reversed_at',
        ]);
        $customer->loadCount('invoices');

        // Ensure arrays/relations are always JSON-safe for frontend (no null crash on .length / .name)
        $customer->setRelation('invoices', $customer->invoices ?? collect());
        $this->setProtectedMediaUrls($customer);
        // package may be null — frontend handles null

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'pendingTermination' => $this->pendingTerminationPayload($customer, request()->user()),
        ]);
    }

    /**
     * Card data for a guard-deferred termination (roadmap 1.3 PR-4a), null when none.
     *
     * @return array<string, mixed>|null
     */
    private function pendingTerminationPayload(Customer $customer, $user): ?array
    {
        $row = CustomerPendingTermination::query()
            ->where('customer_id', $customer->id)
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->first();
        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'source' => $row->source,
            'source_label' => self::pendingSourceLabel($row),
            'requested_at' => OpsTime::iso($row->requested_at),
            'requested_at_label' => OpsTime::local($row->requested_at)->format('d/m/Y'),
            'last_checked_at' => OpsTime::iso($row->last_checked_at),
            'last_checked_label' => LifecycleTerminationService::ageLabel($row->last_checked_at),
            'last_verdict' => $row->last_verdict,
            'last_reasons' => $row->last_reasons ?? [],
            'last_reason_label' => LifecycleTerminationService::reasonLabel($row->last_reasons),
            'hold_billing' => (bool) $row->hold_billing,
            'can_cancel' => $user ? $user->can('cancelPendingTermination', $customer) : false,
        ];
    }

    private static function pendingSourceLabel(CustomerPendingTermination $row): string
    {
        return match ($row->source) {
            'warehouse_receive', 'warehouse_quick_record' => 'Gudang'.($row->recovery_case_id ? ' #'.$row->recovery_case_id : ''),
            'takeover' => 'Ambil alih ONU',
            'recap_receive', 'recap_reused' => 'Impor rekap',
            default => (string) $row->source,
        };
    }

    /**
     * Cancel a guard-deferred termination: the customer stays subscribed
     * (roadmap 1.3 PR-4a; admin + superadmin, reason >= 10, activity log).
     */
    public function cancelPendingTermination(Request $request, Customer $customer, LifecycleTerminationService $service)
    {
        AreaScope::authorizeCustomer($customer, $request->user());
        Gate::forUser($request->user())->authorize('cancelPendingTermination', $customer);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'pending_id' => ['required', 'integer'],
        ], [
            'reason.required' => 'Alasan pembatalan minimal 10 karakter.',
            'reason.min' => 'Alasan pembatalan minimal 10 karakter.',
        ]);

        // Act only on the row the page showed; a different/newer row must be reviewed first.
        $row = CustomerPendingTermination::query()
            ->where('customer_id', $customer->id)
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->first();
        if (! $row) {
            return back()->with('error', 'Terminate tertunda sudah tidak aktif.');
        }
        if ((int) $row->id !== (int) $validated['pending_id']) {
            return back()->with('error', 'Terminate tertunda sudah berubah. Muat ulang halaman lalu periksa lagi.');
        }

        $result = $service->cancel($row, $request->user(), $validated['reason']);
        $message = LifecycleTerminationService::cancelMessage($result);

        return $result['invoice'] === 'invoice_skipped:error'
            ? back()->with('warning', $message)
            : back()->with('success', $message);
    }

    /**
     * Show the form for editing the customer
     */
    public function edit(Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, request()->user());
        $areasQuery = Area::select('id', 'name')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, request()->user());
        $this->setProtectedMediaUrls($customer);

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
            'packages' => Package::select('id', 'name', 'price', 'mikrotik_profile', 'router_id')->get(),
            'areas' => $areasQuery->get(),
            'olts' => Olt::select('id', 'name', 'router_id')->orderBy('name')->get(),
            'routers' => Router::select('id', 'name')->get(),
            'canEditPppoeUsername' => request()->user()?->isGlobalAdmin() ?? false,
        ]);
    }

    /**
     * Update the specified customer
     */
    public function update(CustomerUpdateRequest $request, Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, $request->user());

        $validated = $request->validated();
        AreaScope::authorizeAreaId(isset($validated['area_id']) ? (int) $validated['area_id'] : null, $request->user());

        // Roadmap 1.3 lifecycle guard on a manual status change to terminated
        // (same rules + superadmin override as the archive). Checked before any
        // side effect (password change, KTP upload) so a denial changes nothing.
        $terminateVerdict = null;
        $gate = app(ManualLifecycleGate::class);
        if (($validated['status'] ?? null) === 'terminated' && (string) $customer->status !== 'terminated') {
            $request->validate([
                'lifecycle_override' => ['nullable', 'boolean'],
                'lifecycle_override_reason' => ['nullable', 'string', 'max:1000'],
            ]);
            $terminateVerdict = $gate->gate(
                ManualLifecycleGate::ACTION_TERMINATE,
                $customer,
                $request->user(),
                'customer_update_status',
                $request->boolean('lifecycle_override'),
                (string) $request->input('lifecycle_override_reason', ''),
            );
            if ($terminateVerdict['forbidden']) {
                abort(403, 'Hanya superadmin yang boleh override pagar pelanggan.');
            }
            if (! $terminateVerdict['allowed']) {
                $errors = ['lifecycle_guard' => $terminateVerdict['message']];
                if (isset($terminateVerdict['field_errors']['override_reason'])) {
                    $errors['lifecycle_override_reason'] = $terminateVerdict['field_errors']['override_reason'];
                }

                return back()->withErrors($errors)->withInput();
            }
        }

        $requestedPassword = trim((string) ($validated['pppoe_password'] ?? ''));
        unset($validated['pppoe_password']);

        if ($requestedPassword !== '') {
            $requestedUsername = trim((string) ($validated['pppoe_user'] ?? $customer->pppoe_user));
            if ($requestedUsername !== trim((string) $customer->pppoe_user)) {
                throw ValidationException::withMessages([
                    'pppoe_password' => 'Perubahan username dan password harus diproses melalui workflow router yang terkontrol.',
                ]);
            }

            app(RouterPppoeCredentialService::class)->changeCustomerPassword($customer, $requestedPassword);
        }

        // Handle KTP photo upload
        if ($request->hasFile('ktp_photo')) {
            // Delete old file if exists and is a local path (not a full URL)
            $oldKtpPath = trim((string) $customer->getRawOriginal('ktp_photo_url'));
            if ($oldKtpPath !== '' && ! filter_var($oldKtpPath, FILTER_VALIDATE_URL)) {
                \Storage::disk('private')->delete($oldKtpPath);
                \Storage::disk('public')->delete($oldKtpPath);
            }

            $file = $request->file('ktp_photo');
            $filename = 'customer-'.$customer->id.'-'.uniqid().'.'.$file->extension();
            $path = $file->storeAs('ktp/'.now()->format('Y/m'), $filename, 'private');

            $validated['ktp_photo_url'] = $path;
        }

        $oldAcsTag = app(GenieAcsService::class)->preferredCustomerTag($customer);

        DB::transaction(function () use ($customer, $validated, $terminateVerdict, $gate, $request) {
            if ($terminateVerdict !== null && $terminateVerdict['overridden']) {
                $gate->logOverride(ManualLifecycleGate::ACTION_TERMINATE, $customer, $request->user(), 'customer_update_status', $terminateVerdict['decision'], $terminateVerdict['reason']);
            }
            // A10: only a status->terminated change that passed the guard above is allowed.
            LifecycleBackstop::allow(
                $terminateVerdict !== null ? [(int) $customer->getKey()] : [],
                'customer_update_status',
                fn () => $customer->update($validated),
            );
        });
        $fresh = $customer->fresh();

        // P7: End-to-end sync when PPPoE username or Customer Name is updated (RT change / mis-input fix)
        // Only the ACS tag is synced, and only when the preferred tag (PPPoE first, then name) really changed.
        // olt_onus.onu_name is left alone: the OLT owns it and the collector would revert it.
        try {
            $newAcsTag = app(GenieAcsService::class)->preferredCustomerTag($fresh);
            if ($newAcsTag !== '' && $newAcsTag !== $oldAcsTag) {
                $this->queueAcsTagAfterCustomerUpdate($fresh, $newAcsTag);
            }
        } catch (\Throwable $e) {
            Log::warning('[CustomerUpdate] ACS tag sync warning: '.$e->getMessage());
        }

        $this->syncRadiusUser($fresh);

        $invoiceSync = $this->syncUnpaidInvoiceAmounts($fresh);

        return redirect()->route('customers.index')
            ->with('success', sprintf(
                'Pelanggan diperbarui. %d invoice belum bayar ikut disinkronkan ke harga paket%s.',
                $invoiceSync['updated'],
                $invoiceSync['skipped'] > 0 ? "; {$invoiceSync['skipped']} invoice dilewati karena sudah memiliki transaksi" : ''
            ));
    }

    /**
     * Queue (not run inline) the ACS tag push for a customer's ONU, once per serial.
     * Skipped when the serial is shared with another customer or the ACS device belongs to someone else.
     */
    private function queueAcsTagAfterCustomerUpdate(Customer $customer, string $tag): void
    {
        $serial = strtoupper((string) preg_replace('/[\s:.\-]/', '', (string) ($customer->onu_serial ?? '')));
        if ($serial === '') {
            return;
        }

        $dupCustomer = Customer::query()
            ->where('id', '!=', $customer->id)
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(onu_serial, ''), '-', ''), ' ', ''), ':', ''), '.', '')) = ?", [$serial])
            ->exists();
        $otherDevice = GenieAcsDevice::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$serial])
            ->whereNotNull('customer_id')
            ->where('customer_id', '!=', $customer->id)
            ->exists();
        if ($dupCustomer || $otherDevice) {
            Log::warning('[CustomerUpdate] ACS tag skipped: serial belongs to another customer', ['customer_id' => $customer->id, 'serial' => $serial]);

            return;
        }

        ProcessGenieAcsInformJob::dispatch(['serial' => $serial, 'tag' => $tag, 'event' => 'customer_rename']);
    }

    private function setProtectedMediaUrls(Customer $customer): void
    {
        foreach ([
            'ktp_photo_url' => 'media.customers.ktp',
            'odp_photo_url' => 'media.customers.odp',
        ] as $field => $routeName) {
            $path = trim((string) $customer->getRawOriginal($field));
            if ($path === '') {
                $customer->setAttribute($field, null);
            } elseif (filter_var($path, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $path)) {
                // Frontend does window.open()/href on this value; legacy sync and
                // XLSX import can write arbitrary strings here, so only allow
                // http(s) — never javascript:/data: schemes.
                $customer->setAttribute($field, $path);
            } else {
                $customer->setAttribute($field, URL::temporarySignedRoute(
                    $routeName,
                    now()->addMinutes(10),
                    ['customer' => $customer->getKey()],
                ));
            }
        }
    }

    /**
     * Keep billing aligned with the selected package without rewriting payment history.
     * Invoices with any transaction are intentionally left untouched for audit safety.
     */
    public function bulkUpdatePackage(Request $request)
    {
        $validated = $request->validate([
            'customer_ids' => ['required', 'array', 'min:1', 'max:100'],
            'customer_ids.*' => ['integer', 'distinct'],
            'package_id' => ['required', 'integer', 'exists:packages,id'],
        ]);

        $ids = collect($validated['customer_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $customers = Customer::ebilling()->whereIn('id', $ids)->get();
        abort_if($customers->count() !== $ids->count(), 422, 'Sebagian pelanggan tidak tersedia di list operasional.');

        $updatedCustomers = 0;
        $updatedInvoices = 0;
        $skippedInvoices = 0;
        $package = Package::findOrFail($validated['package_id']);

        DB::transaction(function () use ($customers, $package, &$updatedCustomers, &$updatedInvoices, &$skippedInvoices, $request) {
            foreach ($customers as $customer) {
                AreaScope::authorizeCustomer($customer, $request->user());
                if ((int) $customer->package_id !== (int) $package->id) {
                    $customer->update(['package_id' => $package->id]);
                    $updatedCustomers++;
                }

                $sync = $this->syncUnpaidInvoiceAmounts($customer->fresh());
                $updatedInvoices += $sync['updated'];
                $skippedInvoices += $sync['skipped'];
            }
        });

        return back()->with('success', sprintf(
            '%d pelanggan diproses. %d invoice disinkronkan ke %s%s.',
            $customers->count(),
            $updatedInvoices,
            $package->name,
            $skippedInvoices > 0 ? "; {$skippedInvoices} invoice dilewati karena sudah memiliki transaksi" : ''
        ));
    }

    private function syncUnpaidInvoiceAmounts(Customer $customer): array
    {
        $customer->loadMissing('package');
        $price = $customer->package?->price;
        if ($price === null) {
            return ['updated' => 0, 'skipped' => 0];
        }

        $eligible = $customer->invoices()
            ->where('status', 'unpaid')
            ->whereDoesntHave('transactions')
            ->get(['id', 'amount']);
        $skipped = $customer->invoices()
            ->where('status', 'unpaid')
            ->whereHas('transactions')
            ->count();
        $updated = 0;

        foreach ($eligible as $invoice) {
            if ((float) $invoice->amount !== (float) $price) {
                $invoice->update(['amount' => $price]);
                $updated++;
            }
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Soft-delete → arsip (tidak hard-delete). List utama tidak menampilkan baris ini.
     * Tidak menyentuh PSB WA / secret MT otomatis (aman).
     */
    public function destroy(Request $request, Customer $customer, ManualLifecycleGate $gate)
    {
        AreaScope::authorizeCustomer($customer, $request->user());

        if ($customer->status === 'active') {
            return back()->with('error', 'Pelanggan aktif tidak bisa diarsip. Isolir/dismantle dulu, atau nonaktifkan status.');
        }

        // Roadmap 1.3 lifecycle guard: PPPoE active / ONU working / traffic in the
        // last 2 days / unknown => refused. Only a superadmin with a written
        // reason may override (UI only, logged with the guard evidence).
        $request->validate([
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $verdict = $gate->gate(
            ManualLifecycleGate::ACTION_ARCHIVE,
            $customer,
            $request->user(),
            'customer_archive',
            $request->boolean('override'),
            (string) $request->input('override_reason', ''),
        );
        if ($verdict['forbidden']) {
            abort(403, 'Hanya superadmin yang boleh override pagar pelanggan.');
        }
        if (! $verdict['allowed']) {
            return back()->withErrors(['lifecycle_guard' => $verdict['message']] + $verdict['field_errors']);
        }

        DB::transaction(function () use ($customer, $request, $gate, $verdict) {
            if ($verdict['overridden']) {
                $gate->logOverride(ManualLifecycleGate::ACTION_ARCHIVE, $customer, $request->user(), 'customer_archive', $verdict['decision'], $verdict['reason']);
            }

            // A10: the guard verdict above allowed (or a superadmin overrode) this archive.
            LifecycleBackstop::allow([(int) $customer->getKey()], 'customer_archive', function () use ($customer, $request) {
                // Mark as terminated before archive; production status enum does not accept "dismantled".
                if ((string) $customer->status !== 'terminated') {
                    $customer->status = 'terminated';
                    $customer->save();
                }

                activity()
                    ->causedBy($request->user())
                    ->performedOn($customer)
                    ->withProperties([
                        'pppoe_user' => $customer->pppoe_user,
                        'status' => $customer->status,
                    ])
                    ->log('customer_archived');

                $customer->delete(); // SoftDeletes
            });
        });

        return redirect()->route('customers.index')
            ->with('success', 'Pelanggan dipindah ke Arsip (bisa di-restore).');
    }

    /**
     * Restore pelanggan dari arsip (soft-delete).
     */
    public function restore(int $customer)
    {
        $model = Customer::withTrashed()->findOrFail($customer);
        AreaScope::authorizeCustomer($model, request()->user());

        if (! $model->trashed()) {
            return redirect()->route('customers.index')
                ->with('error', 'Pelanggan tidak dalam arsip.');
        }

        $model->restore();

        activity()
            ->causedBy(request()->user())
            ->performedOn($model)
            ->log('customer_restored_from_archive');

        return redirect()->route('customers.index', ['archive' => 1])
            ->with('success', "Pelanggan {$model->name} di-restore dari arsip. Status: {$model->status}.");
    }

    /**
     * Isolate the customer (block internet via Mikrotik)
     */
    public function isolate(Customer $customer, MikrotikService $mikrotik)
    {
        AreaScope::authorizeCustomer($customer, request()->user());

        if ($customer->router_id && $customer->pppoe_user) {
            try {
                $mikrotik->isolateCustomerNow($customer, 10);
                $this->syncRadiusUser($customer->fresh());

                return back()->with('success', "Customer {$customer->name} isolated on router.");
            } catch (\Throwable $e) {
                return back()->with('error', "Mikrotik Error: {$e->getMessage()}");
            }
        }

        activity()
            ->causedBy(request()->user())
            ->performedOn($customer)
            ->withProperties(['reason' => 'missing_router_or_pppoe'])
            ->log('manual_isolation_blocked');

        return back()->with('error', 'Cannot isolate customer: router and PPPoE username are required for MikroTik enforcement.');
    }

    /**
     * Reconnect the customer (restore internet via Mikrotik)
     */
    public function reconnect(Customer $customer, MikrotikService $mikrotik)
    {
        AreaScope::authorizeCustomer($customer, request()->user());

        if ($customer->router_id && $customer->pppoe_user) {
            try {
                $mikrotik->reconnectCustomerNow($customer, 10);
                $this->syncRadiusUser($customer->fresh());

                return back()->with('success', "Customer {$customer->name} reconnected on router.");
            } catch (\Throwable $e) {
                return back()->with('error', "Mikrotik Error: {$e->getMessage()}");
            }
        }

        activity()
            ->causedBy(request()->user())
            ->performedOn($customer)
            ->withProperties(['reason' => 'missing_router_or_pppoe'])
            ->log('manual_reconnection_blocked');

        return back()->with('error', 'Cannot reconnect customer: router and PPPoE username are required for MikroTik enforcement.');
    }

    private function archiveCount(Request $request): int
    {
        $q = Customer::ebilling()->onlyTrashed();
        AreaScope::applyToCustomers($q, $request->user());

        return (int) $q->count();
    }

    private function customerIndexQuery(Request $request, bool $allowArchive = true)
    {
        $archive = $allowArchive && ($request->boolean('archive') || $request->input('view') === 'archive');

        $query = Customer::ebilling()
            ->select([
                'id', 'code', 'name', 'phone', 'address', 'status', 'pppoe_user',
                'package_id', 'area_id', 'router_id', 'is_online', 'join_date',
                'mikrotik_sync_status', 'mikrotik_synced_at', 'mikrotik_profile',
                'pppoe_local_address', 'pppoe_remote_address', 'created_at', 'deleted_at',
            ])
            ->with([
                'package:id,name,price',
                'area:id,name',
                'router:id,name',
            ])->withCount([
                'invoices as unpaid_periods_count' => function ($query) {
                    $query->where('status', 'unpaid');
                },
            ])->withMin([
                'invoices as next_unpaid_due_date' => function ($query) {
                    $query->where('status', 'unpaid');
                },
            ], 'due_date');

        if ($archive) {
            $query->onlyTrashed();
        }

        AreaScope::applyToCustomers($query, $request->user());

        // Search: text fields + related area/package (so "gadang" matches area SKYNET-GADANG)
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('pppoe_user', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('area', function ($aq) use ($search) {
                        $aq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('package', function ($pq) use ($search) {
                        $pq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Package filter
        if ($request->has('package_id') && $request->package_id) {
            $query->where('package_id', $request->package_id);
        }

        // Area filter
        if ($request->has('area_id') && $request->area_id) {
            $query->where('area_id', $request->area_id);
        }

        // MikroTik sync filter. This is based on the latest persisted router scan only.
        if ($request->has('mikrotik_sync') && $request->mikrotik_sync !== 'all') {
            $status = $request->mikrotik_sync;
            if (in_array($status, ['unknown', 'synced', 'missing'], true)) {
                $query->where('mikrotik_sync_status', $status);
            }
        }

        if ($request->has('unpaid_periods') && $request->unpaid_periods !== 'all') {
            if ($request->unpaid_periods === '3plus') {
                $query->has('invoices', '>=', 3, 'and', function ($invoiceQuery) {
                    $invoiceQuery->where('status', 'unpaid');
                });
            }
        }

        // Sorting
        $defaultSort = $archive ? 'deleted_at' : 'join_date';
        $sortField = $request->get('sort', $defaultSort);
        $sortDirection = $request->get('direction', 'desc');
        if (! in_array($sortField, ['code', 'name', 'status', 'join_date', 'created_at', 'deleted_at'], true)) {
            $sortField = $defaultSort;
        }
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = 'desc';
        }
        $query->orderBy($sortField, $sortDirection);

        return $query;
    }

    private function syncRadiusUser(Customer $customer): void
    {
        try {
            app(RadiusUserService::class)->syncCustomer($customer);
        } catch (\Throwable $e) {
            Log::warning('Failed to sync customer to FreeRADIUS.', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * API: Search customers for Combobox (ticket create)
     */
    public function search(Request $request)
    {
        $terms = array_values(array_filter(preg_split('/\s+/', trim((string) $request->query('q', ''))) ?: []));

        $query = Customer::query()
            ->ebilling()
            ->with(['area:id,name', 'package:id,name'])
            ->select('id', 'code', 'name', 'phone', 'address', 'pppoe_user', 'geo_lat', 'geo_long', 'area_id', 'package_id', 'status');
        AreaScope::applyToCustomers($query, $request->user());

        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            $query->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('pppoe_user', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhereHas('area', function ($aq) use ($like) {
                        $aq->where('name', 'like', $like);
                    })
                    ->orWhereHas('package', function ($pq) use ($like) {
                        $pq->where('name', 'like', $like);
                    });
            });
        }

        $first = $terms[0] ?? '';
        if ($first !== '') {
            $prefix = $first.'%';
            $query->orderByRaw(
                'CASE WHEN name LIKE ? THEN 0 WHEN code LIKE ? THEN 1 WHEN pppoe_user LIKE ? THEN 2 ELSE 3 END',
                [$prefix, $prefix, $prefix]
            );
        }

        $customers = $query->orderBy('name')->limit(50)->get();

        return response()->json($customers);
    }
}
