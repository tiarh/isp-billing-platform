<?php

namespace App\Http\Controllers;

use App\Exceptions\LockBusyException;
use App\Models\Area;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbDraftCustomerSyncService;
use App\Services\PsbKpiService;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteRegistry;
use App\Services\PsbStarliteImportService;
use App\Services\PsbStarliteReportService;
use App\Services\PsbWaHealthService;
use App\Services\PsbWaMessages;
use App\Services\Report\ReportGroupRegistry;
use App\Services\WaChatIntelligenceService;
use App\Support\AreaScope;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PsbDraftController extends Controller
{
    /**
     * Legacy page — redirect into Aktivasi tab (no separate sidebar page).
     */
    public function index(Request $request)
    {
        return redirect()->route('oltc300.index', ['tab' => 'psb']);
    }

    /**
     * JSON panel for Aktivasi → tab PSB WA (lazy load, no SSR hang).
     */
    public function panel(Request $request)
    {
        return response()->json($this->panelPayload($request));
    }

    public function health(PsbWaHealthService $health)
    {
        return response($this->healthHtml($health->full(), true));
    }

    public function healthJson(PsbWaHealthService $health)
    {
        return response()->json($health->full());
    }

    public function publicHealth(Request $request, PsbWaHealthService $health)
    {
        if (! $health->publicTokenValid($request->query('token'))) {
            abort(404);
        }

        $summary = $health->publicSummary();
        if ($request->wantsJson() || $request->boolean('json')) {
            return response()->json($summary);
        }

        return response($this->healthHtml($summary, false));
    }

    /**
     * @return array<string, mixed>
     */
    protected function panelPayload(Request $request): array
    {
        $scoped = (bool) $request->user()?->hasAreaScope();
        $status = $request->string('status')->toString();
        $siteKey = $request->string('site')->toString() ?: 'ra';
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(50, max(10, (int) $request->query('per_page', 50)));

        $q = PsbDraft::query()
            ->select([
                'id', 'status', 'full_name', 'pppoe_user', 'secret_at', 'sn', 'pon_port', 'vlan',
                'daerah', 'odp', 'rt', 'rw', 'phone', 'tikor', 'customer_id', 'last_error',
                'ktp_photo_path', 'router_photo_path', 'meta', 'created_at', 'olt_at', 'area_id',
            ])
            ->orderByDesc('id');
        AreaScope::applyToPsbDrafts($q, $request->user());
        if ($status !== '') {
            $q->where('status', $status);
        }

        // Filter by site_profile_key — '' means all sites (no filter)
        if ($siteKey !== '' && $siteKey !== '__all__') {
            $q->where('site_profile_key', $siteKey);
        }

        $draftPage = $q->simplePaginate($perPage, ['*'], 'page', $page);
        $drafts = $draftPage->getCollection();

        // P2.3: RX dari inventory OLT (DB only — tidak spam MT/OLT)
        $sns = $drafts->pluck('sn')->filter()->map(fn ($s) => strtoupper((string) $s))->unique()->values()->all();
        $rxBySn = [];
        if ($sns !== [] && Schema::hasTable('olt_onus')) {
            try {
                $onus = OltOnu::query()
                    ->whereNotNull('rx_power_dbm')
                    // OLT inventory normalizes serial_number to uppercase on write.
                    // Keep this lookup sargable so the existing serial_number index
                    // remains usable when the ONU inventory is large.
                    ->whereIn('serial_number', array_slice($sns, 0, 80))
                    ->orderByDesc('rx_collected_at')
                    ->limit(120)
                    ->get(['serial_number', 'rx_power_dbm']);
                foreach ($onus as $onu) {
                    $k = strtoupper((string) $onu->serial_number);
                    if (! isset($rxBySn[$k]) && $onu->rx_power_dbm !== null) {
                        $rxBySn[$k] = (float) $onu->rx_power_dbm;
                    }
                }
            } catch (\Throwable) {
            }
        }

        $drafts = $drafts->map(function (PsbDraft $d) use ($rxBySn) {
            $snKey = $d->sn ? strtoupper((string) $d->sn) : '';
            $rx = $snKey !== '' ? ($rxBySn[$snKey] ?? null) : null;

            return [
                'id' => $d->id,
                'status' => $d->status,
                'full_name' => $d->full_name,
                'pppoe_user' => $d->pppoe_user,
                'pppoe_password_available' => filled($d->secret_at) && filled($d->pppoe_user),
                'sn' => $d->sn,
                'pon_port' => $d->pon_port,
                'vlan' => $d->vlan,
                'daerah' => $d->daerah,
                'odp' => $d->odp,
                'rt' => $d->rt,
                'rw' => $d->rw,
                'phone' => $d->phone,
                'tikor' => $d->tikor,
                'customer_id' => $d->customer_id,
                'last_error' => SensitiveDataRedactor::redact((string) $d->last_error),
                'ktp_photo_url' => data_get($d->meta, 'missing_media_review.ktp.status')
                    ? null
                    : $this->storageUrl($d->ktp_photo_path, 'ktp', $d->id),
                'router_photo_url' => $this->storageUrl($d->router_photo_path, 'router', $d->id),
                'media_review' => is_array($d->meta) ? ($d->meta['missing_media_review'] ?? null) : null,
                'document_gate' => is_array($d->meta) ? ($d->meta['document_gate'] ?? null) : null,
                'customer_sync' => is_array($d->meta) ? ($d->meta['psb_customer_sync'] ?? null) : null,
                'correction' => is_array($d->meta) ? ($d->meta['psb_correction'] ?? null) : null,
                'area_id' => $d->area_id,
                'area_resolution' => is_array($d->meta) ? ($d->meta['area_resolution'] ?? null) : null,
                // Blocked before any device write: no/ambiguous area ("Perlu area").
                'needs_area' => $d->status === PsbDraft::STATUS_FAILED
                    && str_starts_with((string) $d->last_error, 'Perlu area'),
                // Nullsafe: secret_at/olt_at sering null — optional()->timezone()->format() meledak (500)
                'created_at' => $d->created_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'secret_at' => $d->secret_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                'olt_at' => $d->olt_at?->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                // Badge UI (bukan spam grup): RX inventory; live MT = #psb cek
                'rx_dbm' => $rx,
                'has_secret' => (bool) $d->secret_at && (bool) $d->pppoe_user,
                'has_olt' => (bool) $d->olt_at,
            ];
        });

        $groupIds = Setting::get('psb_wa_group_ids', []);
        if (! is_array($groupIds)) {
            $groupIds = [];
        }
        $groupIds = array_values(array_filter(array_map(static function ($j) {
            $j = trim((string) $j);
            if ($j === '') {
                return null;
            }
            if (! str_contains($j, '@')) {
                $j .= '@g.us';
            }

            return $j;
        }, $groupIds)));

        $evolution = app(EvolutionApiService::class);
        $evoConfigured = $evolution->isConfigured();
        // Do not block the PSB list on a live Evolution probe. The frontend
        // refreshes health separately after the operational data is visible.
        $health = app(PsbWaHealthService::class)->cachedPublicSummary();
        $evoConnected = ($health['wa_state'] ?? null) === 'open';

        $registry = app(PsbSiteRegistry::class);

        // Today count scoped to site filter
        $todayQ = PsbDraft::query()->whereBetween('created_at', [now('Asia/Jakarta')->startOfDay()->utc(), now('Asia/Jakarta')->endOfDay()->utc()]);
        AreaScope::applyToPsbDrafts($todayQ, $request->user());
        if ($siteKey !== '' && $siteKey !== '__all__') {
            $todayQ->where('site_profile_key', $siteKey);
        }

        return [
            'drafts' => $drafts->values()->all(),
            'pagination' => [
                'current_page' => $draftPage->currentPage(),
                'per_page' => $perPage,
                'has_more' => $draftPage->hasMorePages(),
                'next_page' => $draftPage->hasMorePages() ? $draftPage->currentPage() + 1 : null,
            ],
            'filters' => ['status' => $status, 'site' => $siteKey],
            // Bot settings / infra lists are global-admin config: scoped admins
            // get the same shape, empty.
            'settings' => $scoped ? [
                'psb_wa_group_ids' => [],
                'psb_router_id' => 0,
                'psb_package_id' => 0,
                'psb_olt_id' => 0,
                'psb_acs_url' => '',
                'site_settings' => [],
                'psb_recap' => PsbOrchestratorService::recapSchedule(),
            ] : [
                'psb_recap' => PsbOrchestratorService::recapSchedule(),
                'psb_wa_group_ids' => $groupIds,
                'psb_router_id' => (int) Setting::get('psb_router_id', 0),
                'psb_package_id' => (int) Setting::get('psb_package_id', 0),
                'psb_olt_id' => (int) Setting::get('psb_olt_id', 0),
                'psb_acs_url' => (string) Setting::get('psb_acs_url', config('site.acs_url')),
                'site_settings' => app(PsbSiteRegistry::class)->settingsForUi(),
            ],
            'routers' => $scoped ? [] : Router::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'ip_address']),
            'packages' => Package::query()->orderBy('name')->get(['id', 'name', 'mikrotik_profile']),
            'olts' => $scoped ? [] : Olt::query()->orderBy('name')->get(['id', 'name', 'vendor']),
            'areas' => AreaScope::applyToAreas(Area::query(), request()->user())->orderBy('name')->get(['id', 'name']),
            'sites' => $registry->optionsForUi(),
            'today_count' => $todayQ->count(),
            'evo_configured' => $evoConfigured,
            'evo_connected' => $evoConnected,
            'health' => $health,
            'voice_ops_signals' => $scoped ? [] : app(WaChatIntelligenceService::class)->signalsFor('activation', 5),
            'can_manage' => ! $scoped,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function healthHtml(array $data, bool $detail): string
    {
        $e = static fn ($v) => e(is_scalar($v) || $v === null ? (string) $v : json_encode($v));
        $label = $data['label'] ?? (($data['status'] ?? '') === 'normal' ? 'Normal' : 'Perlu Dicek');
        $status = (string) ($data['status'] ?? 'unknown');
        $tone = $status === 'normal' ? '#047857' : '#b45309';
        $waState = $data['wa_state'] ?? ($data['evolution']['state'] ?? 'unknown');
        $webhook = $data['webhook_status'] ?? ($data['webhook']['status'] ?? 'unknown');
        $lastWebhook = $data['last_webhook_at'] ?? ($data['webhook']['last_accepted_at'] ?? $data['webhook']['last_received_at'] ?? '-');
        $lastReply = $data['last_reply_at'] ?? ($data['reply']['last_reply_at'] ?? '-');
        $queueSize = $data['queue_psb_size'] ?? ($data['queue']['psb_size'] ?? '-');
        $message = $data['message'] ?? ($data['recommendation'] ?? '-');
        $checkedAt = $data['checked_at'] ?? now('Asia/Jakarta')->toIso8601String();
        $labelHtml = $e($label);
        $messageHtml = $e($message);
        $waStateHtml = $e($waState);
        $webhookHtml = $e($webhook);
        $lastWebhookHtml = $e($lastWebhook);
        $lastReplyHtml = $e($lastReply);
        $queueSizeHtml = $e($queueSize);
        $checkedAtHtml = $e($checkedAt);

        $details = '';
        if ($detail) {
            $groups = $data['groups']['allowed'] ?? [];
            $groupList = is_array($groups) && $groups !== []
                ? '<ul>'.implode('', array_map(fn ($g) => '<li><code>'.$e($g).'</code></li>', $groups)).'</ul>'
                : '<p>Belum ada allowlist grup PSB WA.</p>';
            $failed = $data['queue']['failed_latest'] ?? [];
            $failedRows = is_array($failed) && $failed !== []
                ? '<ul>'.implode('', array_map(fn ($row) => '<li><code>#'.$e($row['id'] ?? '').'</code> '.$e($row['queue'] ?? '').' '.$e($row['failed_at'] ?? '').' - '.$e($row['error'] ?? '').'</li>', $failed)).'</ul>'
                : '<p>Tidak ada failed job terbaru.</p>';
            $recon = $data['chat_reconciliation']['rows'] ?? [];
            $reconRows = is_array($recon) && $recon !== []
                ? '<ul>'.implode('', array_map(fn ($row) => '<li><code>'.$e($row['intent'] ?? '').'</code> '.$e($row['occurred_at'] ?? '').' - '.$e($row['classification'] ?? '').' - '.$e($row['summary'] ?? '').'</li>', $recon)).'</ul>'
                : '<p>Belum ada sinyal chat aktivasi terbaru.</p>';

            $details = <<<HTML
<section><h2>Allowlist Grup</h2>{$groupList}</section>
<section><h2>Failed Jobs</h2>{$failedRows}</section>
<section><h2>Chat Reconciliation</h2>{$reconRows}</section>
HTML;
        }

        return <<<HTML
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>PSB WA Health</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;margin:0;background:#f8fafc;color:#0f172a}
    main{max-width:760px;margin:0 auto;padding:24px}
    .card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:12px 0}
    .pill{display:inline-block;border-radius:999px;padding:5px 10px;background:{$tone};color:white;font-weight:700}
    dl{display:grid;grid-template-columns:150px 1fr;gap:8px;font-size:14px}
    dt{color:#64748b} dd{margin:0;font-weight:600;word-break:break-word}
    code{font-size:12px;background:#f1f5f9;padding:2px 4px;border-radius:4px}
    ul{padding-left:18px}
  </style>
</head>
<body>
<main>
  <div class="card">
    <p class="pill">{$labelHtml}</p>
    <h1>PSB WA Health</h1>
    <p>{$messageHtml}</p>
    <dl>
      <dt>WA ops</dt><dd>{$waStateHtml}</dd>
      <dt>Webhook</dt><dd>{$webhookHtml}</dd>
      <dt>Last webhook</dt><dd>{$lastWebhookHtml}</dd>
      <dt>Last bot reply</dt><dd>{$lastReplyHtml}</dd>
      <dt>Queue PSB</dt><dd>{$queueSizeHtml}</dd>
      <dt>Checked</dt><dd>{$checkedAtHtml}</dd>
    </dl>
  </div>
  {$details}
</main>
</body>
</html>
HTML;
    }

    private function storageUrl(?string $path, string $kind, int $draftId): ?string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        try {
            return URL::temporarySignedRoute(
                'psb-drafts.media',
                now()->addMinutes(10),
                ['draft' => $draftId, 'kind' => $kind],
            );
        } catch (\Throwable) {
            return null;
        }
    }

    public function saveSettings(Request $request, PsbOrchestratorService $psb)
    {
        $data = $request->validate([
            'psb_wa_group_ids' => ['nullable', 'array'],
            'psb_wa_group_ids.*' => ['string', 'max:80'],
            'psb_router_id' => ['nullable', 'integer'],
            'psb_package_id' => ['nullable', 'integer'],
            'psb_olt_id' => ['nullable', 'integer'],
            'psb_acs_url' => ['nullable', 'string', 'max:255'],
            'psb_site_settings' => ['nullable', 'array'],
            'psb_site_settings.*' => ['array'],
            'psb_site_settings.*.router_id' => ['nullable', 'integer', 'min:0'],
            'psb_site_settings.*.package_id' => ['nullable', 'integer', 'min:0'],
            'psb_site_settings.*.olt_id' => ['nullable', 'integer', 'min:0'],
            'psb_site_settings.*.acs_url' => ['nullable', 'string', 'max:255'],
            'psb_recap_start_hour' => ['sometimes', 'integer', 'between:0,23'],
            'psb_recap_end_hour' => ['sometimes', 'integer', 'between:0,23'],
            'psb_recap_interval_hours' => ['sometimes', 'integer', 'between:1,12'],
        ]);

        // The Skynet Report group must never become a PSB/migration group (empty report list = no-op).
        $reportRegistry = app(ReportGroupRegistry::class);
        if ($reportRegistry->groupJids() !== []) {
            foreach ($data['psb_wa_group_ids'] ?? [] as $rawJid) {
                if ($reportRegistry->isReportGroup((string) $rawJid)) {
                    throw ValidationException::withMessages([
                        'psb_wa_group_ids' => 'Grup ini terdaftar sebagai grup Report; perintah PSB/migrasi tidak boleh aktif di sana.',
                    ]);
                }
            }
        }

        $recapCurrent = PsbOrchestratorService::recapSchedule();
        $recapStart = (int) ($data['psb_recap_start_hour'] ?? $recapCurrent['start']);
        $recapEnd = (int) ($data['psb_recap_end_hour'] ?? $recapCurrent['end']);
        if ($recapEnd < $recapStart) {
            throw ValidationException::withMessages([
                'psb_recap_end_hour' => 'Jam selesai rekap harus sama atau setelah jam mulai.',
            ]);
        }
        $recapLabels = [
            'psb_recap_start_hour' => 'PSB recap start hour (WIB)',
            'psb_recap_end_hour' => 'PSB recap end hour (WIB)',
            'psb_recap_interval_hours' => 'PSB recap interval (hours)',
        ];
        foreach ($recapLabels as $recapKey => $recapLabel) {
            if (array_key_exists($recapKey, $data)) {
                Setting::set($recapKey, (string) (int) $data[$recapKey], 'integer', 'psb', $recapLabel);
            }
        }

        if (array_key_exists('psb_site_settings', $data)) {
            $allowed = array_fill_keys(array_map(
                static fn (array $site): string => (string) ($site['key'] ?? ''),
                app(PsbSiteRegistry::class)->all(),
            ), true);
            $siteSettings = [];
            foreach ($data['psb_site_settings'] ?? [] as $key => $config) {
                if (is_string($key) === false || isset($allowed[$key]) === false || is_array($config) === false) {
                    throw ValidationException::withMessages(['psb_site_settings' => 'Site PSB tidak dikenali.']);
                }
                $siteSettings[$key] = [
                    'router_id' => max(0, (int) ($config['router_id'] ?? 0)),
                    'package_id' => max(0, (int) ($config['package_id'] ?? 0)),
                    'olt_id' => max(0, (int) ($config['olt_id'] ?? 0)),
                    'acs_url' => trim((string) ($config['acs_url'] ?? '')) ?: (string) config('site.acs_url'),
                ];
            }
            Setting::set('psb_site_settings', $siteSettings, 'json', 'psb', 'PSB per-site router, package, OLT, ACS');
            Cache::forget('setting.psb_site_settings');
        } else {
            // Legacy clients still have the original single-site contract.
            Setting::set('psb_router_id', (string) ($data['psb_router_id'] ?? 0), 'integer', 'psb', 'PSB RA-OLT router');
            Setting::set('psb_package_id', (string) ($data['psb_package_id'] ?? 0), 'integer', 'psb', 'PSB package 200M STARTLITE');
            Setting::set('psb_olt_id', (string) ($data['psb_olt_id'] ?? 0), 'integer', 'psb', 'PSB FiberHome OLT');
            Setting::set('psb_acs_url', $data['psb_acs_url'] ?? config('site.acs_url'), 'text', 'psb', 'ACS URL for ONT');
        }

        // Normalisasi JID agar webhook remoteJid langsung match setelah save (tanpa redeploy)
        $groups = [];
        foreach ($data['psb_wa_group_ids'] ?? [] as $raw) {
            $n = $psb->normalizeGroupJid((string) $raw);
            if ($n !== '' && ! in_array($n, $groups, true)) {
                $groups[] = $n;
            }
        }

        Setting::set('psb_wa_group_ids', $groups, 'json', 'psb', 'PSB WA group JIDs');

        // Double-forget cache — app + queue worker pakai Setting::get yang sama
        Cache::forget('setting.psb_wa_group_ids');
        if (! array_key_exists('psb_site_settings', $data)) {
            Cache::forget('setting.psb_router_id');
            Cache::forget('setting.psb_package_id');
            Cache::forget('setting.psb_olt_id');
            Cache::forget('setting.psb_acs_url');
        }

        // Verify read-back (langsung dari DB via get)
        $live = $psb->allowedGroupJids();
        Log::info('[PSB] settings saved — groups live', [
            'saved' => $groups,
            'live_allowed' => $live,
        ]);

        $msg = 'Pengaturan PSB WA disimpan. Grup aktif: '.(count($live) ? implode(', ', $live) : '(belum ada — bot diam)');
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'psb_wa_group_ids' => $live,
            ]);
        }

        return back()->with('success', $msg);
    }

    public function apply(int $id, PsbOrchestratorService $psb)
    {
        $draft = $this->scopedDraft($id);
        try {
            $customer = $psb->applyDraft($draft);
            $msg = "Draft #{$id} di-apply → customer #{$customer->id} active.";
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['success' => true, 'message' => $msg, 'customer_id' => $customer->id]);
            }

            return back()->with('success', $msg);
        } catch (\Throwable $e) {
            report($e);

            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['success' => false, 'message' => 'PSB operation failed. Check the draft status and server logs.'], 422);
            }

            return back()->with('error', 'PSB operation failed. Check the draft status and server logs.');
        }
    }

    public function retry(int $id, PsbOrchestratorService $psb)
    {
        $draft = $this->scopedDraft($id);
        if ($draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'Draft menunggu approval koreksi. Gunakan tombol Apply Koreksi / #psb lanjut #'.$draft->id.' setelah preflight dicek.',
            ], 422);
        }
        if (! $draft->isReprocessable()) {
            $msg = app(PsbWaMessages::class)->draftNotReprocessable((int) $draft->id, (string) $draft->status);
            if (request()->wantsJson() || request()->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }

            return back()->with('error', $msg);
        }
        // Do not touch a draft another process is working on.
        $probe = Cache::lock('psb-draft-'.$draft->id, 5);
        if (! $probe->get()) {
            return $this->draftBusyRetryResponse($id);
        }
        $probe->release();
        $draft->status = PsbDraft::STATUS_RECEIVED;
        $draft->last_error = null;
        $draft->save();
        $psb->processDraft($draft->fresh());

        $msg = "Draft #{$id} di-retry.";
        $after = $draft->fresh();
        $marker = $after && is_array($after->meta) ? ($after->meta['olt_busy_resume'] ?? null) : null;
        if (is_array($marker) && $after->status === PsbDraft::STATUS_SECRET_OK) {
            $msg = "Draft #{$id} di-retry. OLT sedang dipakai; sistem mencoba lagi otomatis.";
        }
        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['success' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    private function draftBusyRetryResponse(int $id)
    {
        $msg = app(PsbWaMessages::class)->draftBusyAdmin($id);
        if (request()->wantsJson() || request()->ajax()) {
            return response()->json(['success' => false, 'message' => $msg], 409);
        }

        return back()->with('error', $msg);
    }

    /**
     * Admin picks the area for a draft blocked with "Perlu area" (stored as
     * meta.area_manual, highest precedence) and the draft is retried.
     */
    public function setArea(Request $request, int $id, PsbOrchestratorService $psb)
    {
        $data = $request->validate(['area_id' => ['required', 'integer', 'exists:areas,id']]);
        AreaScope::authorizeAreaId((int) $data['area_id'], $request->user());

        // Scoped admins may only relabel drafts already inside their areas;
        // unassigned ("Perlu area") drafts are for global admins to triage.
        $draft = $this->scopedDraft($id);
        // Only drafts blocked for area (same rule as needs_area) may be relabelled.
        if ($draft->status !== PsbDraft::STATUS_FAILED || ! str_starts_with((string) $draft->last_error, 'Perlu area')) {
            return response()->json(['success' => false, 'message' => 'Draft ini tidak sedang menunggu area.'], 422);
        }
        $from = $draft->area_id ? (int) $draft->area_id : null;
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['area_manual'] = (int) $data['area_id'];
        $draft->meta = $meta;
        $draft->area_id = (int) $data['area_id'];
        $draft->save();
        activity()
            ->causedBy($request->user())
            ->performedOn($draft)
            ->withProperties(['area_id' => (int) $data['area_id'], 'from' => $from])
            ->log('psb.set_area');

        return $this->retry($id, $psb);
    }

    public function applyCorrection(int $id, PsbOrchestratorService $psb)
    {
        $draft = $this->scopedDraft($id);
        try {
            $psb->applyPsbCorrection($draft);
            $msg = "Koreksi draft #{$id} diproses.";

            return response()->json(['success' => true, 'message' => $msg]);
        } catch (LockBusyException $e) {
            return response()->json(['success' => false, 'message' => $e->userMessage()], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'PSB operation failed. Check the draft status and server logs.'], 422);
        }
    }

    public function cancelCorrection(int $id, PsbOrchestratorService $psb)
    {
        $draft = $this->scopedDraft($id);
        try {
            $draft = $psb->cancelPsbCorrection($draft, request()->user()?->name);
            $msg = "Koreksi draft #{$id} dibatalkan. Status kembali ke {$draft->status}.";

            return response()->json(['success' => true, 'message' => $msg]);
        } catch (LockBusyException $e) {
            return response()->json(['success' => false, 'message' => $e->userMessage()], 409);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'PSB operation failed. Check the draft status and server logs.'], 422);
        }
    }

    public function syncCustomerMedia(int $id, PsbDraftCustomerSyncService $sync)
    {
        $draft = $this->scopedDraft($id);
        $result = $sync->sync($draft);

        return response()->json([
            'success' => $result['status'] !== 'failed',
            'message' => 'Sync customer: '.$result['status'],
            'sync' => $result,
        ], $result['status'] === 'failed' ? 422 : 200);
    }

    /**
     * STARLITE-style KPI dashboard (read-only). Multi-site/area filters.
     * Does not touch MT/OLT/WA writes.
     */
    public function dashboard(Request $request, PsbKpiService $kpi)
    {
        $filters = [
            'site' => $request->query('site', 'ra'),
            'area_id' => $request->query('area_id'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ];

        return Inertia::render('PsbDrafts/Dashboard', [
            'starlite' => $kpi->dashboard($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * Export CSV (Excel STARLITE columns + WA drafts). Read-only stream.
     */
    public function exportCsv(Request $request, PsbStarliteReportService $report): StreamedResponse
    {
        $filename = 'psb-starlite-'.now('Asia/Jakarta')->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            $report->streamUnifiedCsv($out);
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Re-import STARLITE Google Sheet / Excel history (admin). No MT/OLT writes.
     */
    public function importStarlite(Request $request, PsbStarliteImportService $import)
    {
        $data = $request->validate([
            'sheet_id' => ['nullable', 'string', 'max:80'],
        ]);
        $sheetId = $data['sheet_id'] ?? '1gybwIO3W-84MKzcn-IP9rbfPGeP5n2ms';
        $result = $import->importFromGoogleSheet($sheetId, 'sheet');

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => empty($result['errors']),
                'result' => $result,
            ], empty($result['errors']) ? 200 : 422);
        }

        if (! empty($result['errors'])) {
            return back()->with('error', 'Import gagal: '.implode('; ', $result['errors']));
        }

        return back()->with(
            'success',
            "Import STARLITE OK: +{$result['imported']} baru, {$result['updated']} update, skip {$result['skipped']}."
        );
    }

    /**
     * Save KPI daily targets per site (admin settings).
     */
    public function updateTargets(Request $request)
    {
        $data = $request->validate([
            'targets' => ['required', 'array'],
            'targets.*' => ['required', 'integer', 'min:0', 'max:1000'],
        ]);

        $current = Setting::get('psb_site_targets', []);
        if (is_string($current)) {
            $current = json_decode($current, true) ?: [];
        }
        $updated = array_merge((array) $current, $data['targets']);
        Setting::set('psb_site_targets', $updated);

        return back()->with('success', 'Target KPI per site berhasil diperbarui.');
    }

    /**
     * Write actions on a single draft (apply, retry, corrections, media sync)
     * must respect the same area boundary as the panel list.
     */
    private function scopedDraft(int $id): PsbDraft
    {
        $draft = PsbDraft::query()->findOrFail($id);
        AreaScope::authorizePsbDraft($draft, request()->user());

        return $draft;
    }
}
