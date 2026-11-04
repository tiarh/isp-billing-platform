<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ProcessRecapTopicCommandJob;
use App\Models\Area;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\Recap\OnuHealthService;
use App\Services\Recap\RecapTopicMessages;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * "#rekap migrasi": webhook routing (flags, queues, other bots untouched) and the job reply
 * (text, Excel deleted after send, silence when the flag is off, shared rate limit).
 */
#[Group('blocking-psb')]
class RecapMigrasiCommandFlowTest extends TestCase
{
    use RefreshDatabase;

    private const G = '120363-activation@g.us';

    private const R = '120363-skynetreport@g.us';

    private int $seq = 0;

    /** @var list<string> */
    private array $texts = [];

    /** @var list<string> */
    private array $docs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.evolution_api.webhook_secret' => 'known-webhook-secret',
            'services.evolution_api.api_key' => 'fallback-api-key',
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta'));
        File::deleteDirectory(storage_path('app/recap/migrasi'));
        Setting::set('psb_wa_group_ids', [self::G]);
        Setting::set('psb_recap_cmd_enabled', '1');
        Setting::set('recap_migrasi_cmd_enabled', '1');
        Setting::set('report_wa_group_ids', [self::R]);
        Setting::set('report_recap_cmd_enabled', '1');

        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        DB::table('customer_migrations')->insert([
            'status' => 'synced', 'source' => 'wa', 'message_id' => 'flow-1', 'group_jid' => 'activation@g.us', 'sender_name' => 'Andi',
            'pppoe_user' => 'flow1', 'full_name' => 'Siti Contoh', 'area_id' => $area->id,
            'synced_at' => '2026-10-14 03:00:00', 'created_at' => '2026-10-13 20:00:00', 'updated_at' => '2026-10-14 03:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/recap/migrasi'));
        $this->travelBack();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function text(string $id, string $text, string $group = self::G): array
    {
        return [
            'key' => ['remoteJid' => $group, 'participant' => '6281111111111@s.whatsapp.net', 'id' => $id, 'fromMe' => false],
            'pushName' => 'Teknisi',
            'message' => ['conversation' => $text],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function postBatch(array $items)
    {
        return $this->postJson('/webhooks/attendance/evolution', [
            'event' => 'messages.upsert',
            'data' => ['messages' => $items],
        ], ['apikey' => 'known-webhook-secret']);
    }

    private function fakeWhatsapp(string $channel = 'ops'): EvolutionApiService
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with($channel)->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) {
            $this->texts[] = $jid.'|'.$text;

            return ['success' => true];
        });
        $evo->shouldReceive('sendDocument')->andReturnUsing(function (string $jid, string $path) {
            $this->assertFileExists($path);
            $this->docs[] = $jid;

            return ['success' => true];
        });

