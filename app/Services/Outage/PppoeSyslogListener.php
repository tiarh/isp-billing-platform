<?php

namespace App\Services\Outage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * RCA-2: the UDP receive loop of `pppoe-syslog:listen`. One process, one socket, no threads.
 *
 * Bounded by design: at most MAX_READ_PER_TICK datagrams per tick, a bounded ingestor buffer (overload drops and
 * counts), a flush every FLUSH_SECONDS or when a batch is full. While the setting pppoe_syslog_enabled is 0 every
 * datagram is read and discarded (counted), so the kernel buffer never fills and nothing is stored.
 */
class PppoeSyslogListener
{
    public const MAX_READ_PER_TICK = 500;

    public const FLUSH_SECONDS = 2;

    public const BATCH_SIZE = 200;

    public const SETTINGS_REFRESH_SECONDS = 30;

    public const STATS_CACHE_KEY = 'pppoe-syslog:stats';

    private bool $stop = false;

    public function __construct(private readonly PppoeSyslogIngestor $ingestor) {}

    /**
     * @return resource
     */
    public static function bind(string $address)
    {
        $socket = @stream_socket_server('udp://'.$address, $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            throw new \RuntimeException('Cannot bind UDP '.$address.': '.$errstr);
        }
        stream_set_blocking($socket, false);

        return $socket;
    }

    public function stop(): void
    {
        $this->stop = true;
    }

    /**
     * @param  resource  $socket
     * @param  int|null  $maxSeconds  stop after this many seconds (tests); null = run until stop()
     */
    public function run($socket, ?int $maxSeconds = null): void
    {
        $started = time();
        $lastFlush = time();
        $lastSettings = 0;
        $lastStats = time();
        $enabled = false;

        while (! $this->stop && ($maxSeconds === null || (time() - $started) < $maxSeconds)) {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ((time() - $lastSettings) >= self::SETTINGS_REFRESH_SECONDS || $lastSettings === 0) {
                $lastSettings = time();
                $enabled = OutageSettings::pppoeSyslogEnabled();
                $this->ingestor->setSources(OutageSettings::pppoeSyslogSources());
            }

            $read = [$socket];
            $write = $except = null;
            $ready = @stream_select($read, $write, $except, 1);
            if ($ready !== false && $ready > 0) {
                for ($i = 0; $i < self::MAX_READ_PER_TICK; $i++) {
                    $data = @stream_socket_recvfrom($socket, 4096, 0, $peer);
                    if ($data === false || $data === '') {
                        break;
                    }
                    if (! $enabled) {
                        $this->ingestor->noteDisabled();

                        continue;
                    }
                    $this->ingestor->accept($data, self::ipOf((string) $peer));
                }
            }

            if ($this->ingestor->bufferSize() >= self::BATCH_SIZE || (time() - $lastFlush) >= self::FLUSH_SECONDS) {
                $this->ingestor->flush();
                $lastFlush = time();
            }
            if ((time() - $lastStats) >= 60) {
                $lastStats = time();
                $this->publishStats();
            }
        }

        $this->ingestor->flush();
        $this->publishStats();
    }

    /** "192.0.2.1:514" / "[::1]:514" => "192.0.2.1" / "::1". */
    public static function ipOf(string $peer): string
    {
        if (preg_match('/^\[([^\]]+)\]:\d+$/', $peer, $m) === 1) {
            return $m[1];
        }
        $pos = strrpos($peer, ':');

        return $pos === false ? $peer : substr($peer, 0, $pos);
    }

    private function publishStats(): void
    {
        $stats = $this->ingestor->stats();
        try {
            Cache::put(self::STATS_CACHE_KEY, $stats + ['at' => now()->toIso8601String()], now()->addHour());
            Log::info('[Outage] pppoe syslog stats', $stats);
        } catch (\Throwable) {
            // stats are best effort
        }
    }
}
