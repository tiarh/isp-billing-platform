<?php

namespace App\Services\Cpe;

use Illuminate\Support\Facades\File;

/**
 * RCA-3: the one GenieACS configuration change of this PR, shipped as versioned files in
 * deploy/genieacs/rca3/ and applied by the owner through `genieacs:rca3-config`.
 *
 *  - provision `rca3-cpe` + preset `rca3-cpe` (new; weight 10, no events, runs on every inform, refreshes hourly)
 *  - virtual parameter `wifiClients` (replacement script: sums all WLAN instances, hourly)
 *  - virtual parameters RXPower / ontRxPower / TXPower / ontTemp / gettemp: the LIVE script gets `{value: 1}`
 *    replaced by an hourly refresh (never a guessed copy; a script without that pattern is "blocked" and left alone)
 *
 * plan() is read-only (GET only). apply() and rollback() are the only writers and are reached only from the
 * command with --confirm. Nothing here is ever scheduled.
 */
class GenieAcsRca3Config
{
    public const PROVISION = 'rca3-cpe';

    public const PRESET = 'rca3-cpe';

    public const WIFI_VP = 'wifiClients';

    public function __construct(private ?CpeNbi $nbi = null, private ?string $dir = null)
    {
        $this->nbi ??= new CpeNbi;
        $this->dir ??= base_path('deploy/genieacs/rca3');
    }

    /**
     * Desired state from the repo files.
     *
     * @return array{provision: string, preset: array<string,mixed>, wifi_vp: string, patch: array{virtual_parameters: list<string>, pattern: string, replacement: string}}
     */
    public function desired(): array
    {
        $read = function (string $file): string {
            $path = $this->dir.DIRECTORY_SEPARATOR.$file;
            if (! File::exists($path)) {
                throw new \RuntimeException("File konfigurasi tidak ada: {$file}");
            }

            return (string) File::get($path);
        };
        $preset = json_decode($read('preset-rca3-cpe.json'), true);
        $patch = json_decode($read('vp-refresh-patch.json'), true);
        if (! is_array($preset) || ! is_array($patch) || ! isset($patch['virtual_parameters'], $patch['pattern'], $patch['replacement'])) {
            throw new \RuntimeException('Format file konfigurasi tidak valid.');
        }

        return [
            'provision' => $read('provision-rca3-cpe.js'),
            'preset' => $preset,
            'wifi_vp' => $read('vp-wifiClients.js'),
            'patch' => $patch,
        ];
    }

    /** sha256 over every shipped file body: the owner compares this with the --check output. */
    public function hash(): string
    {
        $d = $this->desired();

        return hash('sha256', $d['provision']."\n".json_encode($d['preset'])."\n".$d['wifi_vp']."\n".json_encode($d['patch']));
    }

    /**
     * Read-only: GET the live objects and compute the changes.
     *
     * @return list<array{kind: string, id: string, action: string, note: ?string, old: ?string, new: ?string, diff: string}>
     */
    public function plan(): array
    {
        $d = $this->desired();
        $plan = [];

        // provision
        $live = $this->getScript('provisions', self::PROVISION);
        $plan[] = $this->scriptChange('provision', self::PROVISION, $live, $d['provision']);

        // wifiClients VP (replacement)
        $liveWifi = $this->getScript('virtual_parameters', self::WIFI_VP);
        $plan[] = $this->scriptChange('virtual_parameter', self::WIFI_VP, $liveWifi, $d['wifi_vp'], $liveWifi === null ? 'VP tidak ada di GenieACS: dibuat baru.' : null);

        // refresh VPs: patch the live script
        foreach ($d['patch']['virtual_parameters'] as $name) {
            $script = $this->getScript('virtual_parameters', (string) $name);
            if ($script === null) {
                $plan[] = ['kind' => 'virtual_parameter', 'id' => (string) $name, 'action' => 'blocked', 'note' => 'VP tidak ada di GenieACS, dilewati.', 'old' => null, 'new' => null, 'diff' => ''];

                continue;
            }
            $patched = @preg_replace($d['patch']['pattern'], $d['patch']['replacement'], $script, -1, $count);
            if (! is_string($patched) || $count === 0) {
                $already = str_contains($script, 'Date.now(3600000)');
                $plan[] = ['kind' => 'virtual_parameter', 'id' => (string) $name, 'action' => $already ? 'unchanged' : 'blocked',
                    'note' => $already ? 'Sudah memakai refresh per jam.' : 'Pola {value: 1} tidak ditemukan, script tidak diubah otomatis.',
                    'old' => $script, 'new' => null, 'diff' => ''];

                continue;
            }
            $plan[] = $this->scriptChange('virtual_parameter', (string) $name, $script, $patched, "{$count} pembacaan jadi refresh per jam.");
        }

        // preset (last: it switches the provision on)
        $livePreset = $this->getPreset(self::PRESET);
        $newPreset = $this->presetJson($d['preset']);
        $oldPreset = $livePreset === null ? null : $this->presetJson($livePreset);
        $plan[] = [
            'kind' => 'preset', 'id' => self::PRESET,
            'action' => $oldPreset === null ? 'create' : ($oldPreset === $newPreset ? 'unchanged' : 'update'),
            'note' => null, 'old' => $oldPreset, 'new' => $newPreset,
            'diff' => $oldPreset === $newPreset ? '' : $this->diff((string) $oldPreset, $newPreset),
        ];

        return $plan;
    }

