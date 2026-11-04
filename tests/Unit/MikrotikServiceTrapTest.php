<?php

namespace Tests\Unit;

use App\Exceptions\RouterOsWriteException;
use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\BillingEnforcementAction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RouterOS\Client;
use RouterOS\Interfaces\ClientInterface;
use RouterOS\Query;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * PR-R1 (Q3): RouterOS !trap on /ppp/secret/set and /ppp/active/remove must
 * surface as an exception (ledger `failed`), and reconnect must verify the
 * target profile exists on the router.
 */
#[Group('blocking-billing')]
class MikrotikServiceTrapTest extends TestCase
{
    use RefreshDatabase;

    private const TRAP = ['after' => ['message' => 'input does not match any value of profile']];

    public function test_isolate_set_profile_trap_throws(): void
    {
        $client = new TrapFakeRouterOsClient([
            '/ppp/secret/set' => self::TRAP,
        ]);
        $service = $this->service($client);

        try {
            $service->isolateUser('trap.user');
            $this->fail('A RouterOS trap on /ppp/secret/set must throw.');
        } catch (RouterOsWriteException $e) {
            $this->assertSame(RouterOsWriteException::ROUTEROS_TRAP, $e->reasonCode);
            $this->assertStringContainsString('input does not match any value of profile', $e->getMessage());
        }
    }

    public function test_reconnect_to_missing_profile_throws_profile_not_found_without_writing(): void
    {
        $this->customer(['pppoe_user' => 'trap.user', 'status' => 'isolated', 'previous_profile' => 'GONE-PROFILE']);
        $client = new TrapFakeRouterOsClient([]);
        $service = $this->service($client);

        try {
            $service->reconnectUser('trap.user', 'default');
            $this->fail('Missing reconnect profile must throw.');
        } catch (RouterOsWriteException $e) {
            $this->assertSame(RouterOsWriteException::PROFILE_NOT_FOUND, $e->reasonCode);
        }

        $this->assertNotContains('/ppp/secret/set', $client->endpoints());
        $this->assertSame('GONE-PROFILE', Customer::query()->where('pppoe_user', 'trap.user')->value('previous_profile'));
    }

    public function test_reconnect_onto_isolation_profile_is_refused_without_writing(): void
    {
        $this->customer(['pppoe_user' => 'trap.user', 'status' => 'isolated', 'previous_profile' => 'ISOLIREBILLING']);
        $client = new TrapFakeRouterOsClient([]);
        $service = $this->service($client);

        try {
            $service->reconnectUser('trap.user', 'default');
            $this->fail('Reconnect onto the isolation profile must throw.');
        } catch (RouterOsWriteException $e) {
            $this->assertSame(RouterOsWriteException::TARGET_IS_ISOLATION_PROFILE, $e->reasonCode);
            $this->assertStringContainsString('reconnect_target_is_isolation_profile', $e->getMessage());
            $this->assertTrue($e->isPermanent());
        }

        $this->assertNotContains('/ppp/secret/set', $client->endpoints());
    }

    public function test_reconnect_job_fails_immediately_for_permanent_profile_problems(): void
    {
        foreach (['GONE-PROFILE', 'ISOLIREBILLING'] as $previous) {
            $router = $this->router();
            $customer = $this->customer([
                'pppoe_user' => 'trap.user', 'status' => 'isolated', 'router_id' => $router->id, 'previous_profile' => $previous,
            ]);
            $job = $this->recordingReconnectJob($customer);
            $radius = Mockery::mock(RadiusUserService::class);
            $radius->shouldNotReceive('syncCustomer');

            $job->handle($this->service(new TrapFakeRouterOsClient([])), $radius);

            $this->assertInstanceOf(RouterOsWriteException::class, $job->failedWith, $previous);
            $this->assertSame('isolated', $customer->refresh()->status);
            Customer::withTrashed()->whereKey($customer->id)->forceDelete();
        }
    }

    public function test_reconnect_job_keeps_retrying_transient_routeros_errors(): void
    {
        $router = $this->router();
        $customer = $this->customer([
            'pppoe_user' => 'trap.user', 'status' => 'isolated', 'router_id' => $router->id, 'previous_profile' => '20MB',
        ]);
        $job = $this->recordingReconnectJob($customer);
        $radius = Mockery::mock(RadiusUserService::class);

        try {
            $job->handle($this->service(new TrapFakeRouterOsClient(['/ppp/secret/set' => self::TRAP])), $radius);
            $this->fail('Transient trap must rethrow so the queue retries.');
        } catch (RouterOsWriteException $e) {
            $this->assertSame(RouterOsWriteException::ROUTEROS_TRAP, $e->reasonCode);
        }

        $this->assertNull($job->failedWith);
    }

    private function recordingReconnectJob(Customer $customer): ReconnectCustomerJob
    {
        return new class($customer, 1) extends ReconnectCustomerJob
        {
            public ?\Throwable $failedWith = null;

            public function fail($exception = null)
            {
                $this->failedWith = $exception;
            }
        };
    }

    public function test_reconnect_happy_path_uses_router_profile_case(): void
    {
        $this->customer(['pppoe_user' => 'trap.user', 'status' => 'isolated', 'previous_profile' => '20MB']);
        $client = new TrapFakeRouterOsClient([]);
        $service = $this->service($client);

        $this->assertTrue($service->reconnectUser('trap.user', 'default'));

        $set = $client->lastQuery('/ppp/secret/set');
        $this->assertNotNull($set);
        $this->assertContains('=profile=20mb', $set->getQuery());
        $this->assertNull(Customer::query()->where('pppoe_user', 'trap.user')->value('previous_profile'));
    }

