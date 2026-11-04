<?php

namespace Tests\Support;

use App\Models\Router;
use App\Services\MikrotikService;

/**
 * MikrotikService yang tersambung ke FakeRouterOsClient (tanpa socket). Semua kode asli
 * (activeSessionFor, kickUser, ...) jalan terhadap tabel palsu.
 */
final class FakeSecretMikrotikService extends MikrotikService
{
    public int $connects = 0;

    public bool $unreachable = false;

    public function __construct(public FakeRouterOsClient $fake) {}

    public function connect(Router $router, array $options = []): self
    {
        $this->connects++;
        if ($this->unreachable) {
            throw new \RuntimeException('connection refused');
        }
        $this->router = $router;
        $this->client = $this->fake;

        return $this;
    }

    public function disconnect(): void
    {
        $this->client = null;
    }
}
