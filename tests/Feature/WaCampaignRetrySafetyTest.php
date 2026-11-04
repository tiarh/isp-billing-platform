<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaCampaign;
use App\Jobs\SendWaCampaignMessage;
use App\Jobs\SendWaNotificationJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use App\Services\EvolutionApiService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PR-WA (Q5, Q11, Q14): broadcast sends with unknown outcome are not retried,
 * campaign fan-out is chunked/idempotent and skips dismantled/terminated
 * customers, and ticket-closed WA uses one job per message.
 */
#[Group('blocking-billing')]
class WaCampaignRetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_delivery_is_failed_once_and_not_released(): void
    {
        Setting::set('wa_broadcast_writes_enabled', '1', 'boolean', 'whatsapp');
        $campaign = WaCampaign::create([
            'name' => 'Timeout campaign',
            'message_template' => 'Halo {name}',
            'target_type' => 'all',
            'status' => 'processing',
            'total_recipients' => 1,
        ]);
        $recipient = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '081234567890',
            'status' => 'pending',
        ]);

        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('billing')->andReturnSelf();
        $evo->shouldReceive('getConnectionState')->andReturn('open');
        $evo->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'error' => 'Timeout menunggu respons Evolution API — status kirim tidak pasti',
            'delivery_unknown' => true,
        ]);

        $job = (new SendWaCampaignMessage($recipient))->withFakeQueueInteractions();
        $job->handle($evo, app(IndonesianPhoneNormalizer::class));

        $job->assertNotReleased();
        $this->assertSame('unconfirmed', $recipient->fresh()->status);
        $this->assertStringContainsString('tidak pasti', (string) $recipient->fresh()->error_message);
        $this->assertSame(0, (int) $campaign->fresh()->failed_count);
        $this->assertSame(1, (int) $campaign->fresh()->unconfirmed_count);
        // unconfirmed counts toward completion (1 of 1 processed)
        $this->assertSame('completed', $campaign->fresh()->status);
    }

    public function test_connect_failure_is_still_retried(): void
    {
        Setting::set('wa_broadcast_writes_enabled', '1', 'boolean', 'whatsapp');
        $campaign = WaCampaign::create([
            'name' => 'Connect fail campaign',
            'message_template' => 'Halo',
            'target_type' => 'all',
            'status' => 'processing',
            'total_recipients' => 1,
        ]);
        $recipient = WaCampaignRecipient::create([
            'wa_campaign_id' => $campaign->id,
            'phone_number' => '081234567890',
            'status' => 'pending',
        ]);

        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('billing')->andReturnSelf();
        $evo->shouldReceive('getConnectionState')->andReturn('open');
        $evo->shouldReceive('send')->once()->andReturn([
            'success' => false,
            'error' => 'Gagal konek ke Evolution API (pesan belum terkirim)',
            'delivery_unknown' => false,
        ]);

        $job = (new SendWaCampaignMessage($recipient))->withFakeQueueInteractions();
        $job->handle($evo, app(IndonesianPhoneNormalizer::class));

        $job->assertReleased();
        $this->assertSame('pending', $recipient->fresh()->status);
    }

    public function test_campaign_fan_out_skips_terminated_and_is_idempotent(): void
    {
        Setting::set('wa_broadcast_writes_enabled', '0', 'boolean', 'whatsapp');
        $active = $this->customer('WA-ACT', 'active');
        $isolated = $this->customer('WA-ISO', 'isolated');
        $this->customer('WA-TER', 'terminated');

        $campaign = WaCampaign::create([
            'name' => 'All customers',
            'message_template' => 'Halo {name}',
            'target_type' => 'all',
            'status' => 'draft',
        ]);
        $evo = Mockery::mock(EvolutionApiService::class);

        (new ProcessWaCampaign($campaign))->handle($evo, app(IndonesianPhoneNormalizer::class));
        (new ProcessWaCampaign($campaign->fresh()))->handle($evo, app(IndonesianPhoneNormalizer::class));

        $customerIds = WaCampaignRecipient::query()
            ->where('wa_campaign_id', $campaign->id)
            ->pluck('customer_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$active->id, $isolated->id], $customerIds);
        $this->assertSame(2, (int) $campaign->fresh()->total_recipients);
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertSame(1, (new ProcessWaCampaign($campaign))->tries);
        $this->assertContains('terminated', ProcessWaCampaign::EXCLUDED_CUSTOMER_STATUSES);
    }

    public function test_ticket_closed_sends_each_message_in_its_own_job(): void
    {
        Queue::fake();
        $ticket = SupportTicket::create([
            'code' => 'WA-CLOSE-'.uniqid(),
            'type' => 'incident',
            'priority' => 'high',
            'status' => 'open',
            'subject' => 'Closed ticket',
        ]);

        $evo = Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('sendTicketClosedToGroup')->once()->andReturn(['success' => true]);
        $evo->shouldNotReceive('sendTicketClosedReportToGroup');
        $this->app->instance(EvolutionApiService::class, $evo);

        (new SendWaNotificationJob($ticket->code, 'closed'))->handle();

        Queue::assertPushed(SendWaNotificationJob::class, fn (SendWaNotificationJob $job) => $job->type === 'closed_report'
            && $job->ticketCode === $ticket->code);
        $this->assertSame(1, (new SendWaNotificationJob($ticket->code))->tries);
    }

    private function customer(string $code, string $status): Customer
    {
        $area = Area::firstOrCreate(['code' => 'WA-AREA'], ['name' => 'WA Area']);
        $package = Package::create(['code' => $code.'-PKG', 'name' => 'WA Package', 'price' => 100000]);

        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0812345'.random_int(10000, 99999),
            'address' => 'Alamat',
            'pppoe_user' => strtolower($code).'-pppoe',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'status' => $status,
        ]);
    }
}
