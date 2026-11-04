<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsUpdateRequest;
use App\Models\Area;
use App\Models\Setting;
use App\Services\BillingWaTemplateService;
use App\Services\DueDayResolver;
use App\Services\EvolutionApiService;
use App\Services\FinanceBulkWaService;
use App\Services\TechnicianProgressTemplateService;
use App\Services\Wa\WaRoutingOverview;
use App\Support\CompanyContact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingController extends Controller
{
    /**
     * Display the settings page.
     */
    public function index()
    {
        // Settings may contain webhook tokens, payment keys, and device
        // credentials. The page does not consume this legacy prop, so expose
        // metadata only and never serialize raw values into the Inertia HTML.
        $settings = Setting::query()
            ->get(['key', 'group', 'type', 'label'])
            ->groupBy('group');

        // WA status/groups di-load via API di frontend — hindari blocking Evolution saat render halaman.
        $waGroups = [];
        $evoConnected = false;
        $evoState = 'unknown';

        // Evolution API config — never send raw API key to the browser (Phase 1 security).
        $rawEvoKey = (string) config('services.evolution_api.api_key', '');
        $evoConfig = [
            'evo_base_url' => config('services.evolution_api.base_url'),
            'evo_api_key' => $rawEvoKey !== '' ? '••••••••' : '',
            'evo_api_key_set' => $rawEvoKey !== '',
            'evo_instance_name' => config('services.evolution_api.instance_name'),
            'evo_delay_ms' => (int) config('services.evolution_api.delay_ms', 5000),
            'evo_group_id' => config('services.evolution_api.group_id', ''),
        ];

        $billingWa = app(BillingWaTemplateService::class)->configSnapshot();
        $financeBulkWa = app(FinanceBulkWaService::class)->configSnapshot();
        $technicianProgress = app(TechnicianProgressTemplateService::class)->snapshot(null, null, null, false);
        $areas = Area::query()->orderBy('name')->get(['id', 'name']);
        $enforcement = $this->billingEnforcementSnapshot();
        $isolationPortal = $this->isolationPortalSnapshot();

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
            'grouped_settings' => [
                'billing' => [
                    'company_name' => Setting::get('company_name', 'Skynet Network'),
                    'company_address' => Setting::get('company_address', ''),
                    'company_email' => CompanyContact::email(),
                    'company_phone' => CompanyContact::phone(),
                    'company_wa_admin' => CompanyContact::waAdmin(),
                ],
                'wa_gateway' => $evoConfig,
            ],
            'billing_enforcement' => $enforcement,
            'isolation_portal' => $isolationPortal,
            'isolation_portal_test_ip_default' => (string) config('site.isolation_portal_test_ip'),
            'wa_gateway' => [
                'connected' => $evoConnected,
                'state' => $evoState,
                'groups' => $waGroups,
                'config' => array_merge($evoConfig, [
                    // Real keys (the old wa_ticket_group_id / wa_report_group_id never existed, so the card showed blank).
                    'ticket_group_id' => Setting::get('wa_ticket_group_jid', ''),
                    'report_group_id' => Setting::get('wa_report_group_jid', ''),
                    'report_interval_hours' => (int) Setting::get('wa_report_interval_hours', '2'),
                    'notify_ticket_created' => (bool) Setting::get('wa_notify_ticket_created', '1'),
                    'notify_ticket_status_change' => (bool) Setting::get('wa_notify_ticket_status_change', '1'),
                    'notify_report' => (bool) Setting::get('wa_notify_report', '1'),
                ]),
            ],
            'billing_wa' => $billingWa,
            'finance_bulk_wa' => $financeBulkWa,
            'technician_progress' => $technicianProgress,
            'areas' => $areas,
        ]);
    }

    /**
     * Billing enforcement: isolir/dismantle thresholds + dry-run kill-switch.
     * Dry-run default ON — mass isolir MT only after admin turns dry-run off.
     */
    public function saveBillingEnforcement(Request $request)
    {
        $validated = $request->validate([
            'isolir_after_days' => 'required|integer|min:0|max:365',
            'dismantle_after_days' => 'required|integer|min:1|max:730',
            'dry_run' => 'required|boolean',
            // Optional so older clients that only post thresholds keep working.
            'fixed_due_day' => 'nullable|integer|min:1|max:31',
            'fixed_due_day_router_ids' => ['nullable', 'string', 'max:500', 'regex:/^[\d\s,]*$/'],
            'fixed_due_day_area_ids' => ['nullable', 'string', 'max:500', 'regex:/^[\d\s,]*$/'],
        ]);

        $isolir = (int) $validated['isolir_after_days'];
        $dismantle = (int) $validated['dismantle_after_days'];
        if ($dismantle < $isolir) {
            return response()->json([
                'success' => false,
                'message' => 'H+ dismantle harus ≥ H+ isolir.',
            ], 422);
        }

        Setting::set('billing_isolir_after_days', $isolir, 'integer', 'billing', 'Isolir after due H+N days');
        Setting::set('billing_dismantle_after_days', $dismantle, 'integer', 'billing', 'Dismantle after due H+N days');
        Setting::set('billing_grace_period_days', $isolir, 'integer', 'billing', 'Grace Period (Days) — alias isolir');
        Setting::set(
            'billing_enforcement_dry_run',
            ! empty($validated['dry_run']) ? '1' : '0',
            'boolean',
            'billing',
            'Enforcement dry-run (1=log only)'
        );
        if (array_key_exists('fixed_due_day', $validated) && $validated['fixed_due_day'] !== null) {
            Setting::set('billing_fixed_due_day', (int) $validated['fixed_due_day'], 'integer', 'billing', 'Fixed due day for new customers on listed routers/areas');
        }
        foreach (['router' => 'fixed_due_day_router_ids', 'area' => 'fixed_due_day_area_ids'] as $kind => $field) {
            if (array_key_exists($field, $validated)) {
                Setting::set(
                    'billing_'.$field,
                    implode(',', DueDayResolver::parseIds($validated[$field] ?? '')),
                    'text',
                    'billing',
                    "Fixed due day {$kind} ids (comma list)"
                );
            }
        }

        return response()->json([
            'success' => true,
            'billing_enforcement' => $this->billingEnforcementSnapshot(),
            'message' => ! empty($validated['dry_run'])
                ? 'Disimpan. DRY RUN aktif — isolir massal hanya log, tidak putus MT.'
                : 'Disimpan. DRY RUN mati — scheduler akan isolir/dismantle NYATA. Hati-hati.',
        ]);
    }

    public function saveIsolationPortal(Request $request)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'test_mode' => ['required', 'boolean'],
            'test_ip' => ['nullable', 'ip', 'max:45'],
            'mode' => ['nullable', 'string', 'in:general,customer'],
            // `present`, not `required`: Laravel's `required` rejects an empty
            // array, but [] is the valid fail-closed allowlist the UI sends
            // when every area is unchecked.
            'areas' => ['present', 'array', 'max:500'],
            'areas.*' => ['string', 'max:160'],
            // Public contact info shown on the isolation page (/isolir). Optional
            // keys: omitted = keep the stored value, empty string = hide it.
            'wa_admin' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+\-\s().]*$/'],
            'office_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'office_hours' => ['sometimes', 'nullable', 'string', 'max:160'],
        ], [
            'wa_admin.regex' => 'Nomor WA CS hanya boleh berisi angka, spasi, +, -, titik, atau tanda kurung.',
        ]);

        $availableAreas = Area::query()
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && trim($name) !== '')
            ->mapWithKeys(fn (string $name) => [$this->normalizeAreaName($name) => trim($name)]);

        $areas = [];
        foreach ($validated['areas'] as $area) {
            $normalized = $this->normalizeAreaName($area);
            if ($normalized === '*') {
                $areas = ['*'];
                break;
            }

            if (! $availableAreas->has($normalized)) {
                return response()->json([
                    'success' => false,
                    'message' => "Area tidak ditemukan: {$area}",
                ], 422);
            }

            $areas[] = $availableAreas->get($normalized);
        }

        $areas = array_values(array_unique($areas));
        if ($validated['test_mode'] && trim((string) ($validated['test_ip'] ?? '')) === '') {
            return response()->json([
                'success' => false,
                'message' => 'TEST IP wajib diisi saat test mode aktif.',
            ], 422);
        }

        Setting::set('isolation_portal_enabled', $validated['enabled'] ? '1' : '0', 'boolean', 'isolation_portal', 'Portal isolir enabled');
        Setting::set('isolation_portal_test_mode', $validated['test_mode'] ? '1' : '0', 'boolean', 'isolation_portal', 'Portal isolir test mode');
        Setting::set('isolation_portal_test_ip', trim((string) ($validated['test_ip'] ?? '')), 'text', 'isolation_portal', 'Portal isolir test IP');
        Setting::set(
            'isolation_portal_mode',
            ($validated['mode'] ?? 'general') === 'customer' ? 'customer' : 'general',
            'text',
            'isolation_portal',
            'Portal isolir mode (general=/isolir, customer=/isolated)'
        );
        Setting::set('isolation_portal_areas', $areas, 'json', 'isolation_portal', 'Portal isolir area allowlist');
        foreach ([
            'wa_admin' => 'Portal isolir: nomor WhatsApp CS',
            'office_address' => 'Portal isolir: alamat kantor',
            'office_hours' => 'Portal isolir: jam buka kantor',
        ] as $field => $description) {
            if (array_key_exists($field, $validated)) {
                Setting::set('isolation_portal_'.$field, trim((string) ($validated[$field] ?? '')), 'text', 'isolation_portal', $description);
            }
        }

        return response()->json([
            'success' => true,
            'isolation_portal' => $this->isolationPortalSnapshot(),
            'message' => 'Setting portal isolir disimpan. Area kosong tetap berarti portal customer nonaktif.',
        ]);
    }

    /**
     * @return array{enabled: bool, test_mode: bool, test_ip: string, mode: string, wa_admin: string, office_address: string, office_hours: string, areas: array<int, string>, all_areas: bool, area_count: int}
     */
    protected function isolationPortalSnapshot(): array
    {
        $areas = Setting::get(
            'isolation_portal_areas',
            config('services.isolation_portal.areas', []),
        );

        if (is_string($areas)) {
            $areas = json_decode($areas, true);
        }

        $areas = is_array($areas)
            ? array_values(array_filter($areas, fn ($area) => is_string($area) && trim($area) !== ''))
            : [];
        $allAreas = in_array('*', $areas, true);

        return [
            'enabled' => $this->settingBoolean('isolation_portal_enabled', (bool) config('services.isolation_portal.enabled', false)),
            'test_mode' => $this->settingBoolean('isolation_portal_test_mode', (bool) config('services.isolation_portal.test_mode', true)),
            'test_ip' => trim((string) Setting::get('isolation_portal_test_ip', config('services.isolation_portal.test_ip', ''))),
            'mode' => strtolower(trim((string) Setting::get('isolation_portal_mode', config('services.isolation_portal.mode', 'general')))) === 'customer' ? 'customer' : 'general',
            'wa_admin' => trim((string) Setting::get('isolation_portal_wa_admin', config('services.isolation_portal.wa_admin', ''))),
            'office_address' => trim((string) Setting::get('isolation_portal_office_address', config('services.isolation_portal.office_address', ''))),
            'office_hours' => trim((string) Setting::get('isolation_portal_office_hours', config('services.isolation_portal.office_hours', ''))),
            'areas' => $areas,
            'all_areas' => $allAreas,
            'area_count' => $allAreas ? Area::query()->count() : count($areas),
        ];
    }

    private function settingBoolean(string $key, bool $fallback): bool
    {
        $value = filter_var(Setting::get($key, $fallback), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $value ?? $fallback;
    }

    private function normalizeAreaName(?string $area): string
    {
        $area = trim((string) $area);

        return strtolower(preg_replace('/\s+/', ' ', $area) ?? $area);
    }

    /**
     * @return array<string, mixed>
     */
    protected function billingEnforcementSnapshot(): array
    {
        $dry = Setting::get('billing_enforcement_dry_run', true);
        $dryRun = is_bool($dry)
            ? $dry
            : ! in_array(strtolower(trim((string) $dry)), ['0', 'false', 'no', 'off'], true);

        $isolir = (int) Setting::get('billing_isolir_after_days', 0);
        if ($isolir <= 0) {
            $isolir = (int) Setting::get('billing_grace_period_days', 3) ?: 3;
        }
        $dismantle = (int) Setting::get('billing_dismantle_after_days', 33) ?: 33;

        return [
            'isolir_after_days' => $isolir,
            'dismantle_after_days' => $dismantle,
            'dry_run' => $dryRun,
            'last_run' => Setting::get('billing_enforcement_last_run', null),
            'fixed_due_day' => (int) Setting::get('billing_fixed_due_day', DueDayResolver::DEFAULT_FIXED_DAY),
            'fixed_due_day_router_ids' => implode(',', DueDayResolver::parseIds(Setting::get('billing_fixed_due_day_router_ids', DueDayResolver::DEFAULT_ROUTER_IDS))),
            'fixed_due_day_area_ids' => implode(',', DueDayResolver::parseIds(Setting::get('billing_fixed_due_day_area_ids', DueDayResolver::DEFAULT_AREA_IDS))),
        ];
    }

    /**
     * Save billing WA auto-broadcast templates + toggles.
     * Isolation toggle is accepted but never enables send (phase 1).
     */
    public function saveBillingWa(Request $request)
    {
        $validated = $request->validate([
            'payment_enabled' => 'nullable|boolean',
            'customer_created_enabled' => 'nullable|boolean',
            'isolation_enabled' => 'nullable|boolean', // ignored for send
            'payment_template' => 'nullable|string|max:4000',
            'customer_created_template' => 'nullable|string|max:4000',
            'isolation_template' => 'nullable|string|max:4000',
            'area_templates' => 'nullable|array',
        ]);

        Setting::set('billing_wa_payment_enabled', ! empty($validated['payment_enabled']) ? '1' : '0', 'boolean', 'billing_wa', 'WA auto: pembayaran');
        Setting::set('billing_wa_customer_created_enabled', ! empty($validated['customer_created_enabled']) ? '1' : '0', 'boolean', 'billing_wa', 'WA auto: pelanggan baru');
        // Force off — isolir broadcast not executed this phase
        Setting::set('billing_wa_isolation_enabled', '0', 'boolean', 'billing_wa', 'WA auto: isolir (belum aktif)');

        if (array_key_exists('payment_template', $validated)) {
            Setting::set('billing_wa_payment_template', (string) ($validated['payment_template'] ?? ''), 'text', 'billing_wa', 'Template WA pembayaran');
        }
        if (array_key_exists('customer_created_template', $validated)) {
            Setting::set('billing_wa_customer_created_template', (string) ($validated['customer_created_template'] ?? ''), 'text', 'billing_wa', 'Template WA pelanggan baru');
        }
        if (array_key_exists('isolation_template', $validated)) {
            Setting::set('billing_wa_isolation_template', (string) ($validated['isolation_template'] ?? ''), 'text', 'billing_wa', 'Template WA isolir (scaffold)');
        }
        if (array_key_exists('area_templates', $validated)) {
            Setting::set('billing_wa_area_templates', $validated['area_templates'] ?? [], 'json', 'billing_wa', 'Template WA per area');
        }

        return response()->json([
            'success' => true,
            'billing_wa' => app(BillingWaTemplateService::class)->configSnapshot(),
            'message' => 'Broadcast billing WA disimpan. Isolir WA belum dijalankan (scaffold only).',
        ]);
    }

    public function setBroadcastLive(Request $request)
    {
        $validated = $request->validate([
            'live' => 'required|boolean',
        ]);

        Setting::set(
            'wa_broadcast_writes_enabled',
            ! empty($validated['live']) ? '1' : '0',
            'boolean',
            'whatsapp',
            'WA broadcast live sending enabled'
        );

        return response()->json([
            'success' => true,
            'billing_wa' => app(BillingWaTemplateService::class)->configSnapshot(),
            'message' => ! empty($validated['live'])
                ? 'Broadcast WA billing LIVE. Broadcast berikutnya akan dikirim nyata.'
                : 'Broadcast WA billing kembali DRY RUN. Broadcast berikutnya tidak dikirim.',
        ]);
    }

    public function setBillingWaLive(Request $request)
    {
        $validated = $request->validate(['live' => 'required|boolean']);
        Setting::set(
            'wa_billing_writes_enabled',
            ! empty($validated['live']) ? '1' : '0',
            'boolean',
            'whatsapp',
            'WA automatic billing live sending enabled'
        );

        return response()->json([
            'success' => true,
            'billing_wa' => app(BillingWaTemplateService::class)->configSnapshot(),
            'message' => ! empty($validated['live'])
                ? 'WA pembayaran otomatis LIVE.'
                : 'WA pembayaran otomatis DRY RUN.',
        ]);
    }

    public function saveFinanceBulkWa(Request $request, FinanceBulkWaService $bulkWa)
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'dry_run' => ['required', 'boolean'],
            'max_recipients' => ['required', 'integer', 'min:1', 'max:1000'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:600'],
            'confirmation' => ['nullable', 'string', 'in:KIRIM WA TAGIHAN'],
        ]);

        if (! empty($validated['enabled']) && empty($validated['dry_run'])
            && ($validated['confirmation'] ?? null) !== FinanceBulkWaService::CONFIRMATION_TEXT) {
            return response()->json([
                'success' => false,
                'message' => 'Ketik KIRIM WA TAGIHAN untuk mengaktifkan pengiriman nyata.',
            ], 422);
        }

        Setting::set('finance_bulk_wa_enabled', ! empty($validated['enabled']) ? '1' : '0', 'boolean', 'finance_bulk_wa', 'WA tagihan massal aktif');
        Setting::set('finance_bulk_wa_dry_run', ! empty($validated['dry_run']) ? '1' : '0', 'boolean', 'finance_bulk_wa', 'WA tagihan massal dry-run');
        Setting::set('finance_bulk_wa_max_recipients', (int) $validated['max_recipients'], 'integer', 'finance_bulk_wa', 'Batas penerima WA tagihan massal');
        Setting::set('finance_bulk_wa_rate_limit_per_minute', (int) $validated['rate_limit_per_minute'], 'integer', 'finance_bulk_wa', 'Rate limit WA tagihan massal per menit');
        Setting::set('finance_bulk_wa_allowed_roles', ['global_admin', 'superadmin'], 'json', 'finance_bulk_wa', 'Role WA tagihan massal');

        activity('finance-bulk-wa.settings')
            ->withProperties([
                'enabled' => ! empty($validated['enabled']),
                'dry_run' => ! empty($validated['dry_run']),
                'max_recipients' => (int) $validated['max_recipients'],
                'rate_limit_per_minute' => (int) $validated['rate_limit_per_minute'],
                'result' => 'saved',
            ])
            ->log('finance_bulk_wa_settings_saved');

        return response()->json([
            'success' => true,
            'finance_bulk_wa' => $bulkWa->configSnapshot(),
            'message' => ! empty($validated['enabled']) && ! empty($validated['dry_run'])
                ? 'WA tagihan massal aktif dalam dry-run. Provider tidak dipanggil.'
                : (! empty($validated['enabled'])
                    ? 'WA tagihan massal LIVE. Gunakan preview dan cap penerima sebelum execute.'
                    : 'WA tagihan massal disabled.'),
        ]);
    }

    /**
     * Update settings.
     */
    public function update(SettingsUpdateRequest $request)
    {
        $validated = $request->validated();

        foreach ($validated['settings'] as $item) {
            Setting::set(
                $item['key'],
                $item['value'],
                $item['type'],
                $item['group']
            );
        }

        return back()->with('success', 'Settings updated successfully.');
    }

    // =====================================================
    // WA Gateway — Pairing / Re-pair (superadmin)
    // =====================================================

    /**
     * Resolve EvolutionApiService for target channel (ops|ticket|billing)
     */
    protected function resolveEvoInstance(Request $request): EvolutionApiService
    {
        // A pairing card names its exact instance: pair/logout/QR/test must hit that instance even when
        // the card's function currently routes elsewhere (e.g. Nomor 3 while Ticket uses Nomor 1).
        $instance = (string) $request->input('instance', $request->query('instance', ''));
        if ($instance !== '') {
            abort_unless(app(WaRoutingOverview::class)->isKnownInstance($instance), 422, 'Instance tidak dikenal.');

            return app(EvolutionApiService::class)->forInstance($instance);
        }

        $channel = $request->input('channel', $request->query('channel', 'ops'));

        return app(EvolutionApiService::class)->forChannel((string) $channel);
    }

    /**
     * Save which instance Ticket & Progress sends through (superadmin; the route group enforces it).
     */
    public function waSaveTicketRouting(Request $request)
    {
        $overview = app(WaRoutingOverview::class);
        $validated = $request->validate([
            'instance' => ['required', 'string', 'max:100', Rule::in($overview->ticketSelectableInstances())],
        ]);

        $before = $overview->resolve('ticket');
        $after = (string) $validated['instance'];
        $warnings = $overview->ticketRoutingWarnings($after);

        Setting::set('wa_instance_ticket', $after, 'text', 'wa', 'Instance WA untuk Ticket & Progress');
        Cache::forget('setting.wa_instance_ticket');

        activity('wa-gateway.routing')
            ->causedBy($request->user())
            ->withProperties(['key' => 'wa_instance_ticket', 'before' => $before, 'after' => $after, 'warnings' => count($warnings)])
            ->log('wa_ticket_routing_changed');

        return response()->json([
            'success' => true,
            'current' => $overview->resolve('ticket'),
            'warnings' => $warnings,
            'instances' => $overview->cards(true),
        ]);
    }

    /**
     * Read-only preview for the confirmation dialog: warnings about the chosen instance.
     */
    public function waCheckTicketRouting(Request $request)
    {
        $overview = app(WaRoutingOverview::class);
        $validated = $request->validate([
            'instance' => ['required', 'string', 'max:100', Rule::in($overview->ticketSelectableInstances())],
        ]);

        return response()->json(['warnings' => $overview->ticketRoutingWarnings((string) $validated['instance'])]);
    }

    /**
     * Render the WA pairing page with multi-instance support (Nomor Ops vs Nomor Billing).
     */
    public function waPair(Request $request)
    {
        $overview = app(WaRoutingOverview::class);
        $cards = $overview->cards();
        $ops = $cards[0];

        return Inertia::render('Settings/WaPair', [
            // Real routing per number: instance names, live status, masked number, "Dipakai untuk".
            'instances' => $cards,
            'functions' => $overview->functions(),
            'ticket_routing' => $overview->ticketRouting() + [
                'can_edit' => $request->user()?->role === 'superadmin',
            ],
            // Backwards compatibility for single instance props
            'instance' => [
                'name' => $ops['name'],
                'state' => $ops['state'],
                'connected' => $ops['connected'],
                'configured' => $ops['configured'],
            ],
            'config' => [
                'base_url' => config('services.evolution_api.base_url'),
                'api_key_masked' => $this->maskApiKey(config('services.evolution_api.api_key') ?? ''),
            ],
        ]);
    }

    /**
     * Status JSON.
     */
    public function waStatus(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);
        if ($request->boolean('fresh')) {
            $evoService->forgetStatusCache();
        }
        $state = $evoService->getConnectionState();
        $masked = $state === 'open'
            ? app(WaRoutingOverview::class)->liveStatus($evoService->getInstanceName())['number_masked']
            : null;

        return response()->json([
            'instance_name' => $evoService->getInstanceName(),
            'state' => $state,
            'connected' => $state === 'open',
            'configured' => $evoService->isConfigured(),
            'number_masked' => $masked,
        ]);
    }

    /**
     * Create (or recreate) the WA instance — kicks off pairing flow.
     */
    public function waCreateInstance(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);

        if ($evoService->isInstanceConnected()) {
            return response()->json([
                'success' => true,
                'instance' => $evoService->getInstanceName(),
                'state' => 'open',
                'message' => 'Instance sudah ada dan terhubung',
            ]);
        }

        $result = $evoService->createInstance();

        return response()->json($result);
    }

    /**
     * Get current QR code as base64 PNG (if waiting for scan).
     */
    public function waQrCode(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);
        $qr = $evoService->getQrCode();

        return response()->json([
            'instance_name' => $evoService->getInstanceName(),
            'qr_code' => $qr,
            'connected' => $evoService->isInstanceConnected(),
        ]);
    }

    /**
     * Logout WA — disconnects the instance, freeing it for re-pairing.
     */
    public function waDisconnect(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);
        $result = $evoService->disconnectInstance();

        return response()->json($result);
    }

    /**
     * Pairing ulang: logout device + ambil QR baru (satu request, hindari race).
     */
    public function waRePair(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);
        $result = $evoService->rePairInstance();

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    /**
     * Test message to a phone number.
     */
    public function waTestMessage(Request $request)
    {
        $channel = (string) $request->input('channel', $request->query('channel', 'ops'));
        $evoService = $this->resolveEvoInstance($request);

        if (in_array(strtolower($channel), ['billing', 'broadcast'], true)
            && ! app(BillingWaTemplateService::class)->isLiveSendingEnabled()) {
            return response()->json([
                'success' => false,
                'dry_run' => true,
                'message' => 'Dry-run: test WA billing tidak dikirim sampai Tiar approve live.',
            ]);
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20', 'regex:/^\+?\d{8,15}$/'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $result = $evoService->send($validated['phone'], $validated['message']);

        return response()->json($result);
    }

    /**
     * Fetch WhatsApp groups from the connected instance (GET),
     * or save selected notification groups (POST).
     *
     * GET response: { groups: [...], ticket_group_id, report_group_id, configured }
     * POST body: { wa_ticket_group_jid: string, wa_report_group_jid: string }
     */
    public function waGroups(Request $request)
    {
        $evoService = $this->resolveEvoInstance($request);

        if ($request->isMethod('post')) {
            // Single source of truth: groups are written only by WaGroupSettingsWriter (Pengaturan > Grup
            // WhatsApp). A stale browser tab gets this message; nothing is written.
            return response()->json([
                'success' => false,
                'error' => 'Pilihan grup dipindah ke Pengaturan › Grup WhatsApp.',
            ], 410);
        }

        // GET: list groups + return current selections
        if ($request->boolean('fresh')) {
            $evoService->forgetStatusCache();
        }

        // Whitelist only what the UI needs; raw Evolution rows carry member phone
        // numbers (participants) and last message content that must not reach the browser.
        $groups = [];
        foreach ($evoService->fetchGroups() as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_string($row['id'])) {
                continue;
            }
            $groups[] = [
                'id' => $row['id'],
                'subject' => is_string($row['subject'] ?? null) ? $row['subject'] : null,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                'members' => is_numeric($row['members'] ?? null) ? (int) $row['members'] : 0,
            ];
        }
        $ticketGroup = Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id');
        $reportGroup = Setting::where('key', 'wa_report_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id');

        return response()->json([
            'groups' => $groups,
            'ticket_group_id' => $ticketGroup,
            'report_group_id' => $reportGroup,
            'state' => $evoService->getConnectionState(),
            'connected' => $evoService->isInstanceConnected(),
            'configured' => $evoService->isConfigured(),
        ]);
    }

    /**
     * Save WA gateway config (base_url, api_key, instance_name) to settings table.
     * POST body: { evo_base_url, evo_api_key, evo_instance_name }
     */
    public function waSaveConfig(Request $request)
    {
        $validated = $request->validate([
            'evo_base_url' => 'required|url',
            'evo_api_key' => 'nullable|string|min:10',
            'evo_instance_name' => 'required|string|max:100',
        ]);

        // Ignore masked placeholders from UI (key is env/server-only)
        $key = (string) ($validated['evo_api_key'] ?? '');
        if ($key === '' || str_contains($key, '•') || str_contains($key, '*')) {
            unset($validated['evo_api_key']);
        }

        foreach ($validated as $k => $value) {
            Setting::set($k, $value, 'text', 'wa');
        }

        return response()->json([
            'success' => true,
            'message' => 'WA config saved. API key hanya diubah jika dikirim nilai baru (bukan mask). Restart container untuk apply env.',
        ]);
    }

    /**
     * Mask the API key for display: "skynet_*****ecure"
     */
    private function maskApiKey(string $key): string
    {
        if (strlen($key) <= 6) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 6).str_repeat('*', max(0, strlen($key) - 10)).substr($key, -4);
    }
}
