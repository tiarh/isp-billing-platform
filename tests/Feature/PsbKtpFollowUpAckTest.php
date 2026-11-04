<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\PsbOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbKtpFollowUpAckTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_ktp_upload_to_completed_draft_gets_confirmation_reply(): void
    {
        $this->runAckScenario([], ['document_gate' => ['status' => 'verified']], 'KTP sudah terbaca.', 'WA-KTP-ACK-1');
    }

    public function test_ack_says_ktp_is_being_read_while_ocr_is_pending(): void
    {
        $this->runAckScenario([], ['document_gate' => ['status' => 'ocr_pending']], 'KTP sedang dibaca.', 'WA-KTP-ACK-2');
    }

    public function test_ack_says_ktp_read_when_it_was_verified_before_and_new_photo_is_pending(): void
    {
        $this->runAckScenario(
            ['document_gate' => ['status' => 'verified']],
            ['document_gate' => ['status' => 'ocr_pending']],
            'KTP sudah terbaca.',
            'WA-KTP-ACK-3'
        );
    }

    /**
     * @param  array<string,mixed>  $metaBefore
     * @param  array<string,mixed>  $metaAfter
     */
    private function runAckScenario(array $metaBefore, array $metaAfter, string $expectedLine, string $messageId): void
    {
        $group = '120363-aktivasi@g.us';
        config([
            'services.evolution_api.api_key' => 'secret-real',
            'services.evolution_api.webhook_secret' => 'secret-real',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => 'secret-real',
        ]);
        Setting::set('psb_wa_group_ids', [$group]);
        Setting::set('attendance_group_id', 'different-group@g.us');

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => $group,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'PSB-ORIGINAL',
            'full_name' => 'Pelanggan Test',
            'meta' => $metaBefore,
        ]);

        Http::fake([
            'http://evolution.test/chat/getBase64FromMediaMessage/ops' => Http::response([
                'base64' => base64_encode(str_repeat('image-bytes', 20)),
            ]),
        ]);

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('normalizeGroupJid')->once()->with($group)->andReturn($group);
        $psb->shouldReceive('isAllowedGroup')->once()->with($group)->andReturnTrue();
        $psb->shouldReceive('attachFollowUpImages')
            ->once()
            ->andReturnUsing(function (PsbDraft $target) use ($metaAfter): PsbDraft {
                $target->ktp_photo_path = 'psb/2026/07/ktp_test.jpg';
                $target->meta = $metaAfter;
                $target->save();

                return $target->fresh();
            });
        $psb->shouldReceive('reply')
            ->once()
            ->with($group, Mockery::on(fn (string $message): bool => str_contains($message, "Draft: *#{$draft->id}*")
                && str_contains($message, $expectedLine)
                && ! str_contains($message, 'Status KTP:')
                && ! str_contains($message, 'ocr_pending')
                && str_contains($message, 'Tidak perlu kirim ulang.')))
            ->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => $group,
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => $messageId,
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'messageTimestamp' => now()->timestamp,
                'message' => [
                    'imageMessage' => ['caption' => "#psb ktp #{$draft->id}"],
                ],
            ],
        ], ['apikey' => 'secret-real'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);
    }

    public function test_explicit_psb_ktp_caption_precedes_active_migration_photo_fallback(): void
    {
        $group = '120363-aktivasi@g.us';
        config([
            'services.evolution_api.api_key' => 'secret-real',
            'services.evolution_api.webhook_secret' => 'secret-real',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => 'secret-real',
        ]);
        Setting::set('psb_wa_group_ids', [$group]);
        Setting::set('attendance_group_id', 'different-group@g.us');

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => $group,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'PSB-ORIGINAL-321',
            'full_name' => 'Pelanggan Test',
            'meta' => ['waiting_photo' => true],
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_RECEIVED,
            'group_jid' => $group,
            'sender_jid' => '6281111111111@s.whatsapp.net',
            'message_id' => 'MIGRATION-ACTIVE-1',
            'full_name' => 'Migrasi Aktif',
        ]);

        Http::fake([
            'http://evolution.test/chat/getBase64FromMediaMessage/ops' => Http::response([
                'base64' => base64_encode(str_repeat('image-bytes', 20)),
            ]),
        ]);

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('normalizeGroupJid')->once()->with($group)->andReturn($group);
        $psb->shouldReceive('isAllowedGroup')->once()->with($group)->andReturnTrue();
        $psb->shouldReceive('attachFollowUpImages')->once()->withArgs(
            fn (PsbDraft $target, array $bytes, array $context): bool => $target->id === $draft->id
                && count($bytes) === 1
                && ($context['message_id'] ?? null) === 'WA-KTP-321'
        )->andReturn($draft);
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $migration = Mockery::mock(CustomerMigrationService::class);
        $migration->shouldNotReceive('attachFollowUpImages');
        $this->app->instance(CustomerMigrationService::class, $migration);

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => $group,
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => 'WA-KTP-321',
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'messageTimestamp' => now()->timestamp,
                'message' => [
                    'imageMessage' => ['caption' => '#psb ktp #'.$draft->id],
                ],
            ],
        ], ['apikey' => 'secret-real'])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'processed' => 1]);
    }
}
