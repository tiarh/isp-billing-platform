<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Jobs\ProcessPsbAfterMediaOcrJob;
use App\Models\Area;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\EvolutionApiService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\HuaweiPsbProvisioningService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbMessageParser;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * The async OCR continuation (ProcessPsbAfterMediaOcrJob) resolves
 * PsbMediaOcrOrchestrator, which extends the BASE orchestrator. processDraft
 * must still run on the container-bound seam so Gadang (Huawei) drafts take
 * the Huawei path, while every other site keeps the base path unchanged.
 */
#[Group('blocking-psb')]
class PsbAfterMediaOcrGadangHuaweiTest extends TestCase
{
    use RefreshDatabase;

    public function test_follow_up_continuation_for_gadang_draft_takes_huawei_path(): void
    {
        $this->fakeBase();
        $draft = $this->gadangDraft(['waiting_photo' => true]);
        $this->expectHuaweiPreflightOnly();
        $this->noFiberhome();

        $this->runJob($draft, ['mode' => 'follow_up', 'batch_key' => 'b1']);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_WAITING_SN, $draft->status);
        $this->assertSame('Huawei PSB: sn_not_found_on_autofind', $draft->last_error);
    }

    public function test_inbound_continuation_for_gadang_draft_takes_huawei_path(): void
    {
        $this->fakeBase();
        $draft = $this->gadangDraft();
        $this->expectHuaweiPreflightOnly();
        $this->noFiberhome();

        $this->runJob($draft, ['mode' => 'inbound', 'parsed' => [], 'payload' => ['group_jid' => 'ops@g.us']]);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_WAITING_SN, $draft->status);
        $this->assertSame('Huawei PSB: sn_not_found_on_autofind', $draft->last_error);
    }

    public function test_non_gadang_draft_keeps_base_path_and_never_touches_huawei(): void
    {
        $this->fakeBase();
        $draft = $this->fiberhomeDraft(['waiting_photo' => true]);
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldNotReceive('collectAutofind');
        $huawei->shouldNotReceive('executePlan');
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $huawei);
        $this->app->forgetInstance(HuaweiPsbProvisioningService::class);
        $this->mock(OltC300Controller::class, function ($mock): void {
            // Base (FiberHome/ZTE) path: ONU discovery goes through the C300 controller.
            $mock->shouldReceive('listUncfg')->once()->andReturn(response()->json(['onus' => []]));
            $mock->shouldNotReceive('provisionFiberhome');
        });

        $this->runJob($draft, ['mode' => 'follow_up', 'batch_key' => 'b1']);

        $this->assertStringNotContainsString('Huawei', (string) $draft->fresh()->last_error);
    }

    private function fakeBase(): void
    {
        Queue::fake();
        Http::fake();
        config(['psb.olt_lock_wait_seconds' => 0]);
    }

    /** @param array<string, mixed> $continuation */
    private function runJob(PsbDraft $draft, array $continuation): void
    {
        $media = new GadangMediaHarness(
            new EvolutionApiService, new MikrotikService, new PsbMessageParser, new PsbWaMessages,
            new KtpOcrService, new RadiusUserService, app(PsbDocumentGateService::class),
        );

        $job = new ProcessPsbAfterMediaOcrJob($draft->id, $continuation);
        $job->withFakeQueueInteractions();
        $job->handle($media);
        $job->assertNotFailed();
    }

    private function expectHuaweiPreflightOnly(): Mockery\MockInterface
    {
        // HuaweiPsbProvisioningService is final; fake the read-only device layer under it.
        $huawei = Mockery::mock(HuaweiOltOnlyProvisioningService::class);
        $huawei->shouldReceive('collectAutofind')->once()->andReturn(['matches' => []]);
        $huawei->shouldReceive('collectSnapshot')->once()->andReturn(['onus' => []]);
        $huawei->shouldNotReceive('executePlan');
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $huawei);
        $this->app->forgetInstance(HuaweiPsbProvisioningService::class);

        return $huawei;
    }

    private function noFiberhome(): void
    {
        $this->mock(OltC300Controller::class, function ($mock): void {
            $mock->shouldNotReceive('provisionFiberhome');
        });
    }

    /** @param array<string, mixed> $meta */
    private function gadangDraft(array $meta = []): PsbDraft
    {
        $router = new Router(['name' => 'SKYNET-GADANG', 'ip_address' => '198.18.2.16', 'username' => 't', 'password' => Canary::of('t'), 'is_active' => true]);
        $router->id = 511;
        $router->save();
        $olt = new Olt([
            'name' => 'OLT GADANG 3', 'code' => 'OLT-GADANG-3', 'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.4.11', 'router_id' => 511, 'area_id' => null,
        ]);
        $olt->id = 11;
        $olt->save();
        $area = Area::create(['name' => 'Gadang', 'code' => 'GADANG']);
        $package = Package::create([
            'router_id' => 511, 'code' => 'GADANG-200M', 'name' => 'Gadang 200M', 'mikrotik_profile' => 'GADANG-200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);

        return $this->draft([
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'router_id' => 511,
            'site_profile_key' => 'gadang_huawei',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'sn' => 'FHTT0000A03A',
        ], ['area_manual' => $area->id] + $meta);
    }

    /** @param array<string, mixed> $meta */
    private function fiberhomeDraft(array $meta = []): PsbDraft
    {
        $router = new Router(['name' => 'SKYNET-RA-OLT', 'ip_address' => '198.18.2.14', 'username' => 'test', 'password' => Canary::of('test'), 'is_active' => true]);
        $router->id = 513;
        $router->save();
        $olt = new Olt(['name' => 'OLT RA', 'code' => 'OLT-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.4.2', 'router_id' => 513, 'area_id' => null]);
        $olt->id = 1;
        $olt->save();
        $package = Package::create([
            'router_id' => 513, 'code' => 'RA-200M', 'name' => 'RA 200M', 'mikrotik_profile' => '200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);
        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);

        return $this->draft([
            'daerah' => 'Klampok',
            'olt_id' => 1,
            'router_id' => 513,
            'package_id' => $package->id,
            'area_id' => $area->id,
            'sn' => 'FHTTBUSY0001',
        ], ['forced_router_id' => 513, 'area_manual' => $area->id] + $meta);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  array<string, mixed>  $meta
     */
    private function draft(array $attrs, array $meta): PsbDraft
    {
        return PsbDraft::create($attrs + [
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'OCR CONTINUATION FIXTURE',
            'odp' => 'ODP-1',
            'rt' => '1',
            'rw' => '2',
            'tikor' => '-7.1,110.1',
            'pppoe_user' => 'ocruser'.random_int(1000, 9999),
            'secret_at' => now(),
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
            'meta' => $meta,
        ]);
    }
}

class GadangMediaHarness extends PsbMediaOcrOrchestrator
{
    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        return true;
    }
}
