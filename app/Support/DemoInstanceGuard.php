<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Safety net for the Duitku demo instance (DEMO_INSTANCE=true).
 *
 * The demo copy must never be able to talk to real customers or real network
 * devices: Evolution (WhatsApp) credentials must be empty and the routers /
 * olts tables must be empty. When violated the app refuses to boot.
 */
class DemoInstanceGuard
{
    public const CUSTOMER_CODE_PREFIX = 'DEMO-';

    public static function enabled(): bool
    {
        return (bool) config('app.demo_instance', false);
    }

    /**
     * @return list<string> human readable violations (empty = safe)
     */
    public static function violations(): array
    {
        $violations = [];

        foreach (['api_key', 'webhook_secret'] as $key) {
            if (trim((string) config('services.evolution_api.'.$key, '')) !== '') {
                $violations[] = 'Evolution API credential services.evolution_api.'.$key.' is set';
            }
        }

        if (config('services.whatsapp.provider') !== 'disabled') {
            $violations[] = 'WA_PROVIDER must be disabled';
        }
        if (config('services.notification.channel') !== 'disabled') {
            $violations[] = 'NOTIFICATION_CHANNEL must be disabled';
        }
        if (trim((string) config('services.fonnte.key', '')) !== '') {
            $violations[] = 'FONNTE_API_KEY is set';
        }
        if (trim((string) config('services.evolution_api.base_url', '')) !== ''
            && config('services.evolution_api.base_url') !== 'http://127.0.0.1:8085') {
            $violations[] = 'EVOLUTION_API_BASE_URL is set';
        }

        // Runs on every demo request/artisan call (2 cheap queries); acceptable for a demo.
        // A DB outage fails closed: the container restart loop is intended.
        foreach (['routers', 'olts'] as $table) {
            try {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    $violations[] = "table {$table} is not empty";
                }
            } catch (\Throwable $e) {
                // DB unreachable: fail closed, a demo that cannot prove it is empty must not boot.
                $violations[] = "cannot verify table {$table} is empty (".$e::class.')';
            }
        }

        return $violations;
    }

    /**
     * @throws RuntimeException when DEMO_INSTANCE=true and the instance is unsafe
     */
    public static function assertSafe(): void
    {
        if (! self::enabled()) {
            return;
        }

        $violations = self::violations();
        if ($violations === []) {
            return;
        }

        Log::critical('[DemoInstance] refusing to boot', ['violations' => $violations]);

        throw new RuntimeException('DEMO_INSTANCE refused to boot: '.implode('; ', $violations));
    }
}
