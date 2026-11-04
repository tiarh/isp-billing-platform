<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaCampaign;
use App\Models\User;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WaCampaignLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_campaign_enters_processing_when_queued(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('broadcasts.store'), [
            'name' => 'Status test',
            'target_type' => 'all',
            'target_area_id' => null,
            'message_template' => 'Test {name}',
        ]);

        $response->assertRedirect(route('broadcasts.index'));
        $campaign = WaCampaign::query()->firstOrFail();
        $this->assertSame('processing', $campaign->status);
        Queue::assertPushedOn('wa', ProcessWaCampaign::class);
    }

    public function test_existing_draft_can_be_started_once(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $campaign = WaCampaign::create([
            'name' => 'Stuck draft',
            'message_template' => 'Test {name}',
            'target_type' => 'all',
            'status' => 'draft',
        ]);

        $this->actingAs($admin)
            ->post(route('broadcasts.start', $campaign))
            ->assertRedirect();

        $this->assertDatabaseHas('wa_campaigns', [
            'id' => $campaign->id,
            'status' => 'processing',
        ]);
        Queue::assertPushed(ProcessWaCampaign::class, 1);

        $this->actingAs($admin)
            ->post(route('broadcasts.start', $campaign))
            ->assertRedirect();

        Queue::assertPushed(ProcessWaCampaign::class, 1);
    }

    public function test_legacy_draft_is_failed_if_queue_dispatch_fails(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $campaign = WaCampaign::create([
            'name' => 'Dispatch failure',
            'message_template' => 'Test {name}',
            'target_type' => 'all',
            'status' => 'draft',
        ]);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('queue connection unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->actingAs($admin)
            ->post(route('broadcasts.start', $campaign))
            ->assertSessionHasErrors('broadcast');

        $this->assertDatabaseHas('wa_campaigns', [
            'id' => $campaign->id,
            'status' => 'failed',
        ]);
    }

    public function test_exhausted_processing_job_marks_campaign_failed_without_raw_exception(): void
    {
        $campaign = WaCampaign::create([
            'name' => 'Failed job',
            'message_template' => 'Test {name}',
            'target_type' => 'all',
            'status' => 'processing',
            'total_recipients' => 1,
        ]);
        $recipient = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '6281200000001',
            'status' => 'pending',
        ]);

        (new ProcessWaCampaign($campaign))->failed(new RuntimeException('secret must not be persisted'));

        $this->assertDatabaseHas('wa_campaigns', [
            'id' => $campaign->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('wa_campaign_recipients', [
            'id' => $recipient->id,
            'status' => 'failed',
            'error_message' => 'Broadcast job gagal diproses; periksa worker WA.',
        ]);
        $this->assertDatabaseMissing('wa_campaign_recipients', [
            'id' => $recipient->id,
            'error_message' => 'secret must not be persisted',
        ]);
    }
}