    public function test_reconnect_kick_trap_throws_but_isolate_kick_stays_best_effort(): void
    {
        $this->customer(['pppoe_user' => 'trap.user', 'status' => 'isolated', 'previous_profile' => '20MB']);
        $responses = [
            '/ppp/active/print' => [['.id' => '*A1', 'name' => 'trap.user']],
            '/ppp/active/remove' => ['after' => ['message' => 'not enough permissions (9)']],
        ];

        $reconnect = $this->service(new TrapFakeRouterOsClient($responses));
        $this->expectExceptionThrown(fn () => $reconnect->reconnectUser('trap.user', 'default'), 'not enough permissions');

        $isolate = $this->service(new TrapFakeRouterOsClient($responses));
        $this->assertTrue($isolate->isolateUser('trap.user'));
    }

    public function test_kick_no_such_item_race_is_not_a_failure(): void
    {
        $this->customer(['pppoe_user' => 'trap.user', 'status' => 'isolated', 'previous_profile' => '20MB']);
        $service = $this->service(new TrapFakeRouterOsClient([
            '/ppp/active/print' => [['.id' => '*A1', 'name' => 'trap.user']],
            '/ppp/active/remove' => ['after' => ['message' => 'no such item']],
        ]));

        $this->assertTrue($service->reconnectUser('trap.user', 'default'));
    }

    public function test_profiles_are_read_once_per_connection(): void
    {
        $client = new TrapFakeRouterOsClient([]);
        $service = $this->service($client);

        $service->isolateUser('trap.user');
        $service->isolateUser('trap.user');

        $this->assertSame(1, count(array_keys($client->endpoints(), '/ppp/profile/print', true)));
    }

    public function test_trap_marks_enforcement_ledger_failed(): void
    {
        Setting::set('billing_isolir_after_days', 3, 'integer', 'billing');
        $router = $this->router();
        $customer = $this->customer([
            'pppoe_user' => 'trap.user',
            'router_id' => $router->id,
            'join_date' => now()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(),
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'due_date' => now()->subDays(10)->toDateString(),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);
        $service = $this->service(new TrapFakeRouterOsClient(['/ppp/secret/set' => self::TRAP]));
        $radius = Mockery::mock(RadiusUserService::class);
        $radius->shouldNotReceive('syncCustomer');

        try {
            (new IsolateCustomerJob($customer, $invoice->id))->handle(
                $service, $radius, app(BillingEligibilityService::class), app(BillingEnforcementLedger::class),
            );
            $this->fail('Trap must fail the job.');
        } catch (RouterOsWriteException) {
            // expected: queue retries / failed()
        }

        $row = BillingEnforcementAction::query()->where('customer_id', $customer->id)->sole();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('input does not match', (string) $row->error);
        $this->assertSame('active', $customer->refresh()->status);
    }

    private function expectExceptionThrown(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail('Expected an exception containing: '.$needle);
        } catch (RouterOsWriteException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    private function service(TrapFakeRouterOsClient $client): MikrotikService
    {
        $service = new class extends MikrotikService
        {
            public ?TrapFakeRouterOsClient $fake = null;

            public function connect(Router $router, array $options = []): self
            {
                $this->router = $router;
                $this->profilesCache = null;
                $this->client = $this->fake;

                return $this;
            }

            public function disconnect(): void
            {
                // keep the fake client for assertions
            }
        };
        $service->fake = $client;
        $service->connect(new Router(['name' => 'Trap Router', 'isolation_profile' => '']));

        return $service;
    }

    private function router(): Router
    {
        return Router::create([
            'name' => 'Trap Router '.uniqid(),
            'ip_address' => '10.40.40.'.rand(1, 254),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
            'radius_enabled' => false,
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-TRAP', 'name' => 'Trap', 'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'TRAP-'.strtoupper(substr(uniqid(), -8)),
            'name' => 'Trap Test', 'phone' => '081234567890', 'address' => 'Test',
            'pppoe_user' => 'trap.'.substr(uniqid(), -8), 'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }
}

/**
 * Minimal RouterOS client double: no socket, canned responses per endpoint.
 * Defaults model a healthy router (profiles exist, secret exists, writes ok).
 */
class TrapFakeRouterOsClient extends Client
{
    /** @var list<Query> */
    public array $queries = [];

    /** @var array<string, mixed> */
    private array $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses + [
            '/ppp/profile/print' => [['name' => 'default'], ['name' => 'isolirebilling'], ['name' => '20mb']],
            '/ppp/secret/print' => [['.id' => '*1', 'name' => 'trap.user', 'profile' => '20MB']],
            '/ppp/secret/set' => [],
            '/ppp/active/print' => [],
            '/ppp/active/remove' => [],
        ];
    }

    public function query($endpoint, ?array $where = null, ?string $operations = null, ?string $tag = null): ClientInterface
    {
        $this->queries[] = $endpoint instanceof Query ? $endpoint : new Query($endpoint);

        return $this;
    }

    public function read(bool $parse = true, array $options = [])
    {
        $last = end($this->queries);

        return $this->responses[$last->getEndpoint()] ?? [];
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        return array_map(fn (Query $q) => (string) $q->getEndpoint(), $this->queries);
    }

    public function lastQuery(string $endpoint): ?Query
    {
        $match = null;
        foreach ($this->queries as $query) {
            if ($query->getEndpoint() === $endpoint) {
                $match = $query;
            }
        }

        return $match;
    }
}
