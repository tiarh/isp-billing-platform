<?php

namespace App\Services;

use App\Models\Olt;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\Process;

class OltHiosoService
{
    public function collectSnapshot(Olt $olt): array
    {
        $protocol = $olt->management_protocol ?: 'telnet';

        if (in_array($protocol, ['http', 'https'], true)) {
            return $this->collectWebSnapshot($olt);
        }

        if ($protocol === 'snmp') {
            return $this->collectSnmpSnapshot($olt);
        }

        return $this->collectCliSnapshot($olt);
    }

    protected function collectCliSnapshot(Olt $olt): array
    {
        $session = $this->connect($olt);
        $commands = [];

        try {
            $statusOutput = $this->execAny($session, [
                'show onu info',
                'show onu status',
                'show epon onu-information',
                'show gpon onu-information',
            ], $commands);

            $opticalOutput = $this->execFirstSuccessful($session, [
                'show onu optical-info',
                'show onu optical',
                'show pon optical',
                'show epon onu optical',
            ], $commands);

            $runningConfig = $this->execFirstSuccessful($session, [
                'show running-config',
                'show startup-config',
            ], $commands);

            $onuRefs = $this->extractOnuRefs($statusOutput."\n".$opticalOutput."\n".$runningConfig);
            $states = $this->parseStates($statusOutput);
            $optical = $this->parseOptical($opticalOutput);
            $names = $this->parseNames($runningConfig."\n".$statusOutput);
            $serials = $this->parseSerials($statusOutput."\n".$runningConfig);

            $onus = array_map(function (string $onuRef) use ($states, $optical, $names, $serials) {
                return [
                    'onu_ref' => $onuRef,
                    'pon_port' => $this->extractPortFromOnuRef($onuRef),
                    'state' => $states[$onuRef]['state'] ?? null,
                    'phase_state' => $states[$onuRef]['phase_state'] ?? null,
                    'serial_number' => $serials[$onuRef] ?? null,
                    'onu_name' => $names[$onuRef] ?? null,
                    'rx_power_dbm' => $optical[$onuRef]['rx_power_dbm'] ?? null,
                    'distance_m' => $optical[$onuRef]['distance_m'] ?? null,
                    'detail_raw' => null,
                ];
            }, $onuRefs);

            $ports = array_values(array_unique(array_filter(array_map(
                fn (string $ref) => $this->extractPortFromOnuRef($ref),
                $onuRefs
            ))));

            return [
                'meta' => [
                    'olt' => $olt->name,
                    'host' => $olt->management_ip,
                    'port' => $olt->management_port ?: $this->defaultPort($olt),
                    'protocol' => $olt->management_protocol ?: 'telnet',
                    'vendor' => 'hioso',
                    'collected_at' => now()->toISOString(),
                    'pon_ports_count' => count($ports),
                    'onus_count' => count($onus),
                ],
                'pon_ports' => array_map(fn (string $item) => ['name' => $item], $ports),
                'onus' => $onus,
                'raw' => [
                    'state' => $statusOutput,
                    'optical' => $opticalOutput,
                    'running_config' => $runningConfig,
                ],
                'commands' => $commands,
            ];
        } finally {
            $session->close();
        }
    }

