<?php

namespace App\Http\Controllers;

use App\Models\MonitoringUpstreamLink;
use App\Models\Setting;
use App\Services\Grafana\GrafanaClient;
use App\Services\Grafana\RouterIdentitySuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Monitoring > Pengaturan: Grafana connection + upstream/router mapping. */
class MonitoringSettingsController extends Controller
{
    public function index(GrafanaClient $grafana): Response
    {
        $conn = $grafana->connection();

        $upstreamLinks = Schema::hasTable('monitoring_upstream_links')
            ? MonitoringUpstreamLink::query()->orderBy('sort')->orderBy('id')->get()->map(fn ($l) => [
                'upstream_key' => $l->upstream_key,
                'display_name' => $l->display_name,
                'kind' => $l->kind,
                'router_label' => $l->router_label,
                'interface_name' => $l->interface_name,
                'capacity_mbps' => $l->capacity_mbps,
                'sort' => $l->sort,
                'is_active' => (bool) $l->is_active,
            ])->all()
            : [];

        $routers = Schema::hasTable('routers') && Schema::hasColumn('routers', 'grafana_identity')
            ? DB::table('routers')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'is_active', 'grafana_identity'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => (string) $r->name,
                    'is_active' => (bool) $r->is_active,
                    'grafana_identity' => $r->grafana_identity,
                ])->all()
            : [];

        return Inertia::render('Monitoring/Settings', [
            'connection' => [
                'url' => $conn['url'],
                'datasource_uid' => $conn['datasource_uid'],
                'token_set' => $conn['token'] !== '',
                'configured' => $conn['url'] !== '' && $conn['token'] !== '',
                'breaker_open' => $grafana->breakerIsOpen(),
            ],
            'upstreamLinks' => $upstreamLinks,
            'routers' => $routers,
        ]);
    }

    public function testConnection(GrafanaClient $grafana): JsonResponse
    {
        return response()->json($grafana->testConnection());
    }

    public function labelValues(string $source, GrafanaClient $grafana): JsonResponse
    {
        if (! isset(GrafanaClient::LABEL_SOURCES[$source])) {
            abort(404);
        }

        return response()->json($grafana->labelValues($source));
    }

    public function suggest(RouterIdentitySuggester $suggester): JsonResponse
    {
        return response()->json(['rows' => $suggester->suggest()]);
    }

    public function updateConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:255', 'regex:#^https?://#i'],
            'datasource_uid' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'token' => ['nullable', 'string', 'max:512'],
        ]);

        $host = strtolower((string) parse_url($data['url'], PHP_URL_HOST));
        $allowed = config('services.grafana.allowed_hosts', []);
        if (is_string($allowed)) {
            $allowed = explode(',', $allowed);
        }
        $allowed = array_map(fn ($h) => strtolower(trim((string) $h)), (array) $allowed);
        if ($host === '' || ! in_array($host, $allowed, true)) {
            throw ValidationException::withMessages(['url' => 'Host tidak diizinkan (GRAFANA_ALLOWED_HOSTS)']);
        }

        Setting::set(GrafanaClient::SETTING_URL, rtrim($data['url'], '/'), 'text', 'monitoring', 'Grafana URL');
        Setting::set(GrafanaClient::SETTING_DATASOURCE_UID, $data['datasource_uid'], 'text', 'monitoring', 'Grafana datasource UID');

        $token = trim((string) ($data['token'] ?? ''));
        if ($token !== '') {
            Setting::setSecret(GrafanaClient::SETTING_TOKEN, $token, 'monitoring', 'Grafana service-account token');
        }

        Cache::forget('grafana.cb.open');

        return back()->with('success', 'Koneksi Grafana disimpan.');
    }

    public function updateUpstreamLinks(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'links' => ['required', 'array', 'max:50'],
            'links.*.upstream_key' => ['required', 'string', 'regex:/^[A-Z0-9_]{2,32}$/'],
            'links.*.display_name' => ['required', 'string', 'max:64'],
            'links.*.kind' => ['required', Rule::in(['transit', 'ix', 'aggregate'])],
            'links.*.interface_name' => ['required', 'string', 'regex:/^[A-Za-z0-9 ._:\/\[\]-]{1,96}$/'],
            'links.*.capacity_mbps' => ['nullable', 'integer', 'min:1', 'max:400000'],
            'links.*.sort' => ['nullable', 'integer', 'min:0', 'max:32000'],
            'links.*.is_active' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['links'] as $link) {
                $existing = MonitoringUpstreamLink::query()->where('upstream_key', $link['upstream_key'])->first();
                $values = [
                    'display_name' => $link['display_name'],
                    'kind' => $link['kind'],
                    'interface_name' => $link['interface_name'],
                    'capacity_mbps' => $link['capacity_mbps'] ?? null,
                    'is_active' => (bool) ($link['is_active'] ?? true),
                ];
                if (isset($link['sort'])) {
                    $values['sort'] = (int) $link['sort'];
                }
                if ($existing) {
                    $existing->update($values);
                } else {
                    MonitoringUpstreamLink::create($values + [
                        'upstream_key' => $link['upstream_key'],
                        'router_label' => 'Jakarta-Edge',
                        'sort' => $values['sort'] ?? 0,
                    ]);
                }
            }
        });

        return back()->with('success', 'Upstream link disimpan.');
    }

    public function updateRouterIdentities(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'routers' => ['required', 'array', 'max:500'],
            'routers.*.router_id' => ['required', 'integer', 'exists:routers,id'],
            'routers.*.grafana_identity' => ['nullable', 'string', 'regex:/^[A-Za-z0-9 ._:\/\[\]-]{1,96}$/'],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['routers'] as $row) {
                $identity = isset($row['grafana_identity']) ? trim($row['grafana_identity']) : null;
                DB::table('routers')->where('id', $row['router_id'])->update([
                    'grafana_identity' => $identity === '' ? null : $identity,
                ]);
            }
        });

        return back()->with('success', 'Identitas Grafana router disimpan.');
    }
}
