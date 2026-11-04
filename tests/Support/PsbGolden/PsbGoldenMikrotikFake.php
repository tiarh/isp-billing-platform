<?php

namespace Tests\Support\PsbGolden;

use App\Models\Router;
use App\Services\MikrotikService;

/**
 * Golden-test recording fake of MikrotikService (owner decision Q2). Keeps a small
 * in-memory PPP secret table, answers like a router and records every call in order.
 * The code under test (allocator, secret write, verify, rename) runs for real above it.
 */
final class PsbGoldenMikrotikFake extends MikrotikService
{
    /** @var array<string, array{name:string,password:string,profile:string,local:string,remote:string,comment:string,service:string}> */
    public array $secrets = [];

    /** @var list<array<string, mixed>> */
    public array $active = [];

    /** @var array<string, bool> usernames whose password is hidden (read back as stars) */
    public array $starPassword = [];

    /** Additive switches (conductor goldens s18-s22); the defaults keep s01-s17 unchanged. */

    /** @var string|null when set, addPPPSecret answers this error and stores nothing */
    public ?string $addSecretError = null;

    /** @var bool connect() throws, as an unreachable router does */
    public bool $connectThrows = false;

    /** @var bool the secret address table reads empty (no used address to allocate from) */
    public bool $emptyAddressTable = false;

    private string $routerName = '';

    public function __construct(private PsbGoldenRecorder $rec) {}

    public function seed(string $user, string $password, string $profile, string $local, string $remote): void
    {
        $this->secrets[$user] = [
            'name' => $user, 'password' => $password, 'profile' => $profile,
            'local' => $local, 'remote' => $remote, 'comment' => 'seed', 'service' => 'pppoe',
        ];
    }

    /** @param array<string, mixed> $options */
    public function connect(Router $router, array $options = []): self
    {
        $this->routerName = (string) $router->name;
        $this->rec->add('mt.connect', ['router' => $this->routerName, 'options' => $options]);
        if ($this->connectThrows) {
            throw new \RuntimeException('router unreachable');
        }

        return $this;
    }

    public function disconnect(): void
    {
        $this->rec->add('mt.disconnect', ['router' => $this->routerName]);
    }

    public function getPPPSecret(string $username): ?array
    {
        $this->rec->add('mt.getPPPSecret', ['user' => $username]);
        $row = $this->secrets[$username] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            '.id' => '*'.(array_search($username, array_keys($this->secrets), true) + 1),
            'name' => $row['name'],
            'password' => ($this->starPassword[$username] ?? false) ? '********' : $row['password'],
            'profile' => $row['profile'],
            'local-address' => $row['local'],
            'remote-address' => $row['remote'],
        ];
    }

    public function getPPPSecretsWithAddresses(): array
    {
        $this->rec->add('mt.getPPPSecretsWithAddresses', ['count' => $this->emptyAddressTable ? 0 : count($this->secrets)]);
        $out = [];
        if ($this->emptyAddressTable) {
            return $out;
        }
        foreach ($this->secrets as $row) {
            $out[] = [
                'name' => $row['name'],
                'local_address' => $row['local'],
                'remote_address' => $row['remote'],
                'profile' => $row['profile'],
                'disabled' => false,
            ];
        }

        return $out;
    }

    public function getActiveConnections(bool $withMeta = false): array
    {
        $this->rec->add('mt.getActiveConnections', ['withMeta' => $withMeta]);

        return $this->active;
    }

    public function addPPPSecret(
        string $username,
        string $password,
        string $profile,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $service = 'pppoe',
        ?string $comment = null
    ): array {
        $this->rec->add('mt.addPPPSecret', [
            'user' => $username,
            'password' => '<pw len='.strlen($password).'>',
            'profile' => $profile,
            'local' => $localAddress,
            'remote' => $remoteAddress,
            'service' => $service,
            'comment' => $comment,
        ]);
        if ($this->addSecretError !== null) {
            return ['success' => false, 'pushed' => false, 'message' => $this->addSecretError, 'code' => 'error'];
        }
        $existed = isset($this->secrets[$username]);
        $this->secrets[$username] = [
            'name' => $username, 'password' => $password, 'profile' => $profile,
            'local' => (string) $localAddress, 'remote' => (string) $remoteAddress,
            'comment' => (string) $comment, 'service' => (string) $service,
        ];

        return [
            'success' => true, 'pushed' => true, 'message' => 'PPPoE secret added',
            'secret_id' => '*1', 'existed' => $existed, 'code' => $existed ? 'updated' : 'added',
        ];
    }

    public function renamePPPSecret(
        string $oldUsername,
        string $newUsername,
        ?string $password = null,
        ?string $profile = null,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $comment = null
    ): array {
        $this->rec->add('mt.renamePPPSecret', [
            'old' => $oldUsername,
            'new' => $newUsername,
            'password' => '<pw len='.strlen((string) $password).'>',
            'profile' => $profile,
            'local' => $localAddress,
            'remote' => $remoteAddress,
            'comment' => $comment,
        ]);
        if (isset($this->secrets[$oldUsername])) {
            $row = $this->secrets[$oldUsername];
            unset($this->secrets[$oldUsername]);
            $row['name'] = $newUsername;
            $row['comment'] = (string) $comment;
            $this->secrets[$newUsername] = $row;
        }

        return ['success' => true, 'message' => 'renamed', 'secret_id' => '*1'];
    }

    /** @return array<string, array<string, string>> secret table without passwords, sorted by user */
    public function tableForSnapshot(): array
    {
        $out = [];
        foreach ($this->secrets as $user => $row) {
            $out[$user] = [
                'profile' => $row['profile'],
                'local' => $row['local'],
                'remote' => $row['remote'],
                'comment' => $row['comment'],
                'pw_len' => (string) strlen($row['password']),
            ];
        }
        ksort($out);

        return $out;
    }
}
