<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Services\CustomerMigrationService;
use App\Services\PsbOrchestratorService;
use App\Support\OcrBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Migration WA photos share one time budget (min(480, photos x 90 s)), like PSB.
 * Photos left when it runs out are handled like a photo that timed out.
 */
#[Group('blocking-psb')]
class CustomerMigrationPhotoBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
    }

    private function migration(): CustomerMigration
    {
        return CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-budget-'.uniqid(),
            'full_name' => '',
            'new_sn' => 'FHTTBUDGET01',
        ]);
    }

    /** @return list<string> */
    private function photos(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = str_repeat((string) $i, 300);
        }

        return $out;
    }

    /**
     * @param  callable(string, OcrBudget): array<string,mixed>  $classify
     */
    private function probe(callable $classify, float &$now, array &$budgetSeconds): MigrationPhotoBudgetTestProbe
    {
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('classifyWaActivationPhoto')->andReturnUsing($classify);
        $this->app->instance(PsbOrchestratorService::class, $psb);

        /** @var MigrationPhotoBudgetTestProbe $svc */
        $svc = app()->make(MigrationPhotoBudgetTestProbe::class);
        $svc->clock = static function () use (&$now): float {
            return $now;
        };
        $svc->seconds = &$budgetSeconds;

        return $svc;
    }

    public function test_budget_spent_skips_the_remaining_photos_as_timeout_and_keeps_earlier_results(): void
    {
        config(['services.ocrspace.media_image_budget_seconds' => 90]);
        $now = 1000.0;
        $seconds = [];
        $calls = 0;
        $svc = $this->probe(function (string $bytes, OcrBudget $budget) use (&$now, &$calls): array {
            $calls++;
            $now += 200.0; // each photo is very slow

            return [
                'type' => 'ktp',
                'ktp_score' => 100,
                'ktp_ocr' => $calls === 1 ? ['name' => 'BUDI CONTOH SANTOSO', 'nik' => '3507124110000001'] : [],
                'classification_status' => 'terminal',
            ];
        }, $now, $seconds);
        $migration = $this->migration();

        // 5 photos: budget = min(300, 5 x 90) = 300 s. After 2 x 200 s it is spent.
        $svc->attach($migration, $this->photos(5), '');

        $this->assertSame([300.0], $seconds);
        $this->assertSame(2, $calls, 'photos 3 to 5 are never sent to OCR');
        $this->assertSame('BUDI CONTOH SANTOSO', $migration->full_name);
        $this->assertSame('3507124110000001', $migration->nik);
        $rows = $migration->meta['photo_class'];
        $this->assertCount(5, $rows);
        $this->assertSame('ktp', $rows[0]['label']);
        $this->assertSame('unknown', $rows[2]['label']);
        $this->assertSame('type_mismatch', $rows[2]['status']);
        $this->assertSame('unknown', $rows[3]['label']);
        $this->assertSame('type_mismatch', $rows[3]['status']);
        $this->assertSame('unknown', $rows[4]['label']);
        $this->assertSame('type_mismatch', $rows[4]['status']);
    }

    public function test_budget_is_capped_at_300_seconds_under_the_job_timeout_and_passed_to_each_photo(): void
    {
        config(['services.ocrspace.media_image_budget_seconds' => 90]);
        $now = 1000.0;
        $seconds = [];
        $seen = [];
        $svc = $this->probe(function (string $bytes, OcrBudget $budget) use (&$seen): array {
            $seen[] = round($budget->remaining());

            return ['type' => 'unknown', 'ktp_ocr' => [], 'classification_status' => 'terminal'];
        }, $now, $seconds);

        $svc->attach($this->migration(), $this->photos(8), '');

        $this->assertSame([300.0], $seconds);
        $this->assertLessThan(420, $seconds[0]);
        $this->assertSame(array_fill(0, 8, 300.0), $seen);
    }

    public function test_normal_batch_is_unchanged_every_photo_is_classified(): void
    {
        config(['services.ocrspace.media_image_budget_seconds' => 90]);
        $now = 1000.0;
        $seconds = [];
        $calls = 0;
        $svc = $this->probe(function (string $bytes, OcrBudget $budget) use (&$now, &$calls): array {
            $calls++;
            $now += 20.0;

            return [
                'type' => $calls === 1 ? 'ktp' : 'sn',
                'sn' => $calls === 1 ? null : 'FHTTNEWTEST01',
                'ktp_score' => 100,
                'sn_score' => 90,
                'ktp_ocr' => $calls === 1 ? ['name' => 'SITI CONTOH'] : [],
                'classification_status' => 'terminal',
            ];
        }, $now, $seconds);
        $migration = $this->migration();

        $svc->attach($migration, $this->photos(2), '');

        $this->assertSame([180.0], $seconds);
        $this->assertSame(2, $calls);
        $this->assertSame('SITI CONTOH', $migration->full_name);
        $this->assertNotNull($migration->ktp_photo_path);
        $this->assertNotNull($migration->router_photo_path);
        $this->assertSame('ktp', $migration->meta['photo_class'][0]['label']);
        $this->assertSame('sn', $migration->meta['photo_class'][1]['label']);
    }

    public function test_skipped_photo_with_a_ktp_caption_never_replaces_an_already_saved_ktp(): void
    {
        config(['services.ocrspace.media_image_budget_seconds' => 90]);
        $now = 1000.0;
        $seconds = [];
        $calls = 0;
        $svc = $this->probe(function (string $bytes, OcrBudget $budget) use (&$now, &$calls): array {
            $calls++;
            $now += 1000.0;

            return [
                'type' => 'ktp',
                'ktp_ocr' => ['name' => 'BUDI CONTOH SANTOSO', 'nik' => '3507124110000001'],
                'classification_status' => 'terminal',
            ];
        }, $now, $seconds);
        $migration = $this->migration();

        $svc->attach($migration, $this->photos(3), 'ktp');

        $this->assertSame(1, $calls);
        $firstPath = $migration->ktp_photo_path;
        $this->assertNotNull($firstPath);
        $this->assertSame($firstPath, $migration->meta['photo_class'][0]['path']);
        $this->assertSame('3507124110000001', $migration->nik);
        $this->assertSame('BUDI CONTOH SANTOSO', $migration->full_name);
        foreach ([1, 2] as $i) {
            $this->assertSame('budget_skipped', $migration->meta['photo_class'][$i]['status']);
            $this->assertNull($migration->meta['photo_class'][$i]['path']);
        }
        $this->assertCount(1, Storage::disk('local')->allFiles('migrasi'), 'skipped photos are not stored');
    }

    public function test_skipped_photo_with_a_caption_is_stored_when_no_photo_is_saved_yet(): void
    {
        config(['services.ocrspace.media_image_budget_seconds' => 90]);
        $now = 1000.0;
        $seconds = [];
        $svc = $this->probe(function (string $bytes, OcrBudget $budget) use (&$now): array {
            $now += 1000.0;

            // Photo 1 is an SN sticker, so the KTP field is still empty.
            return ['type' => 'sn', 'sn' => 'FHTTNEWTEST01', 'ktp_ocr' => [], 'classification_status' => 'terminal'];
        }, $now, $seconds);
        $migration = $this->migration();

        $svc->attach($migration, $this->photos(2), 'ktp');

        // Photo 1 with a "ktp" caption is stored as KTP anyway (caption wins);
        // photo 2 is skipped and must not replace it.
        $this->assertNotNull($migration->ktp_photo_path);
        $this->assertSame($migration->ktp_photo_path, $migration->meta['photo_class'][0]['path']);
        $this->assertSame('budget_skipped', $migration->meta['photo_class'][1]['status']);
    }
}

class MigrationPhotoBudgetTestProbe extends CustomerMigrationService
{
    /** @var (callable():float)|null */
    public $clock = null;

    /** @var list<float> */
    public array $seconds = [];

    /** @param list<string> $bytesList */
    public function attach(CustomerMigration $migration, array $bytesList, string $hint): void
    {
        $this->attachPhotoBytes($migration, $bytesList, $hint);
    }

    protected function photoBatchBudget(float $seconds): OcrBudget
    {
        $this->seconds[] = $seconds;

        return OcrBudget::start($seconds, $this->clock !== null ? \Closure::fromCallable($this->clock) : null);
    }
}
