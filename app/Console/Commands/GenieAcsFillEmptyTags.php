<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Setting;
use App\Services\GenieAcsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fill GenieACS devices that have empty _tags from:
 * 1) customer pppoe_user (RT/RW/ODP)
 * 2) genieacs_devices.pppoe_user
 * 3) olt_onus.onu_name (provision name with RT/RW/ODP)
 */
class GenieAcsFillEmptyTags extends Command
{
    protected $signature = 'genieacs:fill-empty-tags
                            {--limit=80 : Max devices to tag this run}
                            {--dry-run : Only report, do not POST tags}';

    protected $description = 'Auto-fill empty GenieACS tags from customer PPPoE or OLT onu_name (RT/RW/ODP)';

    public function handle(GenieAcsService $svc): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dry = (bool) $this->option('dry-run');
        $base = rtrim((string) Setting::get('genieacs_url', ''), '/');
        if ($base === '') {
            $this->error('GenieACS URL empty in settings');

            return self::FAILURE;
        }

        // Prefer live ACS empty tags (source of truth)
        $empty = $this->listEmptyFromAcs($base, $svc);
        $this->info('empty_on_acs='.count($empty));

        $fixed = 0;
        $fail = 0;
        $skip = 0;

        foreach ($empty as $row) {
            if ($fixed + $fail >= $limit) {
                break;
            }
            $sn = $row['serial'];
            $deviceId = $row['device_id'];
            if ($sn === '') {
                $skip++;

                continue;
            }

            $tag = $this->resolveTagForSerial($svc, $sn);
            if ($tag === '') {
                $this->line("NO_SOURCE sn={$sn} id={$deviceId}");
                $fail++;

                continue;
            }

            if ($dry) {
                $this->line("DRY sn={$sn} => {$tag}");
                $fixed++;

                continue;
            }

            $res = $svc->pushTagForSerial($sn, $tag);
            if ($res['pushed'] ?? false) {
                $fixed++;
                $this->line("OK sn={$sn} => {$tag}");
                $this->mirrorLocal($sn, $tag);
            } else {
                $fail++;
                $this->warn("FAIL sn={$sn} ".($res['message'] ?? ''));
            }
            usleep(50000);
        }

        $this->info("Done fixed={$fixed} fail={$fail} skip={$skip}");
        Log::info('[genieacs:fill-empty-tags]', compact('fixed', 'fail', 'skip'));

        return $fail > 0 && $fixed === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array{serial: string, device_id: string, tags: array}>
     */
    protected function listEmptyFromAcs(string $base, GenieAcsService $svc): array
    {
        $response = Http::timeout(60)->get($base.'/devices', [
            'projection' => '_id,_tags,_deviceId._SerialNumber',
        ]);
        if (! $response->successful()) {
            // fallback local empty
            return GenieAcsDevice::query()
                ->get(['serial_number', 'device_id', 'tags'])
                ->filter(function ($d) {
                    $t = $d->tags;
                    if (is_string($t)) {
                        $t = json_decode($t, true) ?: [];
                    }

                    return ! is_array($t) || count(array_filter($t)) === 0;
                })
                ->map(fn ($d) => [
                    'serial' => (string) $d->serial_number,
                    'device_id' => (string) $d->device_id,
                    'tags' => [],
                ])
                ->values()
                ->all();
        }

        $out = [];
        foreach ($response->json() ?? [] as $d) {
            if (! is_array($d)) {
                continue;
            }
            $tags = $d['_tags'] ?? [];
            if (is_array($tags) && count(array_filter(array_map('strval', $tags))) > 0) {
                continue;
            }
            $sn = strtoupper((string) ($d['_deviceId']['_SerialNumber'] ?? ''));
            $id = (string) ($d['_id'] ?? '');
            if ($sn === '' && preg_match('/(FHTT[A-F0-9]+)$/i', $id, $m)) {
                $sn = strtoupper($m[1]);
            }
            $out[] = ['serial' => $sn, 'device_id' => $id, 'tags' => []];
        }

        return $out;
    }

    protected function resolveTagForSerial(GenieAcsService $svc, string $serial): string
    {
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serial) ?? '');
        $local = GenieAcsDevice::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$norm])
            ->first();

        if ($local?->customer_id) {
            $c = Customer::query()->find($local->customer_id, ['id', 'name', 'pppoe_user']);
            if ($c) {
                $t = $svc->preferredCustomerTag($c);
                if ($t !== '') {
                    return $t;
                }
            }
        }

        if ($local && filled($local->pppoe_user)) {
            $t = $svc->normalizeOnuDisplayName((string) $local->pppoe_user);
            if ($t !== '') {
                return $t;
            }
        }

        // OLT inventory name (RT/RW/ODP)
        try {
            $name = DB::table('olt_onus')
                ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$norm])
                ->value('onu_name');
            $t = $svc->normalizeOnuDisplayName((string) ($name ?? ''));
            if ($t !== '') {
                return $t;
            }
        } catch (\Throwable) {
            // ignore
        }

        if ($local && filled($local->genieacs_customer_name)) {
            return $svc->normalizeOnuDisplayName((string) $local->genieacs_customer_name);
        }

        return '';
    }

    protected function mirrorLocal(string $serial, string $tag): void
    {
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $serial) ?? '');
        $dev = GenieAcsDevice::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(serial_number,"-","")," ","")) = ?', [$norm])
            ->first();
        if (! $dev) {
            return;
        }
        $dev->forceFill([
            'tags' => [$tag],
            'pppoe_user' => $dev->pppoe_user ?: $tag,
            'genieacs_customer_name' => $tag,
        ])->save();
    }
}
