<?php

namespace App\Console\Commands;

use App\Services\Outage\OutageShadowDigest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap 2.4 PR-4: daily shadow summary to the technical log group. Default OFF
 * (outage_shadow_digest_enabled). Never fails the scheduler: errors become a log line and exit 0.
 */
class OutageShadowDigestCommand extends Command
{
    protected $signature = 'outage:shadow-digest {--dry-run : Print the text, send nothing}';

    protected $description = 'Daily shadow outage summary to the technical log group (no PII; flag default off)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        try {
            $digest = new OutageShadowDigest;
            if ($dry) {
                $this->line($digest->compose(null, true)['text']);

                return self::SUCCESS;
            }

            $status = $digest->send();
            $this->line('shadow digest: '.$status);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] outage:shadow-digest failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
            $this->warn('outage:shadow-digest failed: '.$e::class);

            return $dry ? self::FAILURE : self::SUCCESS;
        }
    }
}
