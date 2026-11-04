<?php

namespace Tests\Support\MigrationGolden;

use App\Models\Router;
use App\Services\MikrotikService;
use Tests\Support\PsbGolden\PsbGoldenRecorder;

/**
 * Migration golden-test recording fake of MikrotikService (migration refactor M0).
 *
 * Unlike the PSB fake (one table) it keeps ONE PPP table per router id: a cross-router
 * migration reads the source secret on one router and writes the target secret on another,
 * and a single table would let the target write see the source secret and take another path.
 * Every call is recorded in order with the same event shape as PsbGoldenMikrotikFake.
 * Every password argument is recorded as a masked length.
 */
final class MigrationGoldenMikrotikFake extends MikrotikService
{
    /** @var array<int, array<string, array<string, mixed>>> router id => user => row */
    public array $tables = [];

    /** @var array<int, list<string>> router id => live profile names */
    public array $profiles = [];

    /** @var array<string, bool> usernames whose stored value is read back as stars */
    public array $starPassword = [];

    /** @var string|null when set, addPPPSecret answers this error and stores nothing */
    public ?string $addSecretError = null;

    /** @var bool connect() throws, as an unreachable router does */
    public bool $connectThrows = false;

    /** @var list<int> routers whose rows are the migration source: a copy is compared with them */
    public array $sourceRouterIds = [];

    private int $currentRouterId = 0;

    private string $routerName = '';

    /** yes/no: the value equals the source router's value for the same user. The value itself is never recorded. */
    private function sameAsSource(string $user, string $value): string
    {
        $found = false;
        foreach ($this->sourceRouterIds as $sourceId) {
            $source = $this->tables[$sourceId][$user] ?? null;
            if ($source === null) {
                continue;
            }
            $found = true;
            if (hash_equals((string) $source['password'], $value)) {
                return 'yes';
            }
        }

        return $found ? 'no' : 'no_source_row';
    }

    public function __construct(private PsbGoldenRecorder $rec) {}

    /** Puts one PPP row on a router. The stored value is the caller's; it is never recorded. */
    public function putRow(int $routerId, string $user, string $stored, string $profile, string $local, string $remote, bool $disabled = false): void
    {
        $this->tables[$routerId][$user] = [
            'name' => $user, 'password' => $stored, 'profile' => $profile,
            'local' => $local, 'remote' => $remote, 'comment' => 'seed', 'service' => 'pppoe', 'disabled' => $disabled,
        ];
    }

    /** @param array<string, mixed> $options */
    public function connect(Router $router, array $options = []): self
    {
        $this->currentRouterId = (int) $router->id;
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
        $this->rec->add('mt.getPPPSecret', ['router' => $this->routerName, 'user' => $username]);

        return $this->readRow($username, false);
    }

    public function getPPPSecretWithPassword(string $username): ?array
    {
        $this->rec->add('mt.getPPPSecretWithPassword', ['router' => $this->routerName, 'user' => $username]);

        return $this->readRow($username, true);
    }

    /** @return array<string, mixed>|null */
    private function readRow(string $username, bool $withService): ?array
    {
        $row = $this->tables[$this->currentRouterId][$username] ?? null;
        if ($row === null) {
            return null;
        }
        $keys = array_keys($this->tables[$this->currentRouterId]);
        $out = [
            '.id' => '*'.(array_search($username, $keys, true) + 1),
            'name' => $row['name'],
            'password' => ($this->starPassword[$username] ?? false) ? '********' : $row['password'],
            'profile' => $row['profile'],
            'local-address' => $row['local'],
            'remote-address' => $row['remote'],
            'disabled' => $row['disabled'] ? 'true' : 'false',
        ];
        if ($withService) {
            $out['service'] = $row['service'];
        }

        return $out;
    }

    public function getProfiles(): array
    {
        $this->rec->add('mt.getProfiles', ['router' => $this->routerName]);

        return array_map(fn (string $name): array => ['name' => $name], $this->profiles[$this->currentRouterId] ?? []);
    }