        return $evo;
    }

    /** @param array<string, mixed> $extra */
    private function run_(EvolutionApiService $evo, array $extra = [], bool $keepCooldown = false): void
    {
        if (! $keepCooldown) {
            Cache::forget('psb-recap:group:'.md5((string) ($extra['group_jid'] ?? self::G)));
        }
        $job = new ProcessRecapTopicCommandJob(array_merge([
            'group_jid' => self::G,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'MSG-'.(++$this->seq),
            'text' => '#rekap migrasi klampok kemarin',
        ], $extra));
        $job->handle(app(PsbAreaRecapService::class), app(OnuHealthService::class), app(RecapTopicMessages::class), $evo);
    }

    // ---- webhook routing

    public function test_migrasi_recap_goes_only_to_the_wa_queue_with_a_plain_payload(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('MR-1', '#rekap migrasi klampok 1-14 okt')])->assertOk();

        Queue::assertPushedOn('wa', ProcessRecapTopicCommandJob::class, fn (ProcessRecapTopicCommandJob $job): bool => $job->payload === [
            'group_jid' => self::G,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'MR-1',
            'text' => '#rekap migrasi klampok 1-14 okt',
        ]);
        Queue::assertNotPushed(ProcessPsbWaJob::class);
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_rekap_psb_payload_stays_byte_identical_with_the_migrasi_flag_on(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('MR-P', '#rekap psb klampok 1-14 okt')])->assertOk();

        Queue::assertPushedOn('wa', ProcessPsbRecapCommandJob::class, fn (ProcessPsbRecapCommandJob $job): bool => $job->payload === [
            'group_jid' => self::G,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'MR-P',
            'text' => '#rekap psb klampok 1-14 okt',
        ]);
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    public function test_psb_and_migration_commands_are_never_hijacked(): void
    {
        Queue::fake();

        $this->postBatch([
            $this->text('MR-B1', '#psb bantu'),
            $this->text('MR-B2', '#migrasi cek #5'),
            $this->text('MR-B3', '#migrasi rekap klampok'),
            $this->text('MR-B4', '#rekap migrasi klampok #migrasi cek #5'),
            $this->text('MR-B5', '#rekap migrasi #psb bantu'),
        ])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
        Queue::assertNotPushed(ProcessPsbRecapCommandJob::class);
    }

    public function test_flag_off_means_no_job_and_the_onu_flag_does_not_enable_it(): void
    {
        Queue::fake();
        Setting::set('recap_migrasi_cmd_enabled', '0');
        Setting::set('recap_onu_cmd_enabled', '1');

        $this->postBatch([$this->text('MR-E1', '#rekap migrasi klampok'), $this->text('MR-E2', '#psb bantu')])->assertOk();

        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
        Queue::assertPushedOn('psb', ProcessPsbWaJob::class);
    }

    public function test_report_group_needs_both_flags(): void
    {
        Queue::fake();

        $this->postBatch([$this->text('MR-R1', '#rekap migrasi semua', self::R)])->assertOk();
        Queue::assertPushedOn('wa', ProcessRecapTopicCommandJob::class, fn (ProcessRecapTopicCommandJob $j): bool => ($j->payload['context'] ?? null) === 'report');

        Queue::fake();
        Setting::set('report_recap_cmd_enabled', '0');
        $this->postBatch([$this->text('MR-R2', '#rekap migrasi semua', self::R)])->assertOk();
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);

        Setting::set('report_recap_cmd_enabled', '1');
        $this->postBatch([$this->text('MR-R3', '#migrasi rekap', self::R)])->assertOk();
        Queue::assertNotPushed(ProcessRecapTopicCommandJob::class);
    }

    // ---- job

    public function test_sends_text_then_excel_and_deletes_the_file(): void
    {
        $this->run_($this->fakeWhatsapp());

        $this->assertCount(1, $this->texts);
        $this->assertStringStartsWith(self::G.'|*REKAP MIGRASI - KLAMPOK*', $this->texts[0]);
        $this->assertStringContainsString('SITI CONTOH - selesai (DB)', $this->texts[0]);
        $this->assertSame([self::G], $this->docs);
        $this->assertSame([], File::files(storage_path('app/recap/migrasi')), 'the Excel must not stay on disk');
    }

    public function test_semua_text_has_no_names_and_zero_data_has_no_excel(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi semua kemarin']);
        $this->assertStringContainsString('REKAP MIGRASI - SEMUA AREA', $this->texts[0]);
        $this->assertStringNotContainsString('SITI', $this->texts[0]);
        $this->assertCount(1, $this->docs);

        $this->texts = [];
        $this->docs = [];
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi klampok 1-5 okt']);
        $this->assertStringContainsString('Tidak ada migrasi tercatat untuk KLAMPOK pada periode ini.', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_usage_invalid_date_range_and_unknown_area_replies(): void
    {
        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi']);
        $this->assertStringContainsString('Format: *#rekap migrasi <daerah|semua> [tanggal]*', $this->texts[0]);

        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi klampok 2026']);
        $this->assertStringContainsString('Tanggal tidak valid', $this->texts[1]);

        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi klampok 01/01/2026 14/10/2026']);
        $this->assertStringContainsString('Rentang tanggal terlalu panjang', $this->texts[2]);

        $this->run_($this->fakeWhatsapp(), ['text' => '#rekap migrasi zzzzqqq']);
        $this->assertStringContainsString('tidak ditemukan', $this->texts[3]);
        $this->assertSame([], $this->docs);
    }

    public function test_flag_off_at_run_time_is_silent(): void
    {
        Setting::set('recap_migrasi_cmd_enabled', '0');

        $this->run_($this->fakeWhatsapp());

        $this->assertSame([], $this->texts);
        $this->assertSame([], $this->docs);
    }

    public function test_the_rate_limit_is_shared_with_rekap_psb(): void
    {
        Cache::add('psb-recap:group:'.md5(self::G), 1, now()->addSeconds(ProcessPsbRecapCommandJob::GROUP_COOLDOWN_SECONDS));

        $this->run_($this->fakeWhatsapp(), [], true);

        $this->assertCount(1, $this->texts);
        $this->assertStringContainsString('Tunggu sekitar 20 detik', $this->texts[0]);
        $this->assertSame([], $this->docs);
    }

    public function test_the_evidence_flag_is_read_at_run_time(): void
    {
        DB::table('customer_migrations')->update(['meta' => json_encode(['auto_reconcile' => ['version' => 1]])]);

        $this->run_($this->fakeWhatsapp());
        $this->assertStringNotContainsString('Otomatis', $this->texts[0]);

        Setting::set('recap_migration_olt_evidence_enabled', '1');
        Cache::forget('psb-recap:group:'.md5(self::G));
        $this->run_($this->fakeWhatsapp());
        $this->assertStringContainsString('Status DB 0 | Otomatis (ONU + PPPoE online) 1', $this->texts[1]);
    }
}
