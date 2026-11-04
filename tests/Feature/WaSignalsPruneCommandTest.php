<?php

namespace Tests\Feature;

use App\Models\WaChatSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class WaSignalsPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_deletes_nothing_and_reports_count(): void
    {
        $this->seedRows();

        $this->artisan('wa-signals:prune')
            ->expectsOutputToContain('2 row(s) eligible')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(5, WaChatSignal::count());
    }

    public function test_apply_deletes_only_rows_older_than_cutoff_and_logs_counts(): void
    {
        $this->seedRows();
        Log::spy();

        $this->artisan('wa-signals:prune', ['--apply' => true])
            ->expectsOutputToContain('Deleted 2 row(s)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(['recent', 'fresh', 'old-activation'], WaChatSignal::pluck('message_id')->all());
        Log::shouldHaveReceived('info')
            ->withArgs(fn ($m, $c = []) => $m === 'wa-signals:prune applied' && ($c['deleted'] ?? null) === 2)
            ->once();
    }

    public function test_custom_months_changes_cutoff(): void
    {
        $this->seedRows();

        $this->artisan('wa-signals:prune', ['--months' => 3, '--apply' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['fresh', 'old-activation'], WaChatSignal::pluck('message_id')->all());
    }

    public function test_invalid_months_is_rejected(): void
    {
        $this->artisan('wa-signals:prune', ['--months' => 0])->assertFailed();
    }

    public function test_prune_is_scheduled_weekly_with_apply_outside_the_full_only_block(): void
    {
        $source = (string) file_get_contents(base_path('routes/console.php'));

        $this->assertSame(1, substr_count($source, "Schedule::command('wa-signals:prune --months=18 --apply')"));
        $this->assertStringContainsString("->weeklyOn(0, '03:43')", $source);
        $this->assertLessThan(
            strpos($source, 'if ($fullSchedulerEnabled) {'),
            strpos($source, "Schedule::command('wa-signals:prune"),
        );
    }

    private function seedRows(): void
    {
        $this->row('old-occurred', now()->subMonths(19), now());
        $this->row('old-null-occurred', null, now()->subMonths(20));
        $this->row('recent', now()->subMonths(6), now()->subMonths(6));
        $this->row('fresh', now()->subDay(), now());
        $this->row('old-activation', now()->subMonths(24), now()->subMonths(24), 'activation');
    }

    private function row(string $id, $occurred, $created, string $channel = 'work'): void
    {
        $s = WaChatSignal::create([
            'channel' => $channel, 'domain' => 'ticket', 'intent' => 'incident', 'severity' => 'normal',
            'group_jid' => 'g@g.us', 'message_id' => $id, 'occurred_at' => $occurred,
        ]);
        WaChatSignal::query()->whereKey($s->id)->toBase()->update(['created_at' => $created, 'updated_at' => $created]);
    }
}
