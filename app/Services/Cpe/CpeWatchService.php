<?php

namespace App\Services\Cpe;

use App\Services\Outage\OutageSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-3: 5-minute watch of symptomatic devices (command cpe:watch). Default OFF (cpe_watch_enabled).
 *
 * Per run, for up to cpe_watch_max_devices customers (open incident ticket > >= 2 boots in 24 h > latest
 * diagnosis WLAN_* / WIFI_CLIENT / CPE_REBOOT_LOOP > a recent "Cek sekarang"):
 *  - queue ONE getParameterValues task WITHOUT connection_request, so the ONU answers on its next periodic inform
 *    (<= 5 min) and no connection request is ever sent into the network;
 *  - skip a device that still has a pending task (one GET /tasks per run);
 *  - read the watched devices back (one query, small projection) and write cpe_wifi_samples + transitions.
 * Never touches a customer with a PSB draft / migration changed in the last 30 minutes. Never dispatches a job.
 */
class CpeWatchService
{
    /** A device that did not inform within this many minutes cannot answer: no task is piled up for it. */
    public const MAX_SILENT_MINUTES = 60;

    public function __construct(
        private ?CpeNbi $nbi = null,
        private ?CpeModelMap $map = null,
        private ?CpeSampleWriter $writer = null,
    ) {
        $this->nbi ??= new CpeNbi;
        $this->map ??= new CpeModelMap;
        $this->writer ??= new CpeSampleWriter(null, $this->map);
    }

    /** @return array{enabled: bool, picked: int, queued: int, skipped_pending: int, skipped_busy: int, sampled: int, error: ?string} */
    public function run(?CarbonInterface $now = null): array
    {
        $result = ['enabled' => false, 'picked' => 0, 'queued' => 0, 'skipped_pending' => 0, 'skipped_busy' => 0, 'sampled' => 0, 'error' => null];
        if (! OutageSettings::cpeWatchEnabled() || ! OutageSettings::cpeLayerEnabled()) {
            return $result;
        }
        $result['enabled'] = true;
        $now = $now ? Carbon::instance($now) : Carbon::now();
        if ($this->nbi->baseUrl() === '') {
            $result['error'] = 'genieacs_url kosong';

            return $result;
        }

        try {
            $devices = $this->pickDevices($now);
            $result['picked'] = count($devices);
            if ($devices === []) {
                return $result;
            }

            $pending = $this->nbi->pendingTaskDevices(); // the only GET /tasks of this run
            $watched = [];
            foreach ($devices as $d) {
                $watched[] = $d->device_id;
                if (isset($pending[$d->device_id])) {
                    $result['skipped_pending']++;

                    continue;
                }
                if (CpeGuards::busyReason($d->customer_id !== null ? (int) $d->customer_id : null, $d->pppoe_user, $now) !== null) {
                    $result['skipped_busy']++;

                    continue;
                }
                $entry = $this->map->forProductClass($d->product_class);
                $leaves = $this->map->fixedLeaves($entry);
                if ($leaves === [] || ! ($entry['supported'] ?? false)) {
                    continue; // unknown model: nothing safe to ask for
                }
                // no connection_request: the ONU answers at its next periodic inform
                $posted = $this->nbi->postTask($d->device_id, ['name' => 'getParameterValues', 'parameterNames' => $leaves], false);
                if ($posted['ok']) {
                    $result['queued']++;
                }
            }

            // read back what the ONUs reported since the previous run (answers of the tasks queued 5 minutes ago)
            $docs = $this->nbi->fetchDocs($watched, $this->writer->projection(false));
            foreach ($docs as $doc) {
                $applied = $this->writer->apply($doc, 'watch', false, $now);
                if ($applied !== null && $applied['sample']) {
                    $result['sampled']++;
                }
            }
        } catch (\Throwable $e) {
            $result['error'] = $e::class;
            try {
                Log::warning('[CPE] watch run failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
        }

        return $result;
    }

    /**
     * Candidates in priority order, one device per customer, capped. Only devices that informed recently.
     *
     * @return list<object> rows: device_id, customer_id, pppoe_user, product_class
     */
    public function pickDevices(CarbonInterface $now): array
    {
        $cap = OutageSettings::cpeWatchMaxDevices();
        $hours = OutageSettings::cpeWatchHours();
        $customerIds = [];

        // 1. open incident tickets
        $add = function (iterable $ids) use (&$customerIds): void {
            foreach ($ids as $id) {
                if ($id !== null) {
                    $customerIds[(int) $id] = true;
                }
            }
        };
        $add(DB::table('support_tickets')->where('type', 'incident')->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
            ->whereNotNull('customer_id')->orderByDesc('id')->limit(500)->pluck('customer_id'));

        // 2. >= 2 boots in 24 h (boot / uptime_reset events)
        if (Schema::hasTable('cpe_events')) {
            $since = $now->copy()->utc()->subHours(24)->format('Y-m-d H:i:s');
            $add(DB::table('cpe_events')->whereIn('type', ['boot', 'uptime_reset'])->where('at', '>=', $since)->whereNotNull('customer_id')
                ->groupBy('customer_id')->havingRaw('COUNT(*) >= 2')->orderByRaw('COUNT(*) DESC')->limit(500)->pluck('customer_id'));
        }

        // 3. latest diagnosis says WLAN_* / WIFI_CLIENT / CPE_REBOOT_LOOP (outage_diagnoses exists from RCA-4 on)
        if (Schema::hasTable('outage_diagnoses')) {
            try {
                $since = $now->copy()->utc()->subHours($hours)->format('Y-m-d H:i:s');
                $add(DB::table('outage_diagnoses')->whereIn('cause_code', ['wlan_down', 'wlan_flap', 'wifi_client', 'cpe_reboot_loop'])
                    ->where('created_at', '>=', $since)->whereNotNull('customer_id')->orderByDesc('id')->limit(500)->pluck('customer_id'));
            } catch (\Throwable) {
                // schema differs: ignore this source
            }
        }

        // 4. a recent "Cek sekarang" (summon)
        $summoned = DB::table('genieacs_devices')->whereNotNull('customer_id')->whereNotNull('cpe_summoned_at')
            ->where('cpe_summoned_at', '>=', $now->copy()->utc()->subHours($hours)->format('Y-m-d H:i:s'))
            ->orderByDesc('cpe_summoned_at')->limit(500)->pluck('customer_id');
        $add($summoned);

        if ($customerIds === []) {
            return [];
        }

        $silent = $now->copy()->utc()->subMinutes(self::MAX_SILENT_MINUTES)->format('Y-m-d H:i:s');
        $byCustomer = DB::table('genieacs_devices')->whereIn('customer_id', array_keys($customerIds))
            ->where('last_inform_at', '>=', $silent)
            ->orderByDesc('last_inform_at')
            ->get(['device_id', 'customer_id', 'pppoe_user', 'product_class'])
            ->unique('customer_id')->keyBy('customer_id');

        $out = [];
        foreach (array_keys($customerIds) as $cid) { // insertion order = priority order
            if (isset($byCustomer[$cid])) {
                $out[] = $byCustomer[$cid];
                if (count($out) >= $cap) {
                    break;
                }
            }
        }

        return $out;
    }
}