    protected function collectWebSnapshot(Olt $olt): array
    {
        if (! $olt->management_ip) {
            throw new RuntimeException('Hioso web OLT must have host/IP.');
        }

        $baseUrl = $this->baseUrl($olt);
        $jar = new CookieJar;
        $client = Http::timeout(12)
            ->connectTimeout(8)
            ->withOptions([
                'cookies' => $jar,
                'verify' => false,
                'allow_redirects' => true,
            ])
            ->accept('*/*');

        if ($olt->username || $olt->password) {
            $client = $client->withBasicAuth($olt->username ?: '', $olt->password ?: '');
        }

        $raw = [];
        $visited = [];
        $root = $client->get($baseUrl);
        $raw['GET /'] = $root->body();
        $this->attemptWebLogin($client, $baseUrl, $raw, $olt);

        $paths = $this->hiosoWebCandidatePaths($raw['GET /']);
        foreach ($paths as $path) {
            try {
                $url = str_starts_with($path, 'http') ? $path : $baseUrl.'/'.ltrim($path, '/');
                if (isset($visited[$url])) {
                    continue;
                }

                $visited[$url] = true;
                $response = $client->get($url);
                if ($response->successful() || in_array($response->status(), [401, 403], true)) {
                    $raw['GET '.parse_url($url, PHP_URL_PATH)] = $response->body();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $combined = implode("\n\n", array_values($raw));
        $hiosoOnus = $this->parseHiosoOnuTable($raw);
        $tableOnus = $this->parseWebTables($raw);
        $textOnus = empty($hiosoOnus) ? $this->parseSnapshotText($combined) : [];
        $onus = $this->mergeOnus($hiosoOnus, $tableOnus, $textOnus);

        if (empty($onus) && $olt->snmp_community) {
            try {
                $snmp = $this->collectSnmpSnapshot($olt);
                $onus = $snmp['onus'] ?? [];
                $raw['snmp'] = $snmp['raw']['snmpwalk'] ?? '';
            } catch (\Throwable $e) {
                $raw['snmp_error'] = $e->getMessage();
            }
        }

        $ports = array_values(array_unique(array_filter(array_map(
            fn (array $onu) => $onu['pon_port'] ?? null,
            $onus
        ))));

        return [
            'meta' => [
                'olt' => $olt->name,
                'host' => $olt->management_ip,
                'port' => $olt->management_port ?: $this->defaultPort($olt),
                'protocol' => $olt->management_protocol ?: 'http',
                'vendor' => 'hioso',
                'collector' => 'web-hybrid',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count($ports),
                'onus_count' => count($onus),
                'warning' => empty($onus) ? 'Web reachable, but ONU table was not recognized yet. Use SNMP community or send firmware endpoint sample for exact parser.' : null,
            ],
            'pon_ports' => array_map(fn (string $item) => ['name' => $item], $ports),
            'onus' => $onus,
            'raw' => $this->trimRaw($raw),
            'commands' => array_keys($raw),
        ];
    }

    protected function collectSnmpSnapshot(Olt $olt): array
    {
        if (! $olt->management_ip || ! $olt->snmp_community) {
            throw new RuntimeException('Hioso SNMP requires management IP and SNMP community.');
        }

        $host = $olt->management_ip;
        $port = $olt->management_port ?: 161;
        $community = $olt->snmp_community;
        $walkOutput = $this->snmpWalk($host, $port, $community);
        $onus = $this->parseSnapshotText($walkOutput);
        $ports = array_values(array_unique(array_filter(array_map(
            fn (array $onu) => $onu['pon_port'] ?? null,
            $onus
        ))));

        return [
            'meta' => [
                'olt' => $olt->name,
                'host' => $host,
                'port' => $port,
                'protocol' => 'snmp',
                'vendor' => 'hioso',
                'collector' => 'snmpwalk',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count($ports),
                'onus_count' => count($onus),
                'warning' => empty($onus) ? 'SNMP responded, but ONU OIDs were not recognized yet.' : null,
            ],
            'pon_ports' => array_map(fn (string $item) => ['name' => $item], $ports),
            'onus' => $onus,
            'raw' => ['snmpwalk' => $walkOutput],
            'commands' => ['snmpwalk enterprises'],
        ];
    }

    public function onuDetail(Olt $olt, string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        [$portRef, $onuId] = $this->splitOnuRef($onuRef);
        $session = $this->connect($olt);
        $commands = [];

        try {
            $outputs = [
                'info' => $this->execFirstSuccessful($session, [
                    "show onu {$onuId} info",
                    "show onu {$onuId} information",
                    "show onu info {$portRef} {$onuId}",
                ], $commands),
                'status' => $this->execFirstSuccessful($session, [
                    "show onu {$onuId} status",
                    "show onu status {$portRef} {$onuId}",
                ], $commands),
                'optical' => $this->execFirstSuccessful($session, [
                    "show onu {$onuId} optical-info",
                    "show onu optical-info {$portRef} {$onuId}",
                    "show onu optical {$portRef} {$onuId}",
                ], $commands),
            ];

            return [
                'onu' => [
                    'onu_ref' => $onuRef,
                    'pon_port' => $portRef,
                    'state' => $this->parseStates($outputs['status'])[$onuRef]['state'] ?? null,
                    'phase_state' => $this->parseStates($outputs['status'])[$onuRef]['phase_state'] ?? null,
                    'serial_number' => $this->parseSerials(implode("\n", $outputs))[$onuRef] ?? null,
                    'onu_name' => $this->parseNames(implode("\n", $outputs))[$onuRef] ?? null,
                    'rx_power_dbm' => $this->parseOptical($outputs['optical'])[$onuRef]['rx_power_dbm'] ?? null,
                    'distance_m' => $this->parseOptical($outputs['optical'])[$onuRef]['distance_m'] ?? null,
                    'detail_raw' => $outputs,
                ],
                'raw' => $outputs,
                'commands' => $commands,
            ];
        } finally {
            $session->close();
        }
    }

    public function renameOnu(Olt $olt, string $onuRef, string $name): array
    {
        [$portRef, $onuId] = $this->splitOnuRef($this->normalizeOnuRef($onuRef));
        $name = $this->escapeCliValue($name);

        if ($name === '') {
            throw new RuntimeException('ONU name is required.');
        }

        return $this->runPonOperation($olt, "{$portRef}:{$onuId}", [
            "onu {$onuId} name {$name}",
            "onu {$onuId} description {$name}",
        ]);
    }

    public function rebootOnu(Olt $olt, string $onuRef): array
    {
        [$portRef, $onuId] = $this->splitOnuRef($this->normalizeOnuRef($onuRef));

        return $this->runPonOperation($olt, "{$portRef}:{$onuId}", [
            "onu {$onuId} reboot",
            'yes',
        ]);
    }

    public function setOnuAdminState(Olt $olt, string $onuRef, bool $enabled): array
    {
        [$portRef, $onuId] = $this->splitOnuRef($this->normalizeOnuRef($onuRef));

        return $this->runPonOperation($olt, "{$portRef}:{$onuId}", [
            $enabled ? "onu {$onuId} activate" : "onu {$onuId} deactivate",
        ]);
    }

    public function deleteOnu(Olt $olt, string $onuRef): array
    {
        [$portRef, $onuId] = $this->splitOnuRef($this->normalizeOnuRef($onuRef));

        return $this->runPonOperation($olt, "{$portRef}:{$onuId}", [
            "onu delete {$onuId}",
            "no onu {$onuId}",
            'yes',
        ], stopOnFirstSuccess: true);
    }

    public function authorizeOnu(Olt $olt, array $payload): array
    {
        $portRef = $this->normalizePonPort((string) ($payload['pon_port'] ?? ''));
        $onuId = (int) ($payload['onu_id'] ?? 0);
        $serial = $this->escapeCliValue(strtoupper((string) ($payload['serial_number'] ?? '')));
        $onuType = $this->escapeCliValue((string) ($payload['onu_type'] ?? 'auto'));
        $name = $this->escapeCliValue((string) ($payload['name'] ?? ''));

        if (! $portRef || $onuId < 1 || $onuId > 128 || $serial === '') {
            throw new RuntimeException('PON port, ONU ID, and serial number are required.');
        }

        $commands = [
            "onu {$onuId} type {$onuType} sn {$serial}",
        ];

        if ($name !== '') {
            $commands[] = "onu {$onuId} name {$name}";
        }

        return $this->runPonOperation($olt, "{$portRef}:{$onuId}", $commands, [
            'pon_port' => $portRef,
            'onu_id' => $onuId,
            'serial_number' => $serial,
            'onu_type' => $onuType,
            'name' => $name,
        ]);
    }

    protected function runPonOperation(Olt $olt, string $onuRef, array $commands, array $context = [], bool $stopOnFirstSuccess = false): array
    {
        [$portRef] = $this->splitOnuRef($onuRef);
        $session = $this->connect($olt);
        $executed = [];
        $outputs = [];

        try {
            foreach (['configure terminal', "interface gpon {$portRef}", "interface epon {$portRef}"] as $command) {
                $output = $session->command($command);
                $executed[] = $command;
                $outputs[$command] = $output;
                if (! $this->isCommandFailure($output) && str_contains($command, 'interface')) {
                    break;
                }
            }

            foreach ($commands as $command) {
                $output = $session->command($command);
                $executed[] = $command;
                $outputs[$command] = $output;

                if ($this->isCommandFailure($output)) {
                    if ($stopOnFirstSuccess) {
                        continue;
                    }
                    throw new RuntimeException("Hioso OLT command failed: {$command}\n{$output}");
                }

                if ($stopOnFirstSuccess && ! in_array(strtolower($command), ['yes'], true)) {
                    break;
                }
            }

            foreach (['exit', 'write'] as $command) {
                $output = $session->command($command);
                $executed[] = $command;
                $outputs[$command] = $output;
            }

            return [
                'success' => true,
                'vendor' => 'hioso',
                'onu_ref' => $onuRef,
                'context' => $context,
                'commands' => $executed,
                'outputs' => $outputs,
            ];
        } finally {
            $session->close();
        }
    }

    protected function connect(Olt $olt): HiosoCliSession
    {
        if (! $olt->management_ip || ! $olt->username || ! $olt->password) {
            throw new RuntimeException('Hioso OLT must have management IP, username, and password.');
        }

        $session = new HiosoCliSession(
            host: $olt->management_ip,
            port: $olt->management_port ?: $this->defaultPort($olt),
            protocol: $olt->management_protocol ?: 'telnet',
            username: $olt->username,
            password: $olt->password,
        );
        $session->open();

        foreach (['enable', 'terminal length 0', 'terminal page-break disable'] as $command) {
            try {
                $session->command($command);
            } catch (RuntimeException) {
                // Some Hioso firmware variants do not need enable or paging commands.
            }
        }

        return $session;
    }

    protected function baseUrl(Olt $olt): string
    {
        $protocol = $olt->management_protocol ?: 'http';
        $port = $olt->management_port ?: $this->defaultPort($olt);

        return "{$protocol}://{$olt->management_ip}:{$port}";
    }

    protected function attemptWebLogin($client, string $baseUrl, array &$raw, Olt $olt): void
    {
        // Gadang/HA7304 uses HTTP Basic Auth - no form login needed
    }

    protected function hiosoWebCandidatePaths(string $rootHtml): array
    {
        $paths = [
            '/',
            '/top.asp',
            '/item.asp',
            '/content.asp',
            '/onuAllPonOnuList.asp',
            '/onuOverviewPonList.asp',
            '/onuMacAuthedPonList.asp',
            '/onuAttemptPonList.asp',
            '/onuPortStatusPonList.asp',
            '/oltPonPortStatusPonList.asp',
        ];

        if (preg_match_all('/(?:href|src|action)=["\']([^"\']*(?:onu|pon|optic|status|diagnose)[^"\']*)["\']/i', $rootHtml, $matches)) {
            foreach ($matches[1] as $path) {
                $paths[] = html_entity_decode($path);
            }
        }

        if (preg_match_all('/redirect\([^,]+,\s*["\']([^"\']+)["\']\)/i', $rootHtml, $matches)) {
            foreach ($matches[1] as $path) {
                if (preg_match('/(onu|pon|optic|status|diagnose|gpon|epon)/i', $path)) {
                    $paths[] = html_entity_decode($path);
                }
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    protected function parseHiosoOnuTable(array $rawPages): array
    {
        $onus = [];

        foreach ($rawPages as $page) {
            $html = (string) $page;
            if (! str_contains($html, 'onutable')) {
                continue;
            }

            if (! preg_match('/var\s+onutable\s*=\s*new\s+Array\s*\((.*?)\);/is', $html, $match)) {
                continue;
            }

            preg_match_all("/'((?:\\\\'|[^'])*)'/", $match[1], $matches);
            $cells = array_map(
                fn (string $value) => trim(str_replace("\\'", "'", html_entity_decode($value))),
                $matches[1] ?? []
            );

            // Detect chunk size: 14 cells (newer firmware) or 22 cells (older firmware)
            $chunkSize = (count($cells) % 14 === 0) ? 14 : (count($cells) % 22 === 0 ? 22 : 14);

            foreach (array_chunk($cells, $chunkSize) as $row) {
                if (count($row) < 12 || ! preg_match('/^\d+\/\d+(?:\/\d+)?:\d+$/', $row[0] ?? '')) {
                    continue;
                }

                [$ponPort, $onuId] = explode(':', $row[0], 2);
                $status = strtolower($row[3] ?? '');

                if ($chunkSize === 14) {
                    $rxIdx = 11;
                    $txIdx = 10;
                    $distIdx = 7;
                    $onlineIdx = 12;
                    $offlineIdx = 13;
                } else {
                    // 22-field HA7304 Gadang/EPON format
                    // Index from JS render: 10=RTT(us), 11=Temp, 14=TxPower, 15=RxPower
                    $rxIdx = 15;
                    $txIdx = 14;
                    $distIdx = 10;
                    $onlineIdx = 16;
                    $offlineIdx = 17;
                }

                $onus[] = [
                    'onu_ref' => "{$ponPort}:{$onuId}",
                    'pon_port' => $ponPort,
                    'state' => $status === 'up' ? 'online' : ($status === 'down' ? 'offline' : ($row[3] ?? null)),
                    'phase_state' => $row[4] ?? null,
                    'serial_number' => $row[2] ?? null,
                    'onu_name' => trim($row[1] ?? '') ?: null,
                    'rx_power_dbm' => is_numeric($row[$rxIdx] ?? null) ? (float) $row[$rxIdx] : null,
                    'distance_m' => is_numeric($row[$distIdx] ?? null) && (float) $row[$distIdx] > 0
                        ? ($chunkSize === 22
                            ? (int) round(((float) $row[$distIdx]) * 1.6393 - 157)
                            : (int) round((float) $row[$distIdx]))
                        : null,
                    'detail_raw' => [
                        'source' => 'onuAllPonOnuList.asp:onutable',
                        'mac' => $row[2] ?? null,
                        'tx_power_dbm' => is_numeric($row[$txIdx] ?? null) ? (float) $row[$txIdx] : null,
                        'last_online_at' => $row[$onlineIdx] ?? null,
                        'last_offline_at' => $row[$offlineIdx] ?? null,
                    ],
                ];
            }
        }

        return $onus;
    }

    protected function parseWebTables(array $rawPages): array
    {
        $onus = [];

        foreach ($rawPages as $page) {
            $html = trim((string) $page);
            if ($html === '' || ! str_contains(strtolower($html), '<table')) {
                continue;
            }

            $dom = new DOMDocument;
            libxml_use_internal_errors(true);
            $dom->loadHTML($html);
            libxml_clear_errors();
            $xpath = new DOMXPath($dom);

            foreach ($xpath->query('//tr') ?: [] as $row) {
                $cells = [];
                foreach ($xpath->query('./th|./td', $row) ?: [] as $cell) {
                    $cells[] = trim(preg_replace('/\s+/', ' ', $cell->textContent) ?? '');
                }

                $line = implode(' ', array_filter($cells));
                if ($line === '' || ! preg_match('/(onu|ont|pon|gpon|epon|online|offline|dbm|serial|sn\b)/i', $line)) {
                    continue;
                }

                foreach ($this->parseSnapshotText($line) as $onu) {
                    $onus[$onu['onu_ref']] = $onu;
                }
            }
        }

        return array_values($onus);
    }

    protected function parseSnapshotText(string $text): array
    {
        $refs = $this->extractOnuRefs($text);
        $states = $this->parseStates($text);
        $optical = $this->parseOptical($text);
        $names = $this->parseNames($text);
        $serials = $this->parseSerials($text);

        return array_map(function (string $onuRef) use ($states, $optical, $names, $serials) {
            return [
                'onu_ref' => $onuRef,
                'pon_port' => $this->extractPortFromOnuRef($onuRef),
                'state' => $states[$onuRef]['state'] ?? null,
                'phase_state' => $states[$onuRef]['phase_state'] ?? null,
                'serial_number' => $serials[$onuRef] ?? null,
                'onu_name' => $names[$onuRef] ?? null,
                'rx_power_dbm' => $optical[$onuRef]['rx_power_dbm'] ?? null,
                'distance_m' => $optical[$onuRef]['distance_m'] ?? null,
                'detail_raw' => null,
            ];
        }, $refs);
    }

    protected function mergeOnus(array ...$groups): array
    {
        $merged = [];

        foreach ($groups as $onus) {
            foreach ($onus as $onu) {
                $ref = $onu['onu_ref'] ?? null;
                if (! $ref) {
                    continue;
                }

                $merged[$ref] = array_filter(
                    array_merge($merged[$ref] ?? [], $onu),
                    fn ($value) => $value !== null && $value !== ''
                ) + ['onu_ref' => $ref];
            }
        }

        return array_values($merged);
    }

    protected function snmpWalk(string $host, int $port, string $community): string
    {
        $process = new Process([
            'snmpwalk',
            '-v2c',
            '-c',
            $community,
            '-On',
            '-t',
            '2',
            '-r',
            '1',
            "{$host}:{$port}",
            '.1.3.6.1.4.1',
        ]);
        $process->setTimeout(25);
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        if (
            ! $process->isSuccessful()
            || preg_match('/(timeout|no response|unknown host|authentication failure)/i', $output)
        ) {
            throw new RuntimeException($output !== '' ? $output : 'snmpwalk failed or timed out.');
        }

        if ($output === '') {
            throw new RuntimeException('snmpwalk returned empty response.');
        }

        return $output;
    }

    protected function trimRaw(array $raw): array
    {
        return collect($raw)
            ->map(fn ($value) => is_string($value) ? str($value)->limit(5000)->toString() : $value)
            ->all();
    }

    protected function execAny(HiosoCliSession $session, array $commands, array &$executedCommands): string
    {
        $errors = [];

        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $session->command($command);

            if (! $this->isCommandFailure($output)) {
                return $output;
            }

            $errors[] = "[{$command}] {$output}";
        }

        throw new RuntimeException(implode("\n\n", $errors));
    }

    protected function execFirstSuccessful(HiosoCliSession $session, array $commands, array &$executedCommands): string
    {
        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $session->command($command);

            if (! $this->isCommandFailure($output)) {
                return $output;
            }
        }

        return '';
    }

    protected function isCommandFailure(string $output): bool
    {
        $normalized = strtoupper($output);

        foreach (['INVALID', 'UNKNOWN', 'INCOMPLETE', 'FAIL', 'ERROR', 'AMBIGUOUS'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected function extractOnuRefs(string $output): array
    {
        preg_match_all('/(?:gpon|epon)?\s*(\d+\/\d+\/\d+)\s*[:\/]\s*(\d{1,3})/i', $output, $matches, PREG_SET_ORDER);

        $refs = [];
        foreach ($matches as $match) {
            $refs[] = strtolower("{$match[1]}:{$match[2]}");
        }

        return array_values(array_unique($refs));
    }

    protected function parseStates(string $output): array
    {
        $results = [];
        foreach (preg_split('/\n+/', $output) ?: [] as $line) {
            foreach ($this->extractOnuRefs($line) as $ref) {
                $upper = strtoupper($line);
                $results[$ref] = [
                    'state' => str_contains($upper, 'ONLINE') || str_contains($upper, 'ACTIVE') ? 'online' : (str_contains($upper, 'OFFLINE') || str_contains($upper, 'INACTIVE') ? 'offline' : null),
                    'phase_state' => preg_match('/\b(O[1-9])\b/i', $line, $phase) ? strtoupper($phase[1]) : null,
                ];
            }
        }

        return $results;
    }

    protected function parseOptical(string $output): array
    {
        $results = [];
        foreach (preg_split('/\n+/', $output) ?: [] as $line) {
            foreach ($this->extractOnuRefs($line) as $ref) {
                if (preg_match('/(-?\d+(?:\.\d+)?)\s*(?:dbm|dBm)/', $line, $power)) {
                    $results[$ref]['rx_power_dbm'] = (float) $power[1];
                }
                if (preg_match('/(\d+)\s*m(?:eter)?\b/i', $line, $distance)) {
                    $results[$ref]['distance_m'] = (int) $distance[1];
                }
            }
        }

        return $results;
    }

    protected function parseNames(string $output): array
    {
        $results = [];
        foreach (preg_split('/\n+/', $output) ?: [] as $line) {
            foreach ($this->extractOnuRefs($line) as $ref) {
                if (preg_match('/(?:name|description|desc)\s+([^\s].+)$/i', $line, $match)) {
                    $results[$ref] = trim($match[1]);

                    continue;
                }

                $columns = preg_split('/\s+/', trim($line)) ?: [];
                $tail = end($columns);
                if ($tail && ! preg_match('/^(online|offline|active|inactive|enable|disable|[A-F0-9]{8,}|-?\d+(\.\d+)?|dBm)$/i', $tail)) {
                    $results[$ref] = $tail;
                }
            }
        }

        return $results;
    }

    protected function parseSerials(string $output): array
    {
        $results = [];
        foreach (preg_split('/\n+/', $output) ?: [] as $line) {
            foreach ($this->extractOnuRefs($line) as $ref) {
                if (preg_match('/(?:sn|serial)\s*[:=]?\s*([A-Z0-9]{8,})/i', $line, $match)) {
                    $results[$ref] = strtoupper($match[1]);
                } elseif (preg_match('/\b([A-Z]{4}[A-Z0-9]{8,}|[A-F0-9]{12,16})\b/i', $line, $match)) {
                    $results[$ref] = strtoupper($match[1]);
                }
            }
        }

        return $results;
    }

    protected function normalizeOnuRef(string $onuRef): string
    {
        $onuRef = strtolower(trim($onuRef));
        $onuRef = preg_replace('/^(gpon|epon)[-_]?(onu)?[_-]?/i', '', $onuRef) ?? $onuRef;
        $onuRef = str_replace('/', '/', $onuRef);

        if (preg_match('/^(\d+\/\d+(?:\/\d+)?):(\d{1,3})$/', $onuRef, $match)) {
            return "{$match[1]}:{$match[2]}";
        }

        throw new RuntimeException('Invalid Hioso ONU ref. Expected format 0/1:3 or 1/2/1:3.');
    }

    protected function normalizePonPort(string $portRef): ?string
    {
        $portRef = strtolower(trim($portRef));
        $portRef = preg_replace('/^(gpon|epon)[-_]?/i', '', $portRef) ?? $portRef;

        return preg_match('/^\d+\/\d+(?:\/\d+)?$/', $portRef) ? $portRef : null;
    }

    protected function splitOnuRef(string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        [$portRef, $onuId] = explode(':', $onuRef, 2);

        return [$portRef, (int) $onuId];
    }

    protected function extractPortFromOnuRef(string $onuRef): ?string
    {
        return $this->splitOnuRef($this->normalizeOnuRef($onuRef))[0] ?? null;
    }

    protected function escapeCliValue(string $value): string
    {
        return trim(str_replace(["\r", "\n", '"'], ['', '', ''], $value));
    }

    protected function defaultPort(Olt $olt): int
    {
        return ($olt->management_protocol ?: 'telnet') === 'ssh' ? 22 : 23;
    }
}

class HiosoCliSession
{
    protected SSH2|false|null $ssh = null;

    /** @var resource|null */
    protected $socket = null;

    public function __construct(
        protected string $host,
        protected int $port,
        protected string $protocol,
        protected string $username,
        protected string $password,
    ) {}

    public function open(): void
    {
        if ($this->protocol === 'ssh') {
            $this->ssh = new SSH2($this->host, $this->port, 10);
            if (! $this->ssh->login($this->username, $this->password)) {
                throw new RuntimeException('Failed to log in to Hioso OLT via SSH.');
            }
            $this->ssh->enablePTY();
            $this->ssh->setTimeout(10);
            $this->ssh->read();

            return;
        }

        if ($this->protocol !== 'telnet') {
            throw new RuntimeException('Hioso OLT backend supports SSH and Telnet only.');
        }

        $errno = 0;
        $errstr = '';
        $this->socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, 10);
        if (! $this->socket) {
            throw new RuntimeException("Failed to connect to Hioso OLT via Telnet: {$errstr} ({$errno}).");
        }

        stream_set_timeout($this->socket, 10);
        $this->readUntil('/(?:login|username|user name)[: ]*$/i');
        $this->write($this->username);
        $this->readUntil('/password[: ]*$/i');
        $this->write($this->password);
        $this->readPrompt();
    }

    public function command(string $command): string
    {
        $this->write($command);

        return $this->normalize($this->readPrompt());
    }

    public function close(): void
    {
        if ($this->ssh instanceof SSH2) {
            $this->ssh->disconnect();
        }

        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    protected function write(string $value): void
    {
        if ($this->ssh instanceof SSH2) {
            $this->ssh->write($value."\n");

            return;
        }

        if (! is_resource($this->socket)) {
            throw new RuntimeException('Telnet socket is not connected.');
        }

        fwrite($this->socket, $value."\n");
    }

    protected function readPrompt(): string
    {
        return $this->readUntil('/(?:[#>$]\s*$|\)\s*#\s*$|\]\s*#\s*$)/m');
    }

    protected function readUntil(string $pattern): string
    {
        if ($this->ssh instanceof SSH2) {
            $output = $this->ssh->read($pattern);
            if (! is_string($output)) {
                throw new RuntimeException('Failed reading SSH output from Hioso OLT.');
            }

            return $output;
        }

        if (! is_resource($this->socket)) {
            throw new RuntimeException('Telnet socket is not connected.');
        }

        $buffer = '';
        $deadline = time() + 10;
        while (time() <= $deadline) {
            $chunk = fread($this->socket, 4096);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (preg_match($pattern, $buffer)) {
                    return $buffer;
                }
            }
            usleep(100000);
        }

        throw new RuntimeException('Timed out reading Hioso OLT prompt.');
    }

    protected function normalize(string $output): string
    {
        $output = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;
        $output = str_replace("\r", '', $output);

        return trim($output);
    }
}
