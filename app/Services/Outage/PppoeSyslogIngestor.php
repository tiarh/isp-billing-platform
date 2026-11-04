<?php

namespace App\Services\Outage;

use App\Models\Customer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RCA-2: turns parsed RouterOS PPPoE syslog lines into pppoe_session_events rows.
 *
 * Built to survive a log storm: a bounded in-memory buffer (overload => the line is dropped and counted, never
 * queued without limit), batch inserts, only senders listed in pppoe_syslog_sources are accepted, and every
 * ignored / unknown / dropped line is counted. Writes only pppoe_session_events (no device, no WA, no tickets).
 */
class PppoeSyslogIngestor
{
    public const DEFAULT_MAX_BUFFER = 5000;

    /** Same router + user + event inside this window is one event (connected + "logged in" lines). */
    public const MERGE_SECONDS = 5;

    /** A "connection established from MAC" line only labels an event that follows within this many seconds. */
    public const MAC_CORRELATE_SECONDS = 5;

    /** @var array<string,int> */
    private array $stats = ['received' => 0, 'accepted' => 0, 'stored' => 0, 'dropped_overload' => 0, 'unknown_source' => 0, 'unknown_format' => 0, 'ignored' => 0, 'disabled' => 0, 'insert_failed' => 0];

    /** @var list<array<string,mixed>> */
    private array $buffer = [];

    /** @var array<string,int> merge key => buffer index */
    private array $index = [];

    /** @var array<int,array{mac:string,at:int}> router id => newest "established from MAC" */
    private array $lastMac = [];

    /** @param array<string,int> $sources sender ip => router id */
    public function __construct(private array $sources = [], private readonly int $maxBuffer = self::DEFAULT_MAX_BUFFER) {}

    /** @param array<string,int> $sources */
    public function setSources(array $sources): void
    {
        $this->sources = $sources;
    }

    /** @return array<string,int> */
    public function stats(): array
    {
        return $this->stats + ['buffered' => count($this->buffer)];
    }

    public function bufferSize(): int
    {
        return count($this->buffer);
    }

    public function noteDisabled(): void
    {
        $this->stats['received']++;
        $this->stats['disabled']++;
    }

    /**
     * Parse one datagram and buffer the event. Returns the parser event name (or a drop reason) for tests.
     */
    public function accept(string $datagram, string $senderIp, ?CarbonInterface $at = null): string
    {
        $this->stats['received']++;
        $routerId = $this->sources[$senderIp] ?? null;
        if ($routerId === null) {
            $this->stats['unknown_source']++;

            return 'unknown_source';
        }
        $at = $at ? Carbon::instance($at) : now();
        $p = RouterOsPppoeLogParser::parse($datagram);

        switch ($p['event']) {
            case 'ignored':
                $this->stats['ignored']++;

                return 'ignored';
            case 'unknown':
                $this->stats['unknown_format']++;

                return 'unknown_format';
            case 'mac_seen':
                $this->lastMac[$routerId] = ['mac' => (string) $p['mac'], 'at' => $at->getTimestamp()];

                return 'mac_seen';
            case 'other':
                $this->stats['ignored']++;

                return 'other';
        }
        if ($p['user'] === null || $p['user'] === '') {
            $this->stats['unknown_format']++;

            return 'unknown_format';
        }

        $mac = $p['mac'];
        if ($mac === null && in_array($p['event'], ['auth_failed', 'stuck'], true)) {
            $seen = $this->lastMac[$routerId] ?? null;
            if ($seen !== null && ($at->getTimestamp() - $seen['at']) >= 0 && ($at->getTimestamp() - $seen['at']) <= self::MAC_CORRELATE_SECONDS) {
                $mac = $seen['mac'];
            }
        }

        $key = $routerId.'|'.strtolower($p['user']).'|'.$p['event'];
        $existing = $this->index[$key] ?? null;
        if ($existing !== null && abs($at->getTimestamp() - $this->buffer[$existing]['ts']) <= self::MERGE_SECONDS) {
            // the same event reported by a second topic line: keep the first, fill in what it lacked
            $this->buffer[$existing]['reason'] ??= $p['reason'];
            $this->buffer[$existing]['caller_mac'] ??= $mac;
            $this->stats['accepted']++;

            return $p['event'];
        }

        if (count($this->buffer) >= $this->maxBuffer) {
            $this->stats['dropped_overload']++;

            return 'dropped_overload';
        }

        $this->index[$key] = count($this->buffer);
        $this->buffer[] = [
            'router_id' => $routerId,
            'pppoe_user' => $p['user'],
            'event' => $p['event'],
            'reason' => $p['reason'],
            'caller_mac' => $mac,
            'at' => $at->copy()->utc()->format('Y-m-d H:i:s'),
            'ts' => $at->getTimestamp(),
        ];
        $this->stats['accepted']++;

        return $p['event'];
    }

    /**
     * Insert the buffered events (one customer lookup, chunked inserts). The buffer is always emptied: a failing
     * insert is counted and logged (class only), never retried forever.
     *
     * @return int rows stored
     */
    public function flush(): int
    {
        if ($this->buffer === []) {
            return 0;
        }
        $batch = $this->buffer;
        $this->buffer = [];
        $this->index = [];

        try {
            $users = array_values(array_unique(array_map(fn ($e) => strtolower($e['pppoe_user']), $batch)));
            $map = $this->customersByUser($users);
            $rows = [];
            foreach ($batch as $e) {
                $rows[] = [
                    'router_id' => $e['router_id'],
                    'pppoe_user' => $e['pppoe_user'],
                    'customer_id' => $map[strtolower($e['pppoe_user'])] ?? null,
                    'event' => $e['event'],
                    'reason' => $e['reason'],
                    'caller_mac' => $e['caller_mac'],
                    'at' => $e['at'],
                    'source' => 'syslog',
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('pppoe_session_events')->insert($chunk);
            }
            $this->stats['stored'] += count($rows);

            return count($rows);
        } catch (\Throwable $e) {
            $this->stats['insert_failed'] += count($batch);
            try {
                Log::warning('[Outage] pppoe syslog insert failed', ['exception_class' => $e::class, 'rows' => count($batch)]);
            } catch (\Throwable) {
            }

            return 0;
        }
    }

    /**
     * pppoe user => customer id, only unambiguous matches (a username used by two customers is left unmapped).
     *
     * @param  list<string>  $lowerUsers
     * @return array<string,int>
     */
    private function customersByUser(array $lowerUsers): array
    {
        $seen = [];
        foreach (array_chunk($lowerUsers, 400) as $chunk) {
            foreach (Customer::query()->whereIn('pppoe_user', $chunk)->get(['id', 'pppoe_user']) as $c) {
                $seen[strtolower((string) $c->pppoe_user)][] = (int) $c->id;
            }
        }
        $out = [];
        foreach ($seen as $user => $ids) {
            if (count($ids) === 1) {
                $out[$user] = $ids[0];
            }
        }

        return $out;
    }
}
