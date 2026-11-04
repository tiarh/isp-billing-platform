<?php

namespace App\Services;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\Router;
use App\Services\Olt\OltProvisioningGuard;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Huawei MA5608T-only migration adapter.
 *
 * This class is deliberately separate from the C300/FiberHome path. It plans
 * and, only after the existing OLT safety gate approves, executes Huawei CLI
 * commands. No customer, old ONU, MikroTik write, or PSB mutation belongs here.
 */
class HuaweiOltOnlyProvisioningService extends OltHuaweiMa5608tService
{
    public const MODE = 'olt_only_huawei';

    private const MAX_ONU_ID = 127;

    private const SRV_PROFILE_ID = 10;

    private const TR069_SERVER_PROFILE_ID = 1;

    private const TRAFFIC_TABLE = '200M';

    /**
     * MA5608T may accept `ont add` before the ONT is visible in read-back.
     * Keep the wait bounded so a missing/failed ONT cannot block a worker
     * indefinitely or allow service-port writes without positive evidence.
     */
    private const ONT_READBACK_MAX_ATTEMPTS = 10;

    private const ONT_READBACK_RETRY_DELAY_MICROSECONDS = 3_000_000;

    public function __construct(protected MikrotikService $mikrotik) {}

    /** @var array<int, array{vlan: int, line_profile: int, tr069_vlan: int}> */
    private const STANDARD_PORT_PROFILES = [
        0 => ['vlan' => 101, 'line_profile' => 10, 'tr069_vlan' => 69],
        1 => ['vlan' => 102, 'line_profile' => 20, 'tr069_vlan' => 69],
        2 => ['vlan' => 103, 'line_profile' => 21, 'tr069_vlan' => 69],
        3 => ['vlan' => 104, 'line_profile' => 22, 'tr069_vlan' => 69],
        4 => ['vlan' => 105, 'line_profile' => 23, 'tr069_vlan' => 69],
        5 => ['vlan' => 106, 'line_profile' => 24, 'tr069_vlan' => 69],
        6 => ['vlan' => 107, 'line_profile' => 25, 'tr069_vlan' => 69],
        7 => ['vlan' => 108, 'line_profile' => 26, 'tr069_vlan' => 69],
        8 => ['vlan' => 109, 'line_profile' => 27, 'tr069_vlan' => 69],
        9 => ['vlan' => 110, 'line_profile' => 28, 'tr069_vlan' => 69],
        10 => ['vlan' => 111, 'line_profile' => 29, 'tr069_vlan' => 69],
        11 => ['vlan' => 112, 'line_profile' => 30, 'tr069_vlan' => 69],
        12 => ['vlan' => 113, 'line_profile' => 31, 'tr069_vlan' => 69],
        13 => ['vlan' => 114, 'line_profile' => 32, 'tr069_vlan' => 69],
        14 => ['vlan' => 115, 'line_profile' => 33, 'tr069_vlan' => 69],
        15 => ['vlan' => 116, 'line_profile' => 34, 'tr069_vlan' => 69],
    ];

    /**
     * @return array{matches: list<array{pon_port: string, serial_number: string, vendor_id: ?string}>, raw: string, command: string}
     */
    public function collectAutofind(Olt $olt): array
    {
        $command = 'display ont autofind all';
        $ssh = $this->connect($olt);

        try {
            $raw = $this->trimRaw($this->run($ssh, $command));
        } finally {
            $this->disconnectSafely($ssh);
        }

        return [
            'matches' => $this->parseAutofindOutput($raw),
            'raw' => $raw,
            'command' => $command,
        ];
    }

    /**
     * Read the occupied ONT IDs on one PON. This is intentionally narrower
     * than the inventory snapshot: placement must not scan every PON before a
     * new ONT is added.
     *
     * @return array{pon_port: string, onus: list<array<string, mixed>>, occupied_ids: list<int>, raw: string, command: string}
     */
    public function collectPortOnus(Olt $olt, string $ponPort): array
    {
        $normalizedPort = $this->normalizePonPort($ponPort);
        if (! $normalizedPort) {
            throw new RuntimeException('PON Huawei tidak valid.');
        }

        $port = (int) substr($normalizedPort, strrpos($normalizedPort, '/') + 1);
        // MA5608T requires the `all` selector for a targeted PON table. Without
        // it the firmware returns `{ all<K> }` help text, which is not an empty
        // PON and must never make the allocator choose ONT-ID 0.
        $command = "display ont info 0 0 {$port} all";
        $ssh = $this->connect($olt);

        try {
            $raw = $this->trimRaw($this->run($ssh, $command));
        } finally {
            $this->disconnectSafely($ssh);
        }

        $parsed = $this->parsePortOnusOutput($raw, $normalizedPort, false);

        return [
            'pon_port' => $parsed['pon_port'],
            'onus' => $parsed['onus'],
            'occupied_ids' => $parsed['occupied_ids'],
            'raw' => $raw,
            'command' => $command,
        ];
    }

    /**
     * Parse the `display ont info 0 0 {port} all` answer. PSB/migration call it
     * through collectPortOnus() with $allowEmpty = false (any failure text
     * throws). Only the UI path passes true: the exact "no ONT available"
     * answer of an empty PON then means "no ONT yet" instead of an error. The
     * `{ all<K> }` help text is never accepted as empty.
     *
     * @return array{pon_port: string, onus: list<array<string, mixed>>, occupied_ids: list<int>}
     */
    public function parsePortOnusOutput(string $raw, string $normalizedPort, bool $allowEmpty = false): array
    {
        $port = (int) substr($normalizedPort, strrpos($normalizedPort, '/') + 1);
        $command = "display ont info 0 0 {$port} all";

        if ($allowEmpty && $this->isEmptyPortAnswer($raw, $command)) {
            return ['pon_port' => $normalizedPort, 'onus' => [], 'occupied_ids' => []];
        }

        if ($this->isCommandFailure($raw) || $this->isIncompleteOntInfoOutput($raw)) {
            throw new RuntimeException("Huawei menolak command {$command}.");
        }

        $onus = $this->parsePortOutput($raw, $port);
        $occupiedIds = $this->parseOccupiedOnuIds($raw, $normalizedPort);
        $parsedIds = $this->occupiedOnuIds($onus, $normalizedPort);
        foreach (array_values(array_diff($occupiedIds, $parsedIds)) as $id) {
            $onus[] = [
                'onu_ref' => "{$normalizedPort}:{$id}",
                'pon_port' => $normalizedPort,
                'onu_id' => $id,
            ];
        }

        return [
            'pon_port' => $normalizedPort,
            'onus' => $onus,
            'occupied_ids' => $occupiedIds,
        ];
    }

    /**
     * True only when the command echo is present and the rest of the answer is
     * exactly the empty-PON failure line (plus prompt lines).
     */
    private function isEmptyPortAnswer(string $raw, string $command): bool
    {
        if ($this->isIncompleteOntInfoOutput($raw) || ! $this->commandEchoSeen($raw, $command)) {
            return false;
        }

        $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $raw) ?? $raw;
        $failureSeen = false;
        foreach (preg_split('/\R/', $clean) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $this->commandEchoSeen($line, $command)) {
                continue;
            }
            if (preg_match('/^[\w.-]+(?:\([\w-]+\))?[#>]$/', $line) === 1) {
                continue;
            }
            if (preg_match('/^%?\s*Failure:\s*There is no ONT available\.?$/i', $line) === 1) {
                $failureSeen = true;

                continue;
            }