    /**
     * Writes the backup, then PUTs every create / update (VPs and provision first, preset last).
     *
     * @return array{backup: string, applied: list<string>}
     */
    public function apply(?string $stamp = null, ?string $expectHash = null): array
    {
        $plan = $this->plan();
        if ($expectHash !== null && ! hash_equals($this->planHash($plan), $expectHash)) {
            throw new \RuntimeException('Rencana berubah sejak --check (hash beda). Jalankan --check lagi dan pakai hash yang baru.');
        }
        $stamp ??= now()->utc()->format('Ymd-His');
        $backupDir = storage_path('app/genieacs-backup/'.$stamp);
        File::ensureDirectoryExists($backupDir);

        $backup = ['stamp' => $stamp, 'hash' => $this->hash(), 'items' => []];
        foreach ($plan as $item) {
            if (in_array($item['action'], ['create', 'update'], true)) {
                $backup['items'][] = ['kind' => $item['kind'], 'id' => $item['id'], 'old' => $item['old'], 'existed' => $item['old'] !== null];
            }
        }
        File::put($backupDir.'/backup.json', json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $applied = [];
        $blocked = [];
        foreach ($plan as $item) {
            if ($item['action'] === 'blocked') {
                $blocked[] = $item['id'];
            }
            if (! in_array($item['action'], ['create', 'update'], true)) {
                continue;
            }
            $this->put($item['kind'], $item['id'], (string) $item['new']);
            $applied[] = $item['kind'].':'.$item['id'];
        }

        return ['backup' => $backupDir, 'applied' => $applied, 'blocked' => $blocked];
    }

    /**
     * Hash over what apply() would really send and replace (action + old + new of every item), so the owner can pin
     * `--apply --confirm --expect-hash=<hash printed by --check>` to the diff that was reviewed.
     *
     * @param  list<array<string,mixed>>  $plan
     */
    public function planHash(array $plan): string
    {
        $rows = array_map(fn ($i) => [$i['kind'], $i['id'], $i['action'], $i['old'], $i['new']], $plan);

        return hash('sha256', (string) json_encode($rows));
    }

    /**
     * Read-only: the live `default` preset, to compare its format (channel, precondition) with the new preset.
     *
     * @return array<string,mixed>|null
     */
    public function liveDefaultPreset(): ?array
    {
        return $this->getPreset('default');
    }

    /**
     * Restores a backup written by apply(): old scripts are PUT back, objects that did not exist before are DELETEd.
     *
     * @return list<string>
     */
    public function rollback(string $stamp): array
    {
        if (preg_match('/^[0-9]{8}-[0-9]{6}$/', $stamp) !== 1) {
            throw new \RuntimeException('Format backup harus YYYYMMDD-HHMMSS.');
        }
        $file = storage_path('app/genieacs-backup/'.$stamp.'/backup.json');
        if (! File::exists($file)) {
            throw new \RuntimeException("Backup {$stamp} tidak ditemukan.");
        }
        $backup = json_decode((string) File::get($file), true);
        $done = [];
        // preset first (switch the provision off), then the rest
        $items = array_values((array) ($backup['items'] ?? []));
        usort($items, fn ($a, $b) => ($a['kind'] === 'preset' ? 0 : 1) <=> ($b['kind'] === 'preset' ? 0 : 1));
        foreach ($items as $item) {
            if ($item['existed']) {
                $this->put((string) $item['kind'], (string) $item['id'], (string) $item['old']);
                $done[] = 'restore '.$item['kind'].':'.$item['id'];
            } else {
                $this->delete((string) $item['kind'], (string) $item['id']);
                $done[] = 'hapus '.$item['kind'].':'.$item['id'];
            }
        }

        return $done;
    }

    // ------------------------------------------------------------------ internals

    /** @return array{kind: string, id: string, action: string, note: ?string, old: ?string, new: ?string, diff: string} */
    private function scriptChange(string $kind, string $id, ?string $old, string $new, ?string $note = null): array
    {
        $action = $old === null ? 'create' : (trim($old) === trim($new) ? 'unchanged' : 'update');

        return ['kind' => $kind, 'id' => $id, 'action' => $action, 'note' => $note, 'old' => $old, 'new' => $new,
            'diff' => $action === 'unchanged' ? '' : $this->diff((string) $old, $new)];
    }

    private function getScript(string $collection, string $id): ?string
    {
        $response = $this->nbi->http(15)->get($this->nbi->baseUrl().'/'.$collection, ['query' => json_encode(['_id' => $id])]);
        $response->throw();
        foreach ((array) $response->json() as $row) {
            if (is_array($row) && ($row['_id'] ?? null) === $id) {
                return (string) ($row['script'] ?? '');
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function getPreset(string $id): ?array
    {
        $response = $this->nbi->http(15)->get($this->nbi->baseUrl().'/presets', ['query' => json_encode(['_id' => $id])]);
        $response->throw();
        foreach ((array) $response->json() as $row) {
            if (is_array($row) && ($row['_id'] ?? null) === $id) {
                unset($row['_id']);

                return $row;
            }
        }

        return null;
    }

    /** Stable JSON of the preset fields eBilling controls (ignores key order / extra server fields). */
    private function presetJson(array $preset): string
    {
        $keep = ['weight', 'channel', 'events', 'precondition', 'configurations'];
        $out = [];
        foreach ($keep as $k) {
            $out[$k] = $preset[$k] ?? ($k === 'events' ? new \stdClass : null);
        }
        if ($out['events'] === [] || $out['events'] === null) {
            $out['events'] = new \stdClass; // GenieACS wants an object, not []
        }

        return (string) json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function put(string $kind, string $id, string $body): void
    {
        $url = $this->url($kind, $id);
        $http = $this->nbi->http(20);
        $response = $kind === 'preset'
            ? $http->withBody($body, 'application/json')->put($url)
            : $http->withBody($body, 'text/plain')->put($url);
        $response->throw();
    }

    private function delete(string $kind, string $id): void
    {
        $this->nbi->http(20)->delete($this->url($kind, $id))->throw();
    }

    private function url(string $kind, string $id): string
    {
        $collection = match ($kind) {
            'provision' => 'provisions',
            'preset' => 'presets',
            'virtual_parameter' => 'virtual_parameters',
            default => throw new \InvalidArgumentException('Unknown kind'),
        };

        return $this->nbi->baseUrl().'/'.$collection.'/'.rawurlencode($id);
    }

    /** Small line diff (LCS) for the --check output. */
    private function diff(string $old, string $new): string
    {
        $a = preg_split('/\R/', rtrim($old)) ?: [];
        $b = preg_split('/\R/', rtrim($new)) ?: [];
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $out = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = '- '.$a[$i++];
            } else {
                $out[] = '+ '.$b[$j++];
            }
        }
        while ($i < $n) {
            $out[] = '- '.$a[$i++];
        }
        while ($j < $m) {
            $out[] = '+ '.$b[$j++];
        }

        return implode("\n", $out);
    }
}
