<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbWaJob;
use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Prod incident 2026-10-20: a PSB sent as a WhatsApp album was swallowed by the
 * technician's finished migration (#1187) as "follow-up photos".
 */
#[Group('blocking-psb')]
class MigrationFollowUpPhotoRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '120363-aktivasi@g.us';

    private const SENDER = '6281111111111@s.whatsapp.net';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution_api.api_key' => 'secret-real',
            'services.evolution_api.webhook_secret' => 'secret-real',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => 'secret-real',
        ]);
        Setting::set('psb_wa_group_ids', [self::GROUP]);
        Setting::set('attendance_group_id', 'different-group@g.us');
        Storage::fake(config('filesystems.default'));
        Queue::fake();
        Http::fake([
            'http://evolution.test/chat/getBase64FromMediaMessage/ops' => Http::response([
                'base64' => base64_encode(str_repeat('image-bytes', 20)),
            ]),
        ]);
    }

    private function migration(string $status, ?string $sender = null, int $minutesAgo = 5, array $extra = []): CustomerMigration
    {
        $m = CustomerMigration::create(array_merge([
            'status' => $status,
            'group_jid' => self::GROUP,
            'sender_jid' => $sender ?? self::SENDER,
            'message_id' => 'MIG-'.uniqid(),
            'full_name' => 'Migrasi Test',
            'meta' => ['marker' => 'untouched'],
        ], $extra));
        $m->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->saveQuietly();

        return $m->fresh();
    }

    /** @return array<string, mixed> */
    private function image(string $id, string $caption = '', ?string $albumId = null, ?string $sender = null): array
    {
        $message = ['imageMessage' => array_filter(['caption' => $caption, 'mimetype' => 'image/jpeg'])];
        if ($albumId !== null) {
            $message['messageContextInfo'] = [
                'messageAssociation' => [
                    'associationType' => 'MEDIA_ALBUM',
                    'parentMessageKey' => ['id' => $albumId, 'remoteJid' => self::GROUP],
                ],
            ];
        }

        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => self::GROUP,
                    'participant' => $sender ?? self::SENDER,
                    'id' => $id,
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'messageTimestamp' => now()->timestamp,
                'message' => $message,
            ],
        ];
    }

    private function send(array $payload): void
    {
        $this->postJson('/webhooks/evolution/attendance', $payload, ['apikey' => 'secret-real'])->assertOk();
    }

    private function noMigrationAttach(): void
    {
        $svc = Mockery::mock(CustomerMigrationService::class);
        $svc->shouldNotReceive('attachFollowUpImages');
        $this->app->instance(CustomerMigrationService::class, $svc);
    }

    private function assertUntouched(CustomerMigration $before): void
    {
        $after = $before->fresh();
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->ktp_photo_path, $after->ktp_photo_path);
        $this->assertSame($before->router_photo_path, $after->router_photo_path);
        $this->assertSame($before->meta, $after->meta);
    }

    private function psbImageCount(): int
    {
        $jobs = Queue::pushed(ProcessPsbWaJob::class);

        return $jobs->sum(fn (ProcessPsbWaJob $job) => count($job->payload['image_paths'] ?? []));
    }

    public function test_psb_album_after_synced_migration_goes_to_psb_not_migration(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_SYNCED, minutesAgo: 40);
        $this->noMigrationAttach();

        $this->send($this->image('ALB-1', "#psb\nNama: Budi Contoh\nAlamat: Krian", 'ALBUM-PARENT-1'));
        $this->send($this->image('ALB-2', '', 'ALBUM-PARENT-1'));
        $this->send($this->image('ALB-3', '', 'ALBUM-PARENT-1'));
        $this->send($this->image('ALB-4', '', 'ALBUM-PARENT-1'));

        Queue::assertPushed(ProcessPsbWaJob::class, fn (ProcessPsbWaJob $j) => ($j->payload['message_id'] ?? null) === 'ALB-1'
            && str_contains((string) $j->payload['text'], '#psb'));
        $this->assertSame(4, $this->psbImageCount());
        $this->assertUntouched($migration);
    }

    public function test_caption_less_photo_after_synced_migration_is_not_taken_by_migration(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_SYNCED, minutesAgo: 40);
        $this->noMigrationAttach();

        // Album siblings that arrive before the #psb caption: buffered for PSB, never the finished migration.
        $this->send($this->image('SYN-1', '', 'ALBUM-PARENT-2'));
        $this->send($this->image('SYN-2', '', 'ALBUM-PARENT-2'));
        $this->send($this->image('SYN-3', "#psb\nNama: Budi", 'ALBUM-PARENT-2'));

        $this->assertSame(3, $this->psbImageCount());
        $this->assertUntouched($migration);
    }

    public function test_open_migration_still_takes_plain_caption_less_photo(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);

        $svc = Mockery::mock(CustomerMigrationService::class);
        $svc->shouldReceive('attachFollowUpImages')->once()->withArgs(
            fn (CustomerMigration $m, array $bytes, string $caption): bool => $m->id === $migration->id && count($bytes) === 1 && $caption === ''
        )->andReturn($migration);
        $this->app->instance(CustomerMigrationService::class, $svc);

        $this->send($this->image('OPEN-1'));

        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_open_migration_does_not_take_psb_captioned_image_or_its_album_siblings(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        $this->send($this->image('OPEN-PSB-1', "#psb\nNama: Budi", 'ALBUM-PARENT-3'));
        $this->send($this->image('OPEN-PSB-2', '', 'ALBUM-PARENT-3'));
        $this->send($this->image('OPEN-PSB-3', '', 'ALBUM-PARENT-3'));

        $this->assertSame(3, $this->psbImageCount());
        $this->assertUntouched($migration);
    }

    public function test_other_command_captions_are_not_migration_follow_ups(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_RECEIVED);
        $this->noMigrationAttach();

        $this->send($this->image('CMD-1', '/psb Nama: Budi'));
        $this->send($this->image('CMD-2', '#pppoe cari budi'));

        $this->assertUntouched($migration);
    }

    /** @return array<string, array{0: string}> */
    public static function psbCaptionVariants(): array
    {
        return [
            'bold' => ["*#psb*\nNama: Budi"],
            'lrm prefix' => ["\u{200E}#psb\nNama: Budi"],
            'mid text' => ["Pasang baru #psb\nNama: Budi"],
            'near miss' => ["psb: Nama Budi\nOdp: 12"],
        ];
    }

    #[DataProvider('psbCaptionVariants')]
    public function test_psb_caption_variants_are_not_taken_by_open_migration(string $caption): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        $this->send($this->image('VAR-1', $caption, 'ALBUM-PARENT-V'));
        $this->send($this->image('VAR-2', '', 'ALBUM-PARENT-V'));

        $this->assertUntouched($migration);
    }

    public function test_hint_caption_still_attaches_to_open_migration(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);

        $svc = Mockery::mock(CustomerMigrationService::class);
        $svc->shouldReceive('attachFollowUpImages')->once()->withArgs(
            fn (CustomerMigration $m, array $bytes, string $caption): bool => $m->id === $migration->id && $caption === '#ktp'
        )->andReturn($migration);
        $this->app->instance(CustomerMigrationService::class, $svc);

        $this->send($this->image('HINT-1', '#ktp'));
    }

    public function test_photo_after_psb_command_without_album_field_goes_to_psb(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        // No album key anywhere: the PSB anchor (set by the #psb message) protects the siblings.
        $this->send($this->image('ANCH-1', "#psb\nNama: Budi"));
        $this->send($this->image('ANCH-2', ''));
        $this->send($this->image('ANCH-3', ''));

        $this->assertSame(3, $this->psbImageCount());
        $this->assertUntouched($migration);
    }

    /** @return array<string, mixed> */
    private function text(string $id, string $text): array
    {
        return [
            'event' => 'messages.upsert',
            'data' => [
                'key' => ['remoteJid' => self::GROUP, 'participant' => self::SENDER, 'id' => $id, 'fromMe' => false],
                'pushName' => 'Teknisi',
                'messageTimestamp' => now()->timestamp,
                'message' => ['conversation' => $text],
            ],
        ];
    }

    private function expectMigrationAttach(CustomerMigration $migration, ?string $caption = null): void
    {
        $svc = Mockery::mock(CustomerMigrationService::class);
        $svc->shouldReceive('attachFollowUpImages')->once()->withArgs(
            fn (CustomerMigration $m, array $bytes, string $c): bool => $m->id === $migration->id && ($caption === null || $c === $caption)
        )->andReturn($migration);
        $this->app->instance(CustomerMigrationService::class, $svc);
    }

    /** @return array<string, array{0: string}> */
    public static function nonPsbTriggerTexts(): array
    {
        return [
            'sudah' => ['sudah'],
            'done' => ['done'],
            'pppoe cari' => ['#pppoe cari x'],
            'status' => ['#status'],
        ];
    }

    #[DataProvider('nonPsbTriggerTexts')]
    public function test_non_psb_trigger_text_does_not_block_following_migration_photo(string $text): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->expectMigrationAttach($migration);

        $this->send($this->text('TXT-1', $text));
        $this->send($this->image('TXT-PHOTO'));

        $this->assertSame(0, $this->psbImageCount());
    }

    /** @return array<string, array{0: string}> */
    public static function bareWordCaptions(): array
    {
        return ['Sudah' => ['Sudah'], 'done' => ['done'], 'selesai' => ['selesai']];
    }

    #[DataProvider('bareWordCaptions')]
    public function test_bare_word_caption_stays_migration_follow_up(string $caption): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->expectMigrationAttach($migration, $caption);

        $this->send($this->image('BARE-1', $caption));

        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_psb_ktp_command_without_album_field_marks_siblings_as_psb(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('normalizeGroupJid')->andReturn(self::GROUP);
        $psb->shouldReceive('isAllowedGroup')->andReturnTrue();
        $psb->shouldReceive('reply')->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        // No album key at all: the psb-intake marker keeps the sibling away from the migration.
        $this->send($this->image('KTPNA-1', '#psb ktp #999999'));
        $this->send($this->image('KTPNA-2', ''));

        $this->assertUntouched($migration);
    }

    public function test_psb_ktp_album_siblings_do_not_go_to_open_migration(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('normalizeGroupJid')->andReturn(self::GROUP);
        $psb->shouldReceive('isAllowedGroup')->andReturnTrue();
        $psb->shouldReceive('reply')->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $this->send($this->image('KTPALB-1', '#psb ktp #999999', 'ALBUM-PARENT-K'));
        $this->send($this->image('KTPALB-2', '', 'ALBUM-PARENT-K'));

        $this->assertUntouched($migration);
    }

    public function test_migrasi_ktp_caption_is_unchanged(): void
    {
        $this->migration(CustomerMigration::STATUS_RECEIVED);
        $this->noMigrationAttach();

        $this->send($this->image('MIG-KTP-1', '#migrasi ktp #12345'));

        Queue::assertPushed(ProcessCustomerMigrationWaJob::class, fn ($j) => ($j->payload['message_id'] ?? null) === 'MIG-KTP-1');
        Queue::assertNotPushed(ProcessPsbWaJob::class);
    }

    public function test_other_sender_photo_is_not_attached_to_migration(): void
    {
        $migration = $this->migration(CustomerMigration::STATUS_WAITING_SN);
        $this->noMigrationAttach();

        $this->send($this->image('OTHER-1', '', null, '6281200000047@s.whatsapp.net'));

        $this->assertUntouched($migration);
    }
}
