<?php

namespace Tests\Feature;

use App\Jobs\SendWaCampaignMessage;
use App\Models\User;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class WaCampaignRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_requeues_recipients_paused_by_broadcast_dry_run_without_sending_wa(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $campaign = WaCampaign::create([
            'name' => 'Retry dry-run campaign',
            'message_template' => 'Test {name}',
            'target_type' => 'all',
            'status' => 'paused',
            'total_recipients' => 2,
            'failed_count' => 0,
        ]);

        $dryRun = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000001',
            'status' => 'pending',
            'error_message' => 'Dry-run: broadcast WA billing belum di-enable.',
        ]);
        WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000002',
            'status' => 'pending',
            'error_message' => 'Nomor tidak valid.',
        ]);

        $response = $this->actingAs($admin)->post(route('broadcasts.retry', $campaign));

        $response->assertRedirect();
        $response->assertSessionHas('success', 'Retrying 1 message(s). They have been queued.');
        $this->assertDatabaseHas('wa_campaign_recipients', [
            'id' => $dryRun->id,
            'status' => 'pending',
            'error_message' => null,
        ]);
        $this->assertDatabaseHas('wa_campaign_recipients', [
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000002',
            'status' => 'pending',
            'error_message' => 'Nomor tidak valid.',
        ]);
        $this->assertDatabaseHas('wa_campaigns', [
            'id' => $campaign->id,
            'status' => 'processing',
        ]);
        Queue::assertPushedOn('wa', SendWaCampaignMessage::class);
        Queue::assertPushed(SendWaCampaignMessage::class, 1);
    }

    public function test_retry_failed_skips_unconfirmed_recipients(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $campaign = WaCampaign::create([
            'name' => 'Mixed campaign',
            'message_template' => 'Test {name}',
            'target_type' => 'all',
            'status' => 'completed',
            'total_recipients' => 2,
            'failed_count' => 1,
            'unconfirmed_count' => 1,
        ]);
        $failed = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000011',
            'status' => 'failed',
            'error_message' => 'Nomor tidak terdaftar.',
        ]);
        $unconfirmed = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000012',
            'status' => 'unconfirmed',
            'error_message' => 'Timeout — status kirim tidak pasti',
        ]);

        $this->actingAs($admin)->post(route('broadcasts.retry', $campaign))->assertRedirect();

        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertSame('unconfirmed', $unconfirmed->fresh()->status);
        $this->assertSame(1, (int) $campaign->fresh()->unconfirmed_count);
        Queue::assertPushed(SendWaCampaignMessage::class, 1);
    }
}
