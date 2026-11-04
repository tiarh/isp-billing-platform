<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\OutageSettingsWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The four settings of learn & measure: allow-listed in `outage:settings`, safe defaults (snapshots and labels are DB only
 * and on; the two things a person can SEE are off), and the new commands are on the scheduler without overlap or queues.
 */
#[Group('blocking-core')]
class DiagnosisLearnSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEYS = [
        'diagnosis_episode_snapshot_enabled' => true,
        'diagnosis_outcome_labels_enabled' => true,
        'diagnosis_calibrated_labels_enabled' => false,
        'diagnosis_signature_chip_enabled' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
    }

    public function test_the_keys_are_allow_listed_and_default_to_the_safe_values(): void
    {
        $snapshot = OutageSettings::diagnosisSnapshot();
        $writer = new OutageSettingsWriter;
        foreach (self::KEYS as $key => $default) {
            $this->assertContains($key, OutageSettingsWriter::keys(), $key);
            $this->assertSame($default, $snapshot[$key], $key.' default');
            $this->assertSame(['ok' => true, 'message' => 'ok', 'stored' => '1'], $writer->validate($key, 'on'), $key);
            $this->assertFalse($writer->validate($key, 'maybe')['ok'], $key);
        }
        $this->assertSame($snapshot['diagnosis_signature_chip_enabled'], OutageSettings::snapshot()['diagnosis_signature_chip_enabled']);
    }

    public function test_the_settings_command_flips_each_key_and_activity_is_kept_by_the_existing_command(): void
    {
        foreach (self::KEYS as $key => $default) {
            $this->assertSame(0, Artisan::call('outage:settings', ['action' => 'set', 'key' => $key, 'value' => $default ? '0' : '1']), $key);
            Setting::flushRequestMemo();
            $this->assertSame(! $default, OutageSettings::diagnosisSnapshot()[$key], $key);
        }
    }

    public function test_the_new_commands_are_scheduled_in_the_background_without_overlap_and_never_on_a_queue(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        foreach (['outage:outcome-label' => "cron('11 * * * *')", 'outage:diagnosis-context-prune' => "dailyAt('03:52')"] as $command => $when) {
            $pos = strpos($source, "Schedule::command('{$command}')");
            $this->assertNotFalse($pos, $command);
            $block = substr($source, $pos, 420);
            foreach ([$when, 'withoutOverlapping(', 'onOneServer()', 'runInBackground()', "timezone('Asia/Jakarta')"] as $needle) {
                $this->assertStringContainsString($needle, $block, $command.' '.$needle);
            }
        }
        // the outcome labels never fire in the minute the presence archive starts (01:23 WIB)
        $this->assertStringContainsString("Schedule::command('diagnosis:presence-archive", $source);
        $this->assertMatchesRegularExpression("/Schedule::command\\('diagnosis:presence-archive[^;]*dailyAt\\('01:23'\\)/s", $source);
        $this->assertStringNotContainsString("cron('23 * * * *')", substr($source, (int) strpos($source, "Schedule::command('outage:outcome-label')"), 200));
        // the back-test and the export are by hand only
        $this->assertStringNotContainsString("Schedule::command('outage:diagnosis-backtest", $source);
        $this->assertStringNotContainsString("Schedule::command('outage:diagnosis-corpus-export", $source);
    }
}
