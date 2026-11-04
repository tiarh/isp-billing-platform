<?php

namespace App\Console\Commands;

use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Console\Command;

/**
 * Activity-logged write path for the outage_* settings (roadmap 2.4). Must be used instead of tinker
 * before any shadow enable. Live modes and customer WA are refused in this release.
 */
class OutageSettingsCommand extends Command
{
    protected $signature = 'outage:settings
        {action=show : show|set|keys}
        {key? : setting key (set)}
        {value? : new value (set); use "" to clear outage_shadow_log_jid}
        {--actor=cli : who is changing it (recorded in the activity log)}';

    protected $description = 'Show or change outage_* settings (validated, activity-logged)';

    public function handle(OutageSettingsWriter $writer): int
    {
        $action = strtolower((string) $this->argument('action'));

        if ($action === 'keys') {
            foreach (OutageSettingsWriter::keys() as $key) {
                $this->line($key);
            }

            return self::SUCCESS;
        }

        if ($action === 'set') {
            $key = (string) $this->argument('key');
            $value = $this->argument('value');
            if ($key === '' || $value === null) {
                $this->error('Usage: outage:settings set <key> <value> [--actor=name]');

                return self::FAILURE;
            }
            $result = $writer->set($key, (string) $value, (string) $this->option('actor'));
            if (! $result['ok']) {
                $this->error($result['message']);

                return self::FAILURE;
            }
            $this->info("{$key} updated.");
        } elseif ($action !== 'show') {
            $this->error('Action must be show, set or keys.');

            return self::FAILURE;
        }

        $this->line((string) json_encode(OutageSettings::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
