<?php

namespace Tests\Unit;

use App\Models\PsbDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AuditPsbOcrAggregateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_range_fails_before_querying_any_ocr_data(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('psb_drafts')->andReturn(true);
        DB::shouldReceive('table')->never();

        $this->artisan('ocr:audit-aggregate', [
            '--since' => '2026-09-14T12:00:00+07:00',
            '--until' => '2026-09-14T11:00:00+07:00',
            '--json' => true,
        ])->assertExitCode(1);
    }

    public function test_json_output_contains_aggregates_only(): void
    {
        PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'meta' => [
                'photo_class' => [
                    'status' => 'completed',
                    'unknown_count' => 0,
                    'ocr_ms' => 125,
                ],
            ],
        ]);

        $output = new BufferedOutput;
        $exitCode = Artisan::call('ocr:audit-aggregate', ['--json' => true], $output);
        $metrics = json_decode(trim($output->fetch()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $metrics['draft_count']);
        $this->assertSame(1, $metrics['completed_batches']);
        $this->assertSame(125, $metrics['total_ocr_ms']);
        $this->assertArrayNotHasKey('photo_class', $metrics);
    }
}