            return false;
        }

        return $failureSeen;
    }

    // ------------------------------------------------------------------
    // UI-only reads (Huawei tab "Lihat ONT baru"). Short bounded sessions;
    // PSB/migration never call these.
    // ------------------------------------------------------------------

    public const UI_CONNECT_TIMEOUT_SECONDS = 5;

    public const UI_READ_TIMEOUT_SECONDS = 8;

    public const UI_SESSION_DEADLINE_SECONDS = 20;

    public const UI_LOCK_SECONDS = 60;

    private const UI_OUTPUT_MAX_CHARS = 200000;

    /**
     * One short SSH session for the UI: no waiting for a busy lock, lock TTL
     * 60 s, whole conversation at most 20 s. Limits are always reset so a later
     * PSB call on this instance gets the normal defaults.
     */
    protected function uiSession(Olt $olt, callable $fn): mixed
    {
        $this->sessionLimits = [
            'connect' => self::UI_CONNECT_TIMEOUT_SECONDS,
            'read' => self::UI_READ_TIMEOUT_SECONDS,
            'lock' => self::UI_LOCK_SECONDS,
            'wait' => 0,
        ];
        $this->sessionDeadlineAt = $this->nowFloat() + self::UI_SESSION_DEADLINE_SECONDS;
        $ssh = null;

        try {
            $ssh = $this->connect($olt);

            return $fn($ssh);
        } finally {
            $this->sessionLimits = null;
            $this->sessionDeadlineAt = null;
            $this->disconnectSafely($ssh);
        }
    }

    private function uiRun(SSH2 $ssh, string $command): string
    {
        return mb_substr($this->run($ssh, $command), 0, self::UI_OUTPUT_MAX_CHARS);
    }

    /**
     * @return array{entries: list<array<string, ?string>>, partial: bool, total_reported: ?int}
     */
    public function listAutofindForUi(Olt $olt): array
    {
        /** @var string $raw */
        $raw = $this->uiSession($olt, fn (SSH2 $ssh): string => $this->uiRun($ssh, 'display ont autofind all'));

        return $this->parseAutofindEntries($raw);
    }

    /**
     * One session: autofind list and, when the SN is found on exactly one PON,
     * the port table of that PON (empty PON allowed).
     *
     * @return array{entries: list<array<string, ?string>>, matches: list<array<string, ?string>>, port: ?array{pon_port: string, onus: list<array<string, mixed>>, occupied_ids: list<int>}}
     */
    public function uiPlacementRead(Olt $olt, string $serial): array
    {
        $key = $this->canonicalSerial($serial);

        return $this->uiSession($olt, function (SSH2 $ssh) use ($key): array {
            $list = $this->parseAutofindEntries($this->uiRun($ssh, 'display ont autofind all'));
            $matches = array_values(array_filter(
                $list['entries'],
                fn (array $row): bool => $this->canonicalSerial((string) ($row['sn'] ?? '')) === $key,
            ));

            $port = null;
            if (count($matches) === 1) {
                $pon = $this->normalizePonPort((string) ($matches[0]['pon_port'] ?? ''));
                if ($pon !== null) {
                    $portNumber = (int) substr($pon, strrpos($pon, '/') + 1);
                    $port = $this->parsePortOnusOutput(
                        $this->uiRun($ssh, "display ont info 0 0 {$portNumber} all"),
                        $pon,
                        true,
                    );
                }
            }

            return ['entries' => $list['entries'], 'matches' => $matches, 'port' => $port];
        });
    }

    /**
     * 16 hex chars whose first 4 bytes are letters (46485454... = FHTT) become
     * VENDOR + last 8 hex; every other input is only upper-cased and stripped.
     */
    public function canonicalSerial(string $value): string
    {
        $clean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
        if (preg_match('/^[0-9A-F]{16}$/', $clean) === 1) {
            $vendor = hex2bin(substr($clean, 0, 8));
            if ($vendor !== false && preg_match('/^[A-Z]{4}$/', $vendor) === 1) {
                return $vendor.substr($clean, 8);
            }
        }

        return $clean;
    }

    /**
     * Parse the FULL `display ont autofind all` text (no 5000-char cut). Never
     * reads or keeps Password / Loid / Checkcode.
     *
     * @return array{entries: list<array<string, ?string>>, partial: bool, total_reported: ?int}
     */
    public function parseAutofindEntries(string $output): array
    {
        $text = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;
        $text = preg_replace('/----\s*More\b.*?----/i', '', $text) ?? $text;

        $entries = [];
        $current = [];
        $flush = function () use (&$entries, &$current): void {
            $pon = $this->normalizePonPort((string) ($current['pon_port'] ?? ''));
            $sn = (string) ($current['sn'] ?? '');
            if ($pon !== null && $sn !== '') {
                $entries[] = [
                    'sn' => $sn,
                    'sn_hex' => $current['sn_hex'] ?? null,
                    'pon_port' => $pon,
                    'vendor_id' => $current['vendor_id'] ?? null,
                    'model' => $current['model'] ?? null,
                    'software' => $current['software'] ?? null,
                    'seen_at' => $current['seen_at'] ?? null,
                ];
            }
            $current = [];
        };

        $total = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/number of GPON autofind ONT is\s*(\d+)/i', $line, $m) === 1) {
                $total = (int) $m[1];

                continue;
            }
            if (preg_match('/^\s*([A-Za-z\/][A-Za-z \/]*?)\s*[:：]\s*(.*?)\s*$/u', $line, $m) !== 1) {
                continue;
            }
            $key = strtolower((string) preg_replace('/\s+/', '', $m[1]));
            $value = trim($m[2]);

            switch ($key) {
                case 'number':
                    $flush();
                    break;
                case 'f/s/p':
                    if (isset($current['pon_port'])) {
                        $flush();
                    }
                    $current['pon_port'] = $value;
                    break;
                case 'ontsn':
                    $token = preg_split('/\s+/', $value)[0] ?? '';
                    $canonical = $this->canonicalSerial($token);
                    if ($canonical === '') {
                        break;
                    }
                    $current['sn'] = $canonical;
                    $hex = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $token) ?? '');
                    $current['sn_hex'] = $hex !== $canonical && preg_match('/^[0-9A-F]{16}$/', $hex) === 1 ? $hex : null;
                    break;
                case 'vendorid':
                    $current['vendor_id'] = $value !== '' ? $value : null;
                    break;
                case 'ontequipmentid':
                    $current['model'] = $value !== '' ? $value : null;
                    break;
                case 'ontsoftwareversion':
                    $current['software'] = $value !== '' ? $value : null;
                    break;
                case 'ontautofindtime':
                    $current['seen_at'] = $value !== '' ? $value : null;
                    break;
                default:
                    // Password, Loid, Checkcode and everything else is ignored on purpose.
                    break;
            }
        }
        $flush();

        $unique = [];
        foreach ($entries as $entry) {
            $unique[$entry['pon_port'].'|'.$entry['sn']] = $entry;
        }
        $entries = array_values($unique);
        usort($entries, function (array $a, array $b): int {
            $pa = (int) substr((string) $a['pon_port'], strrpos((string) $a['pon_port'], '/') + 1);
            $pb = (int) substr((string) $b['pon_port'], strrpos((string) $b['pon_port'], '/') + 1);

            return [$pa, $a['sn']] <=> [$pb, $b['sn']];
        });

        if ($entries === []) {
            $emptyPhrase = preg_match('/There is no ONT autofind|automatically found ONTs do not exist|no ONT available/i', $text) === 1;
            if (! $emptyPhrase && $total === null) {
                throw new RuntimeException('OLT menolak perintah daftar ONT.');
            }

            return ['entries' => [], 'partial' => $total !== null && $total > 0, 'total_reported' => $total ?? 0];
        }

        return [
            'entries' => $entries,
            'partial' => $total !== null && $total !== count($entries),
            'total_reported' => $total,
        ];
    }

    /**
     * Parse IDs from Huawei table/detail output variants. The command's PON
     * is authoritative for detail rows that contain only `ONT-ID: <id>`.
     * MA5608T firmware may omit the F/S/P column for a targeted PON query, so
     * accept a row beginning with ONT-ID + serial + state as well.
     *
     * @return list<int>
     */
    public function parseOccupiedOnuIds(string $output, string $ponPort): array
    {
        $normalizedPort = $this->normalizePonPort($ponPort);
        if (! $normalizedPort) {
            return [];
        }
        $port = (int) substr($normalizedPort, strrpos($normalizedPort, '/') + 1);
        $ids = [];
        $portPattern = preg_quote((string) $port, '/');

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*ONT[\s-]?ID\s*(?::|=|\s)\s*(\d{1,3})/iu', $line, $match)) {
                $ids[] = (int) $match[1];
            }
            if (preg_match('/^\s*(\d{1,3})\s+([A-Z]{4,5}-?[A-Z0-9]{4,63})\s+(?:active|inactive|online|offline|initial)\b/iu', $line, $match)) {
                $ids[] = (int) $match[1];
            }
            if (preg_match('/^\s*0\s*\/\s*0\s*\/\s*'.$portPattern.'(?:\s*\|\s*|\s+|:)\s*(\d{1,3})(?:\s|\||$)/iu', $line, $match)) {
                $ids[] = (int) $match[1];
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id >= 0 && $id <= self::MAX_ONU_ID)));
        sort($ids);

        return $ids;
    }

    /**
     * Build the read-only placement and router-secret preflight for an OLT-only
     * migration. The PPPoE password is read in memory from the target router,
     * then discarded; it is never returned, persisted, logged, or sent to WA.
     *
     * @return array<string, mixed>
     */
    public function preflight(CustomerMigration $migration): array
    {
        $base = [
            'eligible' => false,
            'reasons' => [],
            'warnings' => [],
            'mode' => self::MODE,
            'migration' => ['id' => $migration->id, 'status' => $migration->status],
            'router_secret' => [
                'found' => null,
                'source' => 'target_router_local_pppoe_secret',
                'router_id' => $migration->router_id,
            ],
            'checked_at' => now()->toIso8601String(),
        ];

        try {
            $olt = $this->resolveOltForMigration($migration);
            $router = $this->resolveRouterForMigration($migration);
            $sourceRouter = $this->resolveSourceRouterForMigration($migration, $router);
            $crossRouter = $sourceRouter !== null && (int) $sourceRouter->id !== (int) $router->id;

            // Cross-router (e.g. Bumiayu -> Gadang): the PPPoE secret lives on
            // the SOURCE router and is copied to the target at execute time, so
            // verify the source secret here (read-only) instead of the target.
            $secretRouter = $crossRouter ? $sourceRouter : $router;
            $secret = $this->verifyRouterSecret($secretRouter, (string) $migration->pppoe_user);
            $placement = $this->discoverPlacement($olt, $migration);
            $reasons = [];

            if (($secret['found'] ?? null) !== true) {
                $reasons[] = ($secret['found'] ?? null) === false
                    ? 'router_pppoe_secret_missing'
                    : 'router_pppoe_secret_unverified';
            }

            $base['eligible'] = $reasons === [];
            $base['reasons'] = $reasons;
            $base['warnings'] = $secret['warning'] ? [$secret['warning']] : [];
            $base['olt'] = [
                'id' => $olt->id,
                'name' => $olt->name,
                'vendor' => $olt->vendor,
                'management_ip' => $olt->management_ip,
            ];
            $base['router'] = ['id' => $router->id, 'name' => $router->name];
            $base['source_router'] = $sourceRouter ? ['id' => $sourceRouter->id, 'name' => $sourceRouter->name] : null;
            $base['cross_router'] = $crossRouter;
            $base['router_secret'] = [
                'found' => $secret['found'],
                'source' => $crossRouter ? 'source_router_local_pppoe_secret' : 'target_router_local_pppoe_secret',
                'router_id' => $secretRouter?->id,
                'username' => $this->normalizeDescription((string) $migration->pppoe_user),
            ];
            $base['placement'] = $placement['placement'];
            $base['plan'] = $placement['plan'];
            $base['checked_at'] = now()->toIso8601String();

            if ($placement['warning'] !== null) {
                $base['warnings'][] = $placement['warning'];
            }

            return $base;
        } catch (HuaweiOltSessionBusyException $e) {
            // Read-only discovery could not get the OLT session: not an
            // ineligible target. Callers decide (retry message / safe default).
            throw $e;
        } catch (RuntimeException $e) {
            $base['reasons'] = [$this->preflightFailureCode($e->getMessage())];
            $base['warnings'] = ['Huawei OLT atau router belum dapat diverifikasi read-only.'];

            return $base;
        } catch (\Throwable) {
            $base['reasons'] = ['preflight_failed'];
            $base['warnings'] = ['Preflight OLT-only gagal secara aman; tidak ada write dilakukan.'];

            return $base;
        }
    }

    /**
     * @return array{placement: array<string, mixed>, plan: array<string, mixed>, warning: ?string}
     */
    protected function discoverPlacement(Olt $olt, CustomerMigration $migration): array
    {
        $serial = $this->normalizeSerial((string) $migration->new_sn);
        if ($serial === '' || ! preg_match('/^[A-Z0-9-]{8,64}$/', $serial)) {
            throw new RuntimeException('SN baru Huawei tidak valid.');
        }

        // Autofind is the source of truth for a new ONT's physical PON. Do this
        // before any ONT-ID decision so a stale/user-supplied port cannot drive
        // an `ont add` command.
        $autofind = $this->collectAutofind($olt);
        $matches = array_values(array_filter(
            $autofind['matches'],
            fn (array $row): bool => $this->serialKey((string) ($row['serial_number'] ?? '')) === $this->serialKey($serial),
        ));
        if ($matches === []) {
            // Keep the resume/idempotent path for an ONT that was already
            // registered and therefore no longer appears in autofind.
            $snapshot = $this->collectSnapshot($olt);
            $registered = array_values(array_filter(
                (array) ($snapshot['onus'] ?? []),
                fn (array $onu): bool => $this->serialKey((string) ($onu['serial_number'] ?? '')) === $this->serialKey($serial),
            ));
            if (count($registered) > 1) {
                throw new RuntimeException('SN baru terdaftar lebih dari satu kali di OLT Huawei.');
            }

            if ($registered !== []) {
                $onu = $registered[0];
                $ponPort = $this->normalizePonPort((string) ($onu['pon_port'] ?? ''));
                $onuId = $this->onuIdFromRef((string) ($onu['onu_ref'] ?? ''));
                if (! $ponPort || $onuId === null) {
                    throw new RuntimeException('Referensi ONU Huawei yang sudah terdaftar tidak lengkap.');
                }
                $hint = $this->requestedOnuHint($migration);
                if ($hint !== null && strtolower($hint) !== strtolower("{$ponPort}:{$onuId}")) {
                    throw new RuntimeException('SN baru sudah terdaftar pada referensi ONU berbeda.');
                }

                return [
                    'placement' => [
                        'source' => 'registered_olt_snapshot',
                        'pon_port' => $ponPort,
                        'onu_id' => $onuId,
                        'onu_ref' => "{$ponPort}:{$onuId}",
                        'serial_number' => $serial,
                        'vendor_id' => $onu['vendor_id'] ?? null,
                    ],
                    'plan' => $this->buildPlan(
                        $serial,
                        (string) $migration->pppoe_user,
                        $ponPort,
                        $onuId,
                        (string) ($onu['vendor_id'] ?? ''),
                        $this->nullableInt($migration->vlan),
                        $this->nullableInt($migration->tr069_vlan),
                        true,
                    ),
                    'warning' => 'SN sudah terdaftar; hanya service-port yang direncanakan bila approval lanjutan diberikan.',
                ];
            }

            throw new RuntimeException('SN baru belum muncul pada autofind Huawei. Pastikan ONT menyala dan tunggu discovery.');
        }
        if (count($matches) > 1) {
            throw new RuntimeException('SN baru terdeteksi pada lebih dari satu PON Huawei.');
        }

        $match = $matches[0];
        $ponPort = $this->normalizePonPort((string) ($match['pon_port'] ?? ''));
        if (! $ponPort) {
            throw new RuntimeException('PON hasil autofind Huawei tidak valid.');
        }
        $hint = $this->requestedOnuHint($migration);
        $requestedPort = $this->normalizePonPort((string) $migration->new_pon_port)
            ?: ($hint ? $this->normalizePonPort($hint) : null);
        if ($requestedPort !== null && $requestedPort !== $ponPort) {
            throw new RuntimeException("SN terdeteksi di {$ponPort}, bukan PON {$requestedPort}.");
        }

        // Query only the discovered PON for occupancy. Any legacy ONT-ID or
        // ONU-ref in the message is a hint, never the value sent to Huawei.
        $portSnapshot = $this->collectPortOnus($olt, $ponPort);
        $usedOnus = $portSnapshot['onus'];
        $registered = array_values(array_filter(
            $usedOnus,
            fn (array $onu): bool => $this->serialKey((string) ($onu['serial_number'] ?? '')) === $this->serialKey($serial),
        ));
        if (count($registered) > 1) {
            throw new RuntimeException('SN baru terdaftar lebih dari satu kali di PON Huawei.');
        }
        if ($registered !== []) {
            $onu = $registered[0];
            $registeredId = isset($onu['onu_id']) && is_numeric($onu['onu_id'])
                ? (int) $onu['onu_id']
                : $this->onuIdFromRef((string) ($onu['onu_ref'] ?? ''));
            if ($registeredId === null) {
                throw new RuntimeException('Referensi ONU Huawei yang sudah terdaftar tidak lengkap.');
            }
            if ($hint !== null && strtolower($hint) !== strtolower("{$ponPort}:{$registeredId}")) {
                throw new RuntimeException('SN baru sudah terdaftar pada referensi ONU berbeda.');
            }

            return [
                'placement' => [
                    'source' => 'registered_targeted_port_readback',
                    'pon_port' => $ponPort,
                    'onu_id' => $registeredId,
                    'onu_ref' => "{$ponPort}:{$registeredId}",
                    'serial_number' => $serial,
                    'vendor_id' => $match['vendor_id'] ?? ($onu['vendor_id'] ?? null),
                ],
                'plan' => $this->buildPlan(
                    $serial,
                    (string) $migration->pppoe_user,
                    $ponPort,
                    $registeredId,
                    (string) ($match['vendor_id'] ?? ($onu['vendor_id'] ?? '')),
                    $this->nullableInt($migration->vlan),
                    $this->nullableInt($migration->tr069_vlan),
                    true,
                ),
                'warning' => 'SN sudah terdaftar; ont add dilewati dan hanya service-port yang direncanakan bila approval lanjutan diberikan.',
            ];
        }
        $requestedId = $this->nullableInt($migration->new_onu_id)
            ?? ($hint ? $this->onuIdFromRef($hint) : null);
        $onuId = $this->nextOnuId($usedOnus, $ponPort);
        $warning = null;
        if ($requestedId !== null && in_array($requestedId, $this->occupiedOnuIds($usedOnus, $ponPort), true)) {
            $warning = "Hint ONT-ID {$requestedId} sudah dipakai; ONT-ID {$onuId} dipilih otomatis dari read-back PON {$ponPort}.";
        } elseif ($requestedId !== null && $requestedId !== $onuId) {
            $warning = "Hint ONT-ID {$requestedId} diabaikan; ONT-ID {$onuId} dipilih otomatis dari read-back PON {$ponPort}.";
        }

        $plan = $this->buildPlan(
            $serial,
            (string) $migration->pppoe_user,
            $ponPort,
            $onuId,
            (string) ($match['vendor_id'] ?? ''),
            $this->nullableInt($migration->vlan),
            $this->nullableInt($migration->tr069_vlan),
        );

        return [
            'placement' => [
                'source' => 'autofind',
                'pon_port' => $ponPort,
                'onu_id' => $onuId,
                'onu_ref' => "{$ponPort}:{$onuId}",
                'serial_number' => $serial,
                'vendor_id' => $match['vendor_id'] ?? null,
                'autofind_command' => $autofind['command'],
            ],
            'plan' => $plan,
            'warning' => $warning,
        ];
    }

    /**
     * @return array{found: ?bool, warning: ?string}
     */
    protected function verifyRouterSecret(Router $router, string $username): array
    {
        $username = $this->normalizeDescription($username);
        if ($username === '' || ! $this->mikrotik) {
            return ['found' => null, 'warning' => 'router_pppoe_secret_unavailable'];
        }

        try {
            $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
            $secret = $this->mikrotik->getPPPSecretWithPassword($username);
            $password = trim((string) ($secret['password'] ?? ''));
            $found = is_array($secret)
                && strtolower((string) ($secret['service'] ?? '')) === 'pppoe'
                && $password !== ''
                && preg_match('/^\*+$/', $password) !== 1;

            return ['found' => $found, 'warning' => $found ? null : 'router_pppoe_secret_missing'];
        } catch (\Throwable) {
            return ['found' => null, 'warning' => 'router_pppoe_secret_unverified'];
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    /**
     * Parse common MA5608T `display ont autofind all` output variants.
     *
     * @return list<array{pon_port: string, serial_number: string, vendor_id: ?string}>
     */
    public function parseAutofindOutput(string $output): array
    {
        $rows = [];
        $current = [];

        $flush = function () use (&$rows, &$current): void {
            $port = $this->normalizePonPort((string) ($current['pon_port'] ?? ''));
            $serial = $this->normalizeHuaweiSerial((string) ($current['serial_number'] ?? ''));
            if ($port && $serial) {
                $rows[] = [
                    'pon_port' => $port,
                    'serial_number' => $serial,
                    'vendor_id' => $current['vendor_id'] ?? null,
                ];
            }
            $current = [];
        };

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(?:F\/S\/P|PON\s+port)\s*[:：]\s*(\d+\/\d+\/\d+)/iu', $line, $match)) {
                if (($current['serial_number'] ?? '') !== '') {
                    $flush();
                }
                $current['pon_port'] = $match[1];

                continue;
            }

            if (preg_match('/^\s*(?:ONT\s+SN|SN|Serial(?:\s+number)?)\s*[:：]\s*([A-Za-z0-9][A-Za-z0-9-]{5,63})/iu', $line, $match)) {
                $current['serial_number'] = $match[1];

                continue;
            }

            if (preg_match('/^\s*(?:Vendor\s*ID|VendorID|Vendor)\s*[:：]\s*(.+?)\s*$/iu', $line, $match)) {
                $current['vendor_id'] = trim($match[1]);
            }
        }
        $flush();

        return array_values(array_unique($rows, SORT_REGULAR));
    }

    /**
     * Select the first free ID on one PON.
     * A full PON is blocked instead of wrapping or reusing an ID.
     *
     * @param  list<array<string, mixed>>  $onus
     */
    public function nextOnuId(array $onus, string $ponPort): int
    {
        $port = $this->normalizePonPort($ponPort);
        if (! $port) {
            throw new RuntimeException('PON Huawei tidak valid. Expected 0/0/port.');
        }

        $used = [];
        foreach ($onus as $onu) {
            if (($this->normalizePonPort((string) ($onu['pon_port'] ?? '')) ?: $this->portFromOnuRef((string) ($onu['onu_ref'] ?? ''))) !== $port) {
                continue;
            }
            $id = isset($onu['onu_id']) && is_numeric($onu['onu_id'])
                ? (int) $onu['onu_id']
                : $this->onuIdFromRef((string) ($onu['onu_ref'] ?? ''));
            if ($id !== null) {
                $used[$id] = true;
            }
        }

        for ($next = 0; $next <= self::MAX_ONU_ID; $next++) {
            if (! isset($used[$next])) {
                return $next;
            }
        }

        throw new RuntimeException("ONT-ID PON {$port} sudah penuh sampai ".self::MAX_ONU_ID.'.');
    }

    /**
     * Build a safe, deterministic Huawei command plan without writing a device.
     *
     * @return array<string, mixed>
     */
    public function buildPlan(
        string $serial,
        string $pppoe,
        string $ponPort,
        int $onuId,
        ?string $vendorId = null,
        ?int $internetVlan = null,
        ?int $tr069Vlan = null,
        bool $alreadyRegistered = false,
    ): array {
        $serial = $this->normalizeSerial($serial);
        $pppoe = $this->normalizeDescription($pppoe);
        $ponPort = $this->normalizePonPort($ponPort);

        if ($serial === '' || ! preg_match('/^[A-Z0-9-]{8,64}$/', $serial)) {
            throw new RuntimeException('SN baru Huawei tidak valid.');
        }
        if ($pppoe === '') {
            throw new RuntimeException('PPPoE wajib menjadi description ONT.');
        }
        if (! $ponPort) {
            throw new RuntimeException('PON Huawei tidak valid. Expected 0/0/port.');
        }
        if ($onuId < 0 || $onuId > self::MAX_ONU_ID) {
            throw new RuntimeException('ONT-ID Huawei harus berada pada rentang 0-127.');
        }

        $profile = $this->profileFor($ponPort, $serial, $vendorId);
        if ($internetVlan !== null && $internetVlan !== $profile['vlan']) {
            throw new RuntimeException("VLAN {$internetVlan} tidak cocok dengan line-profile {$profile['line_profile']} untuk {$ponPort}.");
        }
        if ($tr069Vlan !== null && $tr069Vlan !== $profile['tr069_vlan']) {
            throw new RuntimeException("VLAN TR-069 {$tr069Vlan} tidak cocok dengan profile Huawei yang dipilih.");
        }

        $vlan = $internetVlan ?? $profile['vlan'];
        $managementVlan = $tr069Vlan ?? $profile['tr069_vlan'];
        $ponIndex = (int) substr($ponPort, strrpos($ponPort, '/') + 1);
        $gponInterface = "0/0/{$ponIndex}";
        $ontRef = "{$ponPort}:{$onuId}";
        $coreCommands = [];

        $coreCommands[] = 'config';
        $coreCommands[] = 'interface gpon 0/0';

        if (! $alreadyRegistered) {
            $coreCommands[] = sprintf(
                'ont add %d %d sn-auth "%s" omci ont-lineprofile-id %d ont-srvprofile-id %d desc "%s"',
                $ponIndex,
                $onuId,
                $serial,
                $profile['line_profile'],
                self::SRV_PROFILE_ID,
                $pppoe,
            );
        }

        $tr069Command = sprintf(
            'ont tr069-server-config %d %d profile-id %d',
            $ponIndex,
            $onuId,
            self::TR069_SERVER_PROFILE_ID,
        );
        $coreCommands[] = 'quit';
        // Verify that `ont add` committed before sending any service-port
        // command. A Huawei command can return without a usable ONT state;
        // fail closed while the session is still read-only and unambiguous.
        $coreCommands[] = "display ont info by-sn {$serial}";
        $coreCommands[] = "display ont info 0 0 {$ponIndex} {$onuId}";
        $coreCommands[] = sprintf(
            'service-port vlan %d gpon %s ont %d gemport 1 multi-service user-vlan %d tag-transform translate inbound traffic-table name "%s" outbound traffic-table name "%s"',
            $vlan,
            $gponInterface,
            $onuId,
            $vlan,
            self::TRAFFIC_TABLE,
            self::TRAFFIC_TABLE,
        );
        $coreCommands[] = sprintf(
            'service-port vlan %d gpon %s ont %d gemport 2 multi-service user-vlan %d tag-transform translate',
            $managementVlan,
            $gponInterface,
            $onuId,
            $managementVlan,
        );
        $coreCommands[] = 'quit';
        $coreCommands[] = "display service-port port {$gponInterface} ont {$onuId}";
        $coreCommands[] = 'save';

        // TR-069 is optional for OLT-only activation. Keep it after the core
        // write/readback and in a fresh session: some MA5608T builds close the
        // SSH channel when this command is unsupported or malformed.
        $optionalCommands = [
            'config',
            'interface gpon 0/0',
            $tr069Command,
            'quit',
            'quit',
            'save',
        ];
        $commands = [...$coreCommands, ...$optionalCommands];
        $optionalCommandIndexes = array_map(
            static fn (int $index): int => count($coreCommands) + $index + 1,
            array_keys($optionalCommands),
        );

        return [
            'mode' => self::MODE,
            'serial_number' => $serial,
            'pppoe_user' => $pppoe,
            'pon_port' => $ponPort,
            'onu_id' => $onuId,
            'onu_ref' => $ontRef,
            'vendor_id' => $vendorId ? trim($vendorId) : null,
            'line_profile' => $profile['line_profile'],
            'srv_profile' => self::SRV_PROFILE_ID,
            'internet_vlan' => $vlan,
            'tr069_vlan' => $managementVlan,
            'traffic_table' => self::TRAFFIC_TABLE,
            'already_registered' => $alreadyRegistered,
            'commands' => $commands,
            'core_commands' => $coreCommands,
            'optional_commands' => $optionalCommands,
            'optional_command_indexes' => $optionalCommandIndexes,
            'command_hash' => hash('sha256', implode("\n", $commands)),
        ];
    }

    /**
     * Build a delete plan for an ONU (used when a dismantle recovery frees the
     * port/serial). Read-only: no device write happens until executePlan runs
     * under the shared OLT guard.
     *
     * @return array<string, mixed>
     */
    public function buildDeletePlan(Olt $olt, string $onuRef, ?string $serial = null): array
    {
        [$ponIndex, $onuId] = $this->splitOnuRef($onuRef);
        $ponIndex = (int) $ponIndex;
        $onuId = (int) $onuId;

        if ($onuId < 0 || $onuId > self::MAX_ONU_ID) {
            throw new RuntimeException('ONT-ID Huawei harus berada pada rentang 0-127.');
        }

        $coreCommands = [
            'config',
            'interface gpon 0/0',
            "ont delete {$ponIndex} {$onuId}",
            'quit',
        ];
        $optionalCommands = [
            'save',
            "display ont info 0 0 {$ponIndex} {$onuId}",
        ];
        $commands = [...$coreCommands, ...$optionalCommands];
        $optionalCommandIndexes = array_map(
            static fn (int $index): int => count($coreCommands) + $index + 1,
            array_keys($optionalCommands),
        );

        return [
            'mode' => self::MODE,
            'operation' => 'delete_onu',
            'serial_number' => $serial !== null ? $this->normalizeSerial($serial) : null,
            'onu_ref' => "0/0/{$ponIndex}:{$onuId}",
            'pon_index' => $ponIndex,
            'onu_id' => $onuId,
            'commands' => $commands,
            'core_commands' => $coreCommands,
            'optional_commands' => $optionalCommands,
            'optional_command_indexes' => $optionalCommandIndexes,
            'command_hash' => hash('sha256', implode("\n", $commands)),
        ];
    }

    /**
     * Delete an ONU after dismantle. Requires the shared OLT guard (dry-run by
     * default, kill switch, and an approval bundle). Never touches MikroTik,
     * customer, or PSB state.
     *
     * @param  array{approval?: string, approved_at?: int|string, signature?: string}  $approval
     * @return array<string, mixed>
     */
    public function deleteOnu(Olt $olt, string $onuRef, ?string $serial = null, array $approval = []): array
    {
        $plan = $this->buildDeletePlan($olt, $onuRef, $serial);
        $approval = $approval !== [] ? $approval : app(OltProvisioningGuard::class)
            ->issueInternalApproval($olt, 'delete_onu', $plan['onu_ref']);

        $result = $this->executePlan($olt, $plan, $approval);

        $verifiedAbsent = false;
        if (($result['device_write'] ?? false) === true) {
            $outputs = (array) ($result['outputs'] ?? []);
            $last = $outputs === [] ? '' : (string) ($outputs[array_key_last($outputs)]['output'] ?? '');
            $normalized = strtolower($last);
            $verifiedAbsent = str_contains($normalized, 'does not exist')
                || str_contains($normalized, 'not exist')
                || str_contains($normalized, 'failure');
        }

        return $result + [
            'success' => (bool) ($result['device_write'] ?? false),
            'verified_absent' => $verifiedAbsent,
            'onu_ref' => $plan['onu_ref'],
        ];
    }

    /**
     * Execute an approved plan. Default OLT provisioning config is dry-run, so
     * this method remains non-writing until the shared guard is deliberately
     * opened outside audit/test mode.
     *
     * @param  array{approval?: string, approved_at?: int|string, signature?: string}  $approval
     * @return array<string, mixed>
     */
    public function executePlan(Olt $olt, array $plan, array $approval = []): array
    {
        $commands = $plan['commands'] ?? null;
        if (! is_array($commands) || $commands === []) {
            throw new RuntimeException('Huawei command plan kosong.');
        }
        $commands = array_values(array_map('strval', $commands));

        $guard = app(OltProvisioningGuard::class)->provision($olt, [
            'commands' => $commands,
            ...$approval,
        ]);
        if (($guard['dry_run'] ?? true) === true) {
            return $guard + ['verified' => false, 'device_write' => false];
        }

        $outputs = [];
        $phases = $this->executionPhases($plan, $commands);
        $requiredServiceVlan = isset($plan['internet_vlan']) && is_numeric($plan['internet_vlan'])
            ? (int) $plan['internet_vlan']
            : null;
        $this->executeCommandPhase(
            $olt,
            $phases['core_commands'],
            $phases['core_indexes'],
            $outputs,
            $requiredServiceVlan,
        );

        $optionalFailure = null;
        try {
            $this->executeCommandPhase($olt, $phases['optional_commands'], $phases['optional_indexes'], $outputs);
        } catch (\Throwable $exception) {
            // Core service-port configuration was already read back and saved.
            // Never retry the optional command automatically because a closed
            // channel leaves the TR-069 binding itself ambiguous.
            $optionalFailure = $this->safeOptionalFailure($exception);
        }

        return [
            'dry_run' => false,
            'olt_id' => (int) $olt->getKey(),
            'verified' => true,
            'device_write' => true,
            'core_verified' => true,
            'tr069_pending' => $optionalFailure !== null,
            'genieacs_pending' => $optionalFailure !== null,
            'optional_failure' => $optionalFailure,
            'outputs' => $outputs,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $commands
     * @return array{core_commands: list<string>, optional_commands: list<string>, core_indexes: list<int>, optional_indexes: list<int>}
     */
    protected function executionPhases(array $plan, array $commands): array
    {
        $coreCommands = $plan['core_commands'] ?? null;
        $optionalCommands = $plan['optional_commands'] ?? null;
        $optionalIndexes = $plan['optional_command_indexes'] ?? null;

        if (! is_array($coreCommands) || ! is_array($optionalCommands) || ! is_array($optionalIndexes)) {
            throw new RuntimeException('Huawei command plan belum memiliki fase core/opsional; ulangi dry-run.');
        }

        $coreCommands = array_values(array_map('strval', $coreCommands));
        $optionalCommands = array_values(array_map('strval', $optionalCommands));
        $optionalIndexes = array_values(array_map('intval', $optionalIndexes));
        if ($optionalCommands === [] || count($optionalCommands) !== count($optionalIndexes)
            || [...$coreCommands, ...$optionalCommands] !== $commands) {
            throw new RuntimeException('Huawei command plan memiliki fase yang tidak konsisten; ulangi dry-run.');
        }

        return [
            'core_commands' => $coreCommands,
            'optional_commands' => $optionalCommands,
            'core_indexes' => range(1, count($coreCommands)),
            'optional_indexes' => $optionalIndexes,
        ];
    }

    /**
     * @param  list<string>  $commands
     * @param  list<int>  $commandIndexes
     * @param  list<array{command: string, output: string}>  $outputs
     */
    protected function executeCommandPhase(
        Olt $olt,
        array $commands,
        array $commandIndexes,
        array &$outputs,
        ?int $requiredServiceVlan = null,
    ): void {
        $ssh = null;
        $commandIndex = null;
        $command = null;
        $context = null;

        try {
            foreach ($commands as $offset => $rawCommand) {
                $command = (string) $rawCommand;
                $commandIndex = $commandIndexes[$offset] ?? ($offset + 1);

                // `quit` is only a local phase boundary. MA5600 firmware can
                // reject it after an ONT command; a fresh SSH session starts
                // at the root prompt and avoids sending housekeeping commands
                // that are not part of the device write contract.
                if ($this->isSessionBoundaryCommand($command)) {
                    $outputs[] = ['command' => $command, 'output' => '[skipped: fresh SSH session boundary]'];
                    $this->disconnectSafely($ssh);
                    $ssh = null;
                    $context = null;

                    continue;
                }

                if (! $ssh) {
                    try {
                        $ssh = $this->connect($olt);
                    } catch (HuaweiOltCommandException $exception) {
                        throw $exception;
                    } catch (HuaweiOltSessionBusyException $exception) {
                        // Busy before ANY command of this plan was sent: let the
                        // caller treat it as "OLT busy, nothing written".
                        if (! $this->anyCommandSent($outputs)) {
                            throw $exception;
                        }
                        // Later phase: commands already went out, so this stays a
                        // transport failure that needs manual reconciliation.
                        throw new HuaweiOltCommandException(
                            'transport',
                            'connect',
                            $commandIndex,
                            $this->safeCommandLabel($command),
                            class_basename($exception),
                            'Huawei SSH connection gagal secara aman.',
                            $exception,
                        );
                    } catch (\Throwable $exception) {
                        throw new HuaweiOltCommandException(
                            'transport',
                            'connect',
                            $commandIndex,
                            $this->safeCommandLabel($command),
                            class_basename($exception),
                            'Huawei SSH connection gagal secara aman.',
                            $exception,
                        );
                    }
                    $context = 'root';
                }

                if ($this->requiresConfigContext($command) && $context !== 'config') {
                    $contextCommand = 'config';
                    $contextOutput = $this->runInteractive($ssh, $contextCommand, $commandIndex);
                    $outputs[] = ['command' => $contextCommand, 'output' => $this->trimRaw($contextOutput)];
                    if ($this->isCommandFailure($contextOutput)) {
                        throw new HuaweiOltCommandException(
                            'command',
                            'command',
                            $commandIndex,
                            $contextCommand,
                            'command_rejected',
                            'Huawei menolak command '.$contextCommand.'.',
                        );
                    }
                    $context = 'config';
                }

                $isOntInfo = $this->isOntInfoCommand($command);
                $isOntBySn = $this->isOntBySnCommand($command);
                $isServicePortReadback = $this->isServicePortReadbackCommand($command);
                $output = $isOntBySn
                    ? $this->runOntBySnReadbackWithRetry($ssh, $command, $commandIndex, $outputs)
                    : ($isOntInfo
                        ? $this->runOntReadbackWithPolling($ssh, $command, $commandIndex, $outputs)
                        : $this->runInteractive($ssh, $command, $commandIndex));
                if (! $isOntInfo) {
                    $outputs[] = ['command' => $command, 'output' => $this->trimRaw($output)];
                }
                $context = $this->contextAfterCommand($command, $context);
                $ontIsMissing = $isOntInfo && $this->isMissingOntOutput($output);
                $ontIsNotReady = $isOntInfo
                    && ! $isOntBySn
                    && ! $ontIsMissing
                    && ! $this->isOntReadbackReady($output);
                $servicePortIsNotReady = $isServicePortReadback
                    && ! $this->isServicePortReadbackReady($output, $requiredServiceVlan);
                if (($this->isCommandFailure($output) || $ontIsMissing || $ontIsNotReady) && ! $this->isIdempotentAlreadyConfigured($output)) {
                    $causeClass = $ontIsMissing ? 'onu_not_present' : ($ontIsNotReady ? 'onu_not_ready' : 'command_rejected');
                    $message = $ontIsMissing
                        ? 'Huawei tidak menemukan ONT pada read-back; service-port tidak dikirim.'
                        : ($ontIsNotReady
                            ? 'Huawei ONT belum siap (Run state online dan Config state normal wajib terbukti); service-port tidak dikirim.'
                            : 'Huawei menolak command '.$this->safeCommandLabel($command).'.');
                    throw new HuaweiOltCommandException(
                        'command',
                        'command',
                        $commandIndex,
                        $this->safeCommandLabel($command),
                        $causeClass,
                        $message,
                    );
                }
                if ($servicePortIsNotReady) {
                    throw new HuaweiOltCommandException(
                        'command',
                        'command',
                        $commandIndex,
                        $this->safeCommandLabel($command),
                        'service_port_not_up',
                        'Huawei service-port belum berstatus up; save tidak dikirim.',
                    );
                }
            }
        } catch (HuaweiOltCommandException $exception) {
            throw $exception;
        } catch (HuaweiOltSessionBusyException $exception) {
            // Only the first connect of the plan rethrows it raw (see above).
            throw $exception;
        } catch (\Throwable $exception) {
            throw new HuaweiOltCommandException(
                'transport',
                'command',
                $commandIndex,
                $command ? $this->safeCommandLabel($command) : null,
                class_basename($exception),
                'Huawei SSH phase gagal secara aman.',
                $exception,
            );
        } finally {
            $this->disconnectSafely($ssh);
        }
    }

    /**
     * True once a real command was sent in this plan (session-boundary markers
     * are local only and do not count).
     *
     * @param  list<array{command: string, output: string}>  $outputs
     */
    private function anyCommandSent(array $outputs): bool
    {
        foreach ($outputs as $entry) {
            if (! str_starts_with((string) ($entry['output'] ?? ''), '[skipped')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Poll only the ONT read-back. A positive, ready read-back is required
     * before the caller can continue to service-port commands. A command
     * rejection remains immediate; only a missing or not-ready ONT is
     * transient and eligible for the bounded wait.
     *
     * @param  list<array{command: string, output: string}>  $outputs
     */
    protected function runOntReadbackWithPolling(
        SSH2 $ssh,
        string $command,
        int $commandIndex,
        array &$outputs,
    ): string {
        $output = '';

        for ($attempt = 1; $attempt <= self::ONT_READBACK_MAX_ATTEMPTS; $attempt++) {
            $output = $this->runInteractive($ssh, $command, $commandIndex);
            $outputs[] = [
                'command' => $command,
                'output' => $this->trimRaw($output),
            ];

            $missing = $this->isMissingOntOutput($output);
            $hasReadbackState = $this->hasOntReadbackState($output);
            $rejected = $this->isCommandFailure($output) && ! $missing && ! $hasReadbackState;
            $ready = ! $missing && ! $rejected && $this->isOntReadbackReady($output);

            if ($ready || $rejected) {
                return $output;
            }

            if ($attempt < self::ONT_READBACK_MAX_ATTEMPTS) {
                $this->waitBeforeOntReadbackRetry($attempt);
            }
        }

        return $output;
    }

    /**
     * Huawei can briefly report a newly added ONT as missing by serial. Retry
     * that exact read-back once after an autofind refresh, then let the normal
     * command guard fail closed. This is deliberately bounded and does not
     * issue a second write.
     *
     * @param  list<array{command: string, output: string}>  $outputs
     */
    protected function runOntBySnReadbackWithRetry(
        SSH2 $ssh,
        string $command,
        int $commandIndex,
        array &$outputs,
    ): string {
        $output = '';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $output = $this->runInteractive($ssh, $command, $commandIndex);
            $outputs[] = [
                'command' => $command,
                'output' => $this->trimRaw($output),
            ];

            if (! $this->isMissingOntOutput($output) || $attempt === 2) {
                return $output;
            }

            $autofindCommand = 'display ont autofind all';
            try {
                $autofindOutput = $this->runInteractive($ssh, $autofindCommand, $commandIndex);
                $outputs[] = [
                    'command' => $autofindCommand,
                    'output' => $this->trimRaw($autofindOutput),
                ];
            } catch (\Throwable $exception) {
                $outputs[] = [
                    'command' => $autofindCommand,
                    'output' => '[autofind refresh failed: '.class_basename($exception).']',
                ];
            }
            $this->waitBeforeOntReadbackRetry($attempt);
        }

        return $output;
    }

    protected function waitBeforeOntReadbackRetry(int $attempt): void
    {
        usleep(self::ONT_READBACK_RETRY_DELAY_MICROSECONDS);
    }

    protected function hasOntReadbackState(string $output): bool
    {
        return preg_match('/\b(?:ONT-ID|Run\s+state|Config\s+state|Match\s+state)\s*:/i', $output) === 1;
    }

    protected function requiresConfigContext(string $command): bool
    {
        return preg_match('/^service-port\s+/i', trim($command)) === 1;
    }

    protected function isSessionBoundaryCommand(string $command): bool
    {
        return strtolower(trim($command)) === 'quit';
    }

    protected function contextAfterCommand(string $command, ?string $context): ?string
    {
        $command = strtolower(trim($command));

        return match (true) {
            $command === 'config' => 'config',
            str_starts_with($command, 'interface gpon ') => 'gpon',
            str_starts_with($command, 'service-port ') => 'config',
            $command === 'save' => 'root',
            default => $context,
        };
    }

    protected function isOntInfoCommand(string $command): bool
    {
        return preg_match('/^display\s+ont\s+info\s+/i', trim($command)) === 1;
    }

    protected function isOntBySnCommand(string $command): bool
    {
        return preg_match('/^display\s+ont\s+info\s+by-sn\s+/i', trim($command)) === 1;
    }

    protected function isServicePortReadbackCommand(string $command): bool
    {
        return preg_match('/^display\s+service-port\s+port\s+\d+\/\d+\/\d+\s+ont\s+\d+\s*$/i', trim($command)) === 1;
    }

    /**
     * The CLI command itself can be accepted while the service-port remains
     * down. Require a scoped VLAN row with an explicit `up` state before the
     * plan is reported verified and before `save` is sent.
     */
    protected function isServicePortReadbackReady(string $output, ?int $requiredVlan = null): bool
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || preg_match('/\bvlan\s+(\d+)\b/i', $line, $vlanMatch) !== 1) {
                continue;
            }
            if ($requiredVlan !== null && (int) $vlanMatch[1] !== $requiredVlan) {
                continue;
            }

            if (preg_match('/\bup\b\s*$/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Huawei reports an idempotent no-op with several word orders. A repeat
     * `ont add`/`service-port` for an existing object is safe, not a failure:
     *   - "already exists" / "already exist" / "already existed"
     *   - "has existed already" / "existed already"
     *   - "already configured" / "already in use"
     * The MA5608T returns `Failure: Service virtual port has existed already`,
     * which the previous `already (exists|configured)` pattern missed and
     * reported as command_rejected.
     */
    protected function isIdempotentAlreadyConfigured(string $output): bool
    {
        return preg_match(
            '/already\s+exist|existed\s+already|already\s+(?:configured|in\s+use)/i',
            $output,
        ) === 1;
    }

    protected function isMissingOntOutput(string $output): bool
    {
        return preg_match('/\b(?:ont|onu)\b.*\b(?:does not exist|not found)\b|\b(?:does not exist|not found)\b.*\b(?:ont|onu)\b/i', $output) === 1;
    }

    protected function isOntReadbackReady(string $output): bool
    {
        if (preg_match('/\bRun\s+state\s*[:=]\s*([^\r\n]+)/i', $output, $runState) !== 1
            || strtolower(trim($runState[1])) !== 'online') {
            return false;
        }

        if (preg_match('/\bConfig\s+state\s*[:=]\s*([^\r\n]+)/i', $output, $configState) !== 1
            || strtolower(trim($configState[1])) !== 'normal') {
            return false;
        }

        if (preg_match('/\bMatch\s+state\s*[:=]\s*([^\r\n]+)/i', $output, $matchState) === 1
            && strtolower(trim($matchState[1])) !== 'match') {
            return false;
        }

        return true;
    }

    /** @return array{failure_type: string, failure_stage: string, command_index: ?int, command_label: ?string, cause_class: string} */
    protected function safeOptionalFailure(\Throwable $exception): array
    {
        if ($exception instanceof HuaweiOltCommandException) {
            return [
                'failure_type' => $exception->failureType,
                'failure_stage' => $exception->stage,
                'command_index' => $exception->commandIndex,
                'command_label' => $exception->commandLabel,
                'cause_class' => $exception->causeClass,
            ];
        }

        return [
            'failure_type' => 'unknown',
            'failure_stage' => 'optional_tr069',
            'command_index' => null,
            'command_label' => null,
            'cause_class' => class_basename($exception),
        ];
    }

    /**
     * @return array{vlan: int, line_profile: int, tr069_vlan: int}
     */
    public function profileFor(string $ponPort, string $serial = '', ?string $vendorId = null): array
    {
        $port = $this->normalizePonPort($ponPort);
        if (! $port) {
            throw new RuntimeException('PON Huawei tidak valid.');
        }

        $isNokia = str_starts_with($this->normalizeSerial($serial), 'ALCL')
            || str_contains(strtoupper((string) $vendorId), 'ALCL')
            || str_contains(strtoupper((string) $vendorId), 'NOKIA');
        if ($isNokia) {
            return ['vlan' => 101, 'line_profile' => 11, 'tr069_vlan' => 81];
        }

        $index = (int) substr($port, strrpos($port, '/') + 1);
        if (! isset(self::STANDARD_PORT_PROFILES[$index])) {
            throw new RuntimeException("Mapping line-profile Huawei untuk PON {$port} belum tersedia.");
        }

        return self::STANDARD_PORT_PROFILES[$index];
    }

    protected function runInteractive(SSH2 $ssh, string $command, ?int $commandIndex = null): string
    {
        // MA5600 CLI treats CR as the terminal Enter key. A bare LF opens the
        // command help prompt and leaves the command unexecuted.
        $this->writeSsh($ssh, $command."\r", 'command', $command, $commandIndex);
        $output = '';
        $commandEchoSeen = false;
        $paginationOffset = null;
        $confirmationOffset = null;
        $optionPrompt = null;

        for ($packet = 0; $packet < self::MAX_INTERACTIVE_PACKETS; $packet++) {
            $chunk = $this->readSsh($ssh, 'command', $command, $commandIndex);
            if ($chunk === false) {
                throw $this->timeoutFailure('command', $command, $commandIndex);
            }
            $output .= $chunk;
            $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;
            $commandEchoSeen = $commandEchoSeen || $this->commandEchoSeen($clean, $command);

            if (preg_match_all('/----\s*More\b.*?----/i', $clean, $paginationMatches, PREG_OFFSET_CAPTURE)) {
                $lastPagination = end($paginationMatches[0]);
                $currentPaginationOffset = (int) $lastPagination[1];
                if ($paginationOffset !== $currentPaginationOffset) {
                    $paginationOffset = $currentPaginationOffset;
                    $this->writeSsh($ssh, ' ', 'pagination', $command, $commandIndex);

                    continue;
                }
            }

            if (preg_match_all('/(?:are you sure|save.*\(y\/n\)|\[y\/n\])/i', $clean, $confirmationMatches, PREG_OFFSET_CAPTURE)) {
                $lastConfirmation = end($confirmationMatches[0]);
                $currentConfirmationOffset = (int) ($lastConfirmation[1] ?? -1);
                if ($confirmationOffset !== $currentConfirmationOffset) {
                    $confirmationOffset = $currentConfirmationOffset;
                    $this->writeSsh($ssh, "y\r", 'confirmation', $command, $commandIndex);

                    continue;
                }
            }

            // MA5600 commands with optional arguments (notably `ont add` and
            // `display service-port`) pause at `{ <cr>|... }:`. The prompt
            // can arrive split as `{ <cr>`; do not mistake its `>` for the
            // shell prompt. Press Enter once per prompt shape.
            $currentOptionPrompt = $this->huaweiOptionPromptAtEnd($clean);
            if ($currentOptionPrompt !== null && $currentOptionPrompt !== $optionPrompt) {
                $optionPrompt = $currentOptionPrompt;
                $this->writeSsh($ssh, "\r", 'option_prompt', $command, $commandIndex);

                continue;
            }
            if ($commandEchoSeen && $this->isHuaweiCliPromptAtEnd($clean) && ! preg_match('/(?:are you sure|\(y\/n\)|\[y\/n\])\s*$/i', $clean)) {
                return $output;
            }
        }

        throw $this->timeoutFailure('command', $command, $commandIndex);
    }

    protected function resolveOltForMigration(CustomerMigration $migration): Olt
    {
        $candidates = Olt::query()
            ->where(function ($query) {
                $query->where('vendor', 'like', '%huawei%')
                    ->orWhere('vendor', 'like', '%ma5608%');
            })
            ->get();

        if ($migration->new_olt_id) {
            $olt = $candidates->first(fn (Olt $row): bool => (int) $row->id === (int) $migration->new_olt_id);
            if ($olt) {
                return $olt;
            }
        }

        $parser = new CustomerMigrationMessageParser;
        $parsed = $parser->parse((string) $migration->raw_text);
        $alias = trim((string) ($parsed['olt_field'] ?? ''));
        $normalized = $this->normalizeLookup($alias);
        $matches = $normalized === ''
            ? collect()
            : $candidates->filter(fn (Olt $row): bool => in_array($normalized, [
                $this->normalizeLookup((string) $row->name),
                $this->normalizeLookup((string) $row->code),
            ], true));

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($migration->router_id) {
            $routerMatches = $candidates->where('router_id', (int) $migration->router_id);
            if ($routerMatches->count() === 1) {
                return $routerMatches->first();
            }
        }

        // A bare `PPPoE + SN` message is safe only when the database has one
        // Huawei target. Multiple OLTs remain an explicit-input requirement.
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        throw new RuntimeException($matches->count() > 1
            ? 'Alias OLT Huawei cocok ke lebih dari satu OLT.'
            : 'OLT Huawei target belum ditemukan. Isi OLT dengan alias yang tepat.');
    }

    protected function resolveRouterForMigration(CustomerMigration $migration): Router
    {
        $router = $migration->router_id
            ? Router::query()->whereKey((int) $migration->router_id)->where('is_active', true)->first()
            : null;
        if (! $router) {
            $routerIds = Olt::query()
                ->where(function ($query) {
                    $query->where('vendor', 'like', '%huawei%')
                        ->orWhere('vendor', 'like', '%ma5608%');
                })
                ->whereNotNull('router_id')
                ->pluck('router_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->filter()
                ->unique()
                ->values();
            if ($routerIds->count() === 1) {
                $router = Router::query()
                    ->whereKey((int) $routerIds->first())
                    ->where('is_active', true)
                    ->first();
            }
        }
        if (! $router) {
            throw new RuntimeException($migration->router_id
                ? 'Router target tidak aktif atau tidak ditemukan.'
                : 'Router target tidak dapat diinfer dengan aman; isi Router atau pastikan hanya satu router Huawei aktif.');
        }

        return $router;
    }

    /**
     * Resolve the SOURCE router for a cross-router Huawei migration
     * (e.g. Bumiayu -> Gadang). Explicit `Router Lama` / old_router_id wins;
     * returns null for same-router migrations.
     */
    protected function resolveSourceRouterForMigration(CustomerMigration $migration, Router $target): ?Router
    {
        $router = null;

        if ($migration->old_router_id) {
            $router = Router::query()->whereKey((int) $migration->old_router_id)->where('is_active', true)->first();
        }

        if (! $router) {
            $parser = new CustomerMigrationMessageParser;
            $parsed = $parser->parse((string) $migration->raw_text);
            $alias = trim((string) ($parsed['old_router_field'] ?? ''));
            if ($alias !== '') {
                $needle = $this->normalizeLookup($alias);
                $router = Router::query()->where('is_active', true)->get()
                    ->first(fn (Router $row): bool => $needle !== ''
                        && (in_array($needle, [$this->normalizeLookup((string) $row->name)], true)
                            || str_contains($this->normalizeLookup((string) $row->name), $needle)));
            }
        }

        if (! $router || (int) $router->id === (int) $target->id) {
            return null;
        }

        return $router;
    }

    public function normalizePonPort(string $value): ?string
    {
        $value = strtolower(trim(str_replace('gpon-olt_', '', $value)));

        return preg_match('/^0\/0\/(\d{1,2})$/', $value, $match) && (int) $match[1] <= 15
            ? $value
            : null;
    }

    protected function normalizeSerial(string $value): string
    {
        return strtoupper(trim($value));
    }

    protected function serialKey(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
    }

    protected function normalizeDescription(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F\x7F"]+/', '', $value) ?? '');
        if ($value === '' || mb_strlen($value) > 80 || ! preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
            return '';
        }

        return $value;
    }

    protected function normalizeLookup(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]+/i', '', trim($value)) ?? '');
    }

    protected function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected function requestedOnuHint(CustomerMigration $migration): ?string
    {
        $value = $migration->new_onu_ref
            ?: data_get($migration->meta, 'huawei_olt_only.requested_onu_ref_hint');
        $normalized = $this->normalizeOnuRef((string) $value);

        return $normalized ?: null;
    }

    /**
     * @param  list<array<string, mixed>>  $onus
     * @return list<int>
     */
    protected function occupiedOnuIds(array $onus, string $ponPort): array
    {
        $port = $this->normalizePonPort($ponPort);
        if (! $port) {
            return [];
        }

        $ids = [];
        foreach ($onus as $onu) {
            $onuPort = $this->normalizePonPort((string) ($onu['pon_port'] ?? ''))
                ?: $this->portFromOnuRef((string) ($onu['onu_ref'] ?? ''));
            if ($onuPort !== $port) {
                continue;
            }
            $id = isset($onu['onu_id']) && is_numeric($onu['onu_id'])
                ? (int) $onu['onu_id']
                : $this->onuIdFromRef((string) ($onu['onu_ref'] ?? ''));
            if ($id !== null && $id >= 0 && $id <= self::MAX_ONU_ID) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    protected function preflightFailureCode(string $message): string
    {
        return match (true) {
            str_contains($message, 'SN baru belum muncul') => 'new_sn_not_seen_by_autofind',
            str_contains($message, 'sudah terdaftar') => 'new_sn_already_registered',
            str_contains($message, 'ONT-ID') => 'onu_id_conflict',
            str_contains($message, 'PON') => 'pon_not_valid_or_mismatch',
            str_contains($message, 'Router target') => 'target_router_unavailable',
            str_contains($message, 'OLT Huawei') => 'target_olt_unavailable',
            str_contains($message, 'SN baru') => 'new_sn_invalid',
            default => 'huawei_olt_preflight_failed',
        };
    }

    protected function isIncompleteOntInfoOutput(string $output): bool
    {
        return preg_match('/\{\s*all\s*<K>\s*\}/iu', $output) === 1;
    }

    protected function normalizeOnuRef(string $value): ?string
    {
        $ref = strtolower(trim($value));
        $ref = preg_replace('/^gpon-onu_/i', '', $ref) ?? $ref;
        if (preg_match('/^(0\/0\/\d+):(\d+)$/', $ref, $match) !== 1) {
            return null;
        }

        $port = $this->normalizePonPort($match[1]);
        $id = (int) $match[2];

        return $port !== null && $id >= 0 && $id <= self::MAX_ONU_ID
            ? "{$port}:{$id}"
            : null;
    }

    protected function portFromOnuRef(string $value): ?string
    {
        return preg_match('/^(0\/0\/\d+):\d+$/', strtolower(trim($value)), $match) === 1 ? $match[1] : null;
    }

    protected function onuIdFromRef(string $value): ?int
    {
        return preg_match('/^0\/0\/\d+:(\d+)$/', strtolower(trim($value)), $match) === 1 ? (int) $match[1] : null;
    }

    protected function safeCommandLabel(string $command): string
    {
        $command = preg_replace('/sn-auth\s+"[^"]+"/i', 'sn-auth "[redacted]"', $command) ?? $command;

        return mb_substr($command, 0, 120);
    }
}
