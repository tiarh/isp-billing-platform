<?php

namespace App\Console\Commands;

use App\Services\Outage\PppoeSyslogIngestor;
use App\Services\Outage\PppoeSyslogListener;
use Illuminate\Console\Command;

/**
 * RCA-2: UDP syslog receiver for MikroTik PPPoE lines. Long-running; started only by the opt-in compose profile
 * `pppoe-syslog` (deploy/3node). It idles (reads and discards) while the setting pppoe_syslog_enabled is 0.
 */
class PppoeSyslogListen extends Command
{
    protected $signature = 'pppoe-syslog:listen {--bind=0.0.0.0 : Address to bind} {--port=5514 : UDP port} {--seconds= : Stop after N seconds (tests)}';

    protected $description = 'Receive MikroTik PPPoE syslog over UDP into pppoe_session_events (opt-in, off by default)';

    public function handle(): int
    {
        $port = (int) $this->option('port');
        if ($port < 1 || $port > 65535) {
            $this->error('Invalid --port.');

            return self::FAILURE;
        }

        $listener = new PppoeSyslogListener(new PppoeSyslogIngestor);
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $listener->stop());
            pcntl_signal(SIGINT, fn () => $listener->stop());
        }

        $socket = PppoeSyslogListener::bind((string) $this->option('bind').':'.$port);
        $this->info("pppoe-syslog listening on udp {$this->option('bind')}:{$port}");
        $seconds = $this->option('seconds');
        $listener->run($socket, $seconds !== null && $seconds !== '' ? (int) $seconds : null);

        return self::SUCCESS;
    }
}