    public function getPPPSecretsWithAddresses(): array
    {
        $rows = $this->tables[$this->currentRouterId] ?? [];
        $this->rec->add('mt.getPPPSecretsWithAddresses', ['router' => $this->routerName, 'count' => count($rows)]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'name' => $row['name'],
                'local_address' => $row['local'],
                'remote_address' => $row['remote'],
                'profile' => $row['profile'],
                'disabled' => $row['disabled'],
            ];
        }

        return $out;
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
            'router' => $this->routerName,
            'user' => $username,
            'password' => '<pw len='.strlen($password).'>',
            'same_as_source' => $this->sameAsSource($username, $password),
            'profile' => $profile,
            'local' => $localAddress,
            'remote' => $remoteAddress,
            'service' => $service,
            'comment' => $comment,
        ]);
        if ($this->addSecretError !== null) {
            return ['success' => false, 'pushed' => false, 'message' => $this->addSecretError, 'code' => 'error'];
        }
        $existed = isset($this->tables[$this->currentRouterId][$username]);
        $this->tables[$this->currentRouterId][$username] = [
            'name' => $username, 'password' => $password, 'profile' => $profile,
            'local' => (string) $localAddress, 'remote' => (string) $remoteAddress,
            'comment' => (string) $comment, 'service' => (string) $service, 'disabled' => false,
        ];

        return [
            'success' => true, 'pushed' => true, 'message' => 'PPPoE secret added',
            'secret_id' => '*1', 'existed' => $existed, 'code' => $existed ? 'updated' : 'added',
        ];
    }

    public function disablePPPSecret(string $username): array
    {
        $this->rec->add('mt.disablePPPSecret', ['router' => $this->routerName, 'user' => $username]);
        if (! isset($this->tables[$this->currentRouterId][$username])) {
            throw new \RuntimeException("Secret '{$username}' tidak ditemukan.");
        }
        $this->tables[$this->currentRouterId][$username]['disabled'] = true;

        return ['success' => true, 'message' => 'Secret source disabled', 'secret_id' => '*1'];
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
            'router' => $this->routerName,
            'old' => $oldUsername,
            'new' => $newUsername,
            'password' => '<pw len='.strlen((string) $password).'>',
            'same_as_source' => $password === null ? 'not_sent' : $this->sameAsSource($oldUsername, $password),
            'profile' => $profile,
            'local' => $localAddress,
            'remote' => $remoteAddress,
            'comment' => $comment,
        ]);
        if (isset($this->tables[$this->currentRouterId][$oldUsername])) {
            $row = $this->tables[$this->currentRouterId][$oldUsername];
            unset($this->tables[$this->currentRouterId][$oldUsername]);
            $row['name'] = $newUsername;
            $row['comment'] = (string) $comment;
            if ($profile !== null) {
                $row['profile'] = $profile;
            }
            $this->tables[$this->currentRouterId][$newUsername] = $row;
        }

        return ['success' => true, 'message' => 'renamed', 'secret_id' => '*1'];
    }

    /** @return array<string, array<string, array<string, string>>> router name => user => state, no passwords */
    public function tablesForSnapshot(array $routerNames): array
    {
        $out = [];
        foreach ($this->tables as $routerId => $rows) {
            $name = $routerNames[$routerId] ?? (string) $routerId;
            foreach ($rows as $user => $row) {
                $out[$name][$user] = [
                    'profile' => $row['profile'],
                    'disabled' => $row['disabled'] ? 'yes' : 'no',
                    'local' => $row['local'],
                    'remote' => $row['remote'],
                    'comment' => $row['comment'],
                    'pw_len' => (string) strlen((string) $row['password']),
                ];
                if (! in_array($routerId, $this->sourceRouterIds, true)) {
                    $out[$name][$user]['same_as_source'] = $this->sameAsSource((string) $user, (string) $row['password']);
                }
            }
            if (isset($out[$name])) {
                ksort($out[$name]);
            }
        }
        ksort($out);

        return $out;
    }
}
