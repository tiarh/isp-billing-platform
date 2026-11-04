<?php

namespace App\Services;

use App\Exceptions\RouterOsWriteException;
use App\Models\Customer;
use App\Models\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;

class MikrotikService
{
    private const PPP_SECRET_PASSWORD_PROPLIST = 'name,password,profile,disabled,service,.id,local-address,remote-address';

    private const PPP_SECRET_PORTAL_PROPLIST = 'name,profile,disabled,service,remote-address';

    protected ?Client $client = null;

    /**
     * PPP profiles of the connected router, cached per connection (Q19) so a
     * batch of isolate/reconnect calls reads /ppp/profile once.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $profilesCache = null;

    protected ?Router $router = null;

    /**
     * Connect to a MikroTik router
     *
     * @param  array  $options  Optional override for connection settings (timeout, attempts)
     */
    public function connect(Router $router, array $options = []): self
    {
        $this->router = $router;
        $this->profilesCache = null;

        try {
            $timeout = $options['timeout'] ?? 10;
            // Force PHP socket timeout to respect our setting (fix for hanging connections)
            ini_set('default_socket_timeout', $timeout);

            // Use VPN address when VPN is enabled and tunnel is available
            $host = $router->ip_address;
            if ($router->vpn_enabled && $router->vpn_address) {
                $vpnIp = Str::before($router->vpn_address, '/');
                if ($vpnIp) {
                    $host = $vpnIp;
                    Log::info("Using VPN address {$vpnIp} for router {$router->name}");
                }
            }

            if (trim((string) $host) === '') {
                throw new \InvalidArgumentException("Router {$router->name} belum memiliki IP address/VPN address yang valid.");
            }

            $config = new Config([
                'host' => $host,
                'user' => $router->username,
                'pass' => $router->password, // Auto-decrypted by Laravel's encrypted cast
                'port' => $router->port,
                'ssl' => $options['ssl'] ?? ((int) $router->port === 8729),
                'timeout' => $timeout, // Connection timeout
                'socket_timeout' => $timeout, // Read/write timeout (THIS WAS THE MISSING PIECE!)
                'attempts' => $options['attempts'] ?? 3,
            ]);

            $this->client = new Client($config);

            Log::info("Successfully connected to router: {$router->name}");
        } catch (\Exception $e) {
            Log::error("Failed to connect to router {$router->name}: {$e->getMessage()}");
            throw $e;
        }

        return $this;
    }

    public function isolateCustomerNow(Customer $customer, int $timeout = 10): void
    {
        if (! $customer->router || ! $customer->pppoe_user) {
            throw new \InvalidArgumentException('Customer must have a router and PPPoE username.');
        }

        try {
            $this->connect($customer->router, ['timeout' => $timeout, 'attempts' => 1]);

            if (! $this->isolateUser($customer->pppoe_user)) {
                throw new \RuntimeException("PPPoE user '{$customer->pppoe_user}' not found on router '{$customer->router->name}'");
            }

            $customer->update([
                'status' => 'isolated',
                'mikrotik_profile' => $this->isolationProfileName(),
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ]);

            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($customer)
                ->withProperties([
                    'router' => $customer->router->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'mode' => 'realtime',
                ])
                ->log('customer_isolated');
        } finally {
            $this->disconnect();
        }
    }

    public function reconnectCustomerNow(Customer $customer, int $timeout = 10): void
    {
        if (! $customer->router || ! $customer->pppoe_user) {
            throw new \InvalidArgumentException('Customer must have a router and PPPoE username.');
        }

        try {
            $this->connect($customer->router, ['timeout' => $timeout, 'attempts' => 1]);

            $fallbackProfile = $customer->package?->mikrotik_profile
                ?: $customer->mikrotik_profile
                ?: 'default';
            $restoredProfile = $this->reconnectProfileName($customer, $fallbackProfile);

            if (! $this->reconnectUser($customer->pppoe_user, $fallbackProfile)) {
                throw new \RuntimeException("PPPoE user '{$customer->pppoe_user}' not found on router '{$customer->router->name}'");
            }

            $customer->update([
                'status' => 'active',
                'mikrotik_profile' => $restoredProfile,
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
                'mikrotik_sync_checked_at' => now(),
            ]);

            activity()
                ->causedBy(auth()->user() ?? null)
                ->performedOn($customer)
                ->withProperties([
                    'router' => $customer->router->name,
                    'pppoe_user' => $customer->pppoe_user,
                    'mode' => 'realtime',
                ])
                ->log('customer_reconnected');
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Get all PPPoE secrets from router
     */
    public function getPPPSecretCount(): int
    {
        $this->ensureConnected();

        try {
            $query = (new Query('/ppp/secret/print'));
            $query->operations('count-only');
            $response = $this->client->query($query)->read();

            if (isset($response['after']['ret'])) {
                return (int) $response['after']['ret'];
            }

            foreach ($response as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['ret'])) {
                    return (int) $row['ret'];
                }
            }
        } catch (\Exception $e) {
            Log::warning("PPP secret count-only failed on {$this->router->name}: {$e->getMessage()}");
        }

        return count($this->readQueryRows(
            (new Query('/ppp/secret/print'))->equal('.proplist', '.id')
        ));
    }

    /** Count only local PPPoE secrets; VPN/non-PPPoE secrets are out of scope. */
    public function getPppoeSecretCount(): int
    {
        $this->ensureConnected();

        try {
            $query = (new Query('/ppp/secret/print'))
                ->where('service', 'pppoe');
            $query->operations('count-only');
            $response = $this->client->query($query)->read();

            if (isset($response['after']['ret'])) {
                return (int) $response['after']['ret'];
            }

            foreach ($response as $row) {
                if (is_array($row) && isset($row['ret'])) {
                    return (int) $row['ret'];
                }
            }
        } catch (\Exception $e) {
            Log::warning("PPPoE secret count-only failed on {$this->router->name}: {$e->getMessage()}");
        }

        return count($this->readQueryRows(
            (new Query('/ppp/secret/print'))
                ->equal('.proplist', '.id')
                ->where('service', 'pppoe')
        ));
    }

    /**
     * Read the RouterOS PPP authentication source without changing device state.
     *
     * Password reconciliation is valid only for local PPP secrets.  The caller
     * must treat an unreadable/empty response as blocked; this method deliberately
     * returns only normalized source metadata.
     *
     * @return array{readable: bool, use_radius: bool, source: string, rows: int}
     */
    public function getPppAuthenticationSource(): array
    {
        $this->ensureConnected();

        $rows = $this->readQueryRows(
            (new Query('/ppp/aaa/print'))->equal('.proplist', 'use-radius')
        );

        if ($rows === []) {
            return [
                'readable' => false,
                'use_radius' => false,
                'source' => 'unknown',
                'rows' => 0,
            ];
        }

        $useRadius = false;
        foreach ($rows as $row) {
            $value = strtolower(trim((string) ($row['use-radius'] ?? '')));
            if (in_array($value, ['yes', 'true', '1'], true)) {
                $useRadius = true;
                break;
            }
        }

        return [
            'readable' => true,
            'use_radius' => $useRadius,
            'source' => $useRadius ? 'radius' : 'mikrotik_local',
            'rows' => count($rows),
        ];
    }

    /**
     * PPP secrets with local/remote address for Auto-PPPoE IP allocation.
     * Lightweight proplist only — sorted/validated by caller.
     *
     * @return list<array{name:string,local_address:string,remote_address:string,profile:string,disabled:bool}>
     */
    public function getPPPSecretsWithAddresses(): array
    {
        $this->ensureConnected();

        // Tiny proplist; single strategy — avoid double-fetch storms on large pools.
        // Caller (Auto PPPoE) only invokes this on explicit Refresh (cache 10m).
        $proplist = 'name,profile,disabled,local-address,remote-address';
        $expected = (int) ($this->router->total_pppoe_count ?? 0);

        try {
            if ($expected > 150) {
                // Large: disabled-split only (lighter than bulk+retry on Krian-class)
                $rows = $this->fetchPPPSecretsByDisabled($proplist, $expected, []);
            } else {
                $rows = $this->fetchPPPSecretsBulk($proplist, $expected);
            }
        } catch (\Exception $e) {
            Log::warning("getPPPSecretsWithAddresses failed on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $remote = $this->normalizeIpAddressField((string) ($row['remote-address'] ?? ''));
            $local = $this->normalizeIpAddressField((string) ($row['local-address'] ?? ''));
            $out[] = [
                'name' => $name,
                'local_address' => $local,
                'remote_address' => $remote,
                'profile' => (string) ($row['profile'] ?? ''),
                'disabled' => in_array(strtolower((string) ($row['disabled'] ?? 'false')), ['true', 'yes', '1'], true),
            ];
        }

        return $out;
    }

    /** Strip pool names / CIDR → IPv4 or empty */
    protected function normalizeIpAddressField(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // MikroTik sometimes stores pool name (not IP) on secret remote-address
        $value = preg_replace('#/\d+$#', '', $value) ?? $value;
        $value = preg_replace('/\s+.*/', '', $value) ?? $value;

        return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $value : '';
    }

    /**
     * Fast PPP secret pull — one strategy only, minimal proplist, no count-only, no profile-chunk.
     *
     * Hot path target: <15s for ~450 secrets (Krian).
     * proplist name,profile,disabled only (smallest useful set for eBilling mapping).
     * Large routers: single bulk first; if incomplete, one disabled-split — then stop.
     */
    public function getPPPSecrets(): array
    {
        $this->ensureConnected();

        try {
            $expected = (int) ($this->router->total_pppoe_count ?? 0);
            // Tiny proplist = less MikroTik CPU + smaller API frames.
            $proplist = 'name,profile,disabled';

            // Large routers: disabled-split first (bulk often stream-timeouts).
            // Measured Krian: secrets_ms≈3.5s, fullSync≈5.5s.
            if ($expected > 120) {
                $response = $this->fetchPPPSecretsByDisabled($proplist, $expected, []);
                // If short vs cache, one bulk merge (no profile-chunk storm).
                if ($expected > 0 && count($response) < (int) floor($expected * 0.85)) {
                    $response = $this->mergeSecretRows(
                        $response,
                        $this->fetchPPPSecretsBulk($proplist, $expected)
                    );
                }
            } else {
                $response = $this->fetchPPPSecretsBulk($proplist, $expected);
                if ($expected > 0 && count($response) < (int) floor($expected * 0.85)) {
                    $response = $this->fetchPPPSecretsByDisabled($proplist, $expected, $response);
                }
            }

            $got = count($response);
            Log::info("Retrieved {$got} PPP secrets from {$this->router->name}".($expected > 0 ? " (expected {$expected})" : ''));

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get PPP secrets from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Read all PPP secrets including passwords for an explicitly approved
     * internal reconciliation flow.  This method never logs or serializes the
     * returned password values; callers must keep them in memory only.
     *
     * @return list<array<string, mixed>>
     */
    public function getPPPSecretsWithPasswords(): array
    {
        $this->ensureConnected();

        $expected = $this->getPPPSecretCount();
        $proplist = self::PPP_SECRET_PASSWORD_PROPLIST;

        if ($expected > 120) {
            $rows = $this->fetchPPPSecretsByDisabled($proplist, $expected, []);
            if ($expected > 0 && count($rows) < $expected) {
                $rows = $this->mergeSecretRows($rows, $this->fetchPPPSecretsBulk($proplist, $expected));
            }
        } else {
            $rows = $this->fetchPPPSecretsBulk($proplist, $expected);
            if ($expected > 0 && count($rows) < $expected) {
                $rows = $this->mergeSecretRows(
                    $rows,
                    $this->fetchPPPSecretsByDisabled($proplist, $expected, $rows)
                );
            }
        }

        return $rows;
    }

    /**
     * Read only local PPPoE secrets with passwords for password reconciliation.
     * Non-PPPoE services (VPN, OVPN, L2TP, etc.) are intentionally excluded at
     * the RouterOS query boundary so they cannot affect completeness checks.
     *
     * @return list<array<string, mixed>>
     */
    public function getPppoeSecretsWithPasswords(): array
    {
        $this->ensureConnected();

        $expected = $this->getPppoeSecretCount();
        $proplist = self::PPP_SECRET_PASSWORD_PROPLIST;

        if ($expected > 120) {
            $rows = $this->fetchPPPSecretsByDisabled($proplist, $expected, [], 'pppoe');
            if ($expected > 0 && count($rows) < $expected) {
                $rows = $this->mergeSecretRows($rows, $this->fetchPPPSecretsBulk($proplist, $expected, 'pppoe'));
            }
        } else {
            $rows = $this->fetchPPPSecretsBulk($proplist, $expected, 'pppoe');
            if ($expected > 0 && count($rows) < $expected) {
                $rows = $this->mergeSecretRows(
                    $rows,
                    $this->fetchPPPSecretsByDisabled($proplist, $expected, $rows, 'pppoe')
                );
            }
        }

        return $rows;
    }

    /**
     * Read one PPP secret with an explicit password proplist.
     *
     * @return array<string, mixed>|null
     */
    public function getPPPSecretWithPassword(string $username): ?array
    {
        $this->ensureConnected();

        $rows = $this->readQueryRows(
            (new Query('/ppp/secret/print'))
                ->equal('.proplist', self::PPP_SECRET_PASSWORD_PROPLIST)
                ->where('name', $username)
        );

        return $rows[0] ?? null;
    }

    /**
     * RCA-4 "Cek sekarang": the ONE active PPPoE session of a user (`/ppp/active/print ?name=`), read-only.
     *
     * @return array{name?:string,uptime?:string,'caller-id'?:string,address?:string}|null
     */
    public function activeSessionFor(string $username): ?array
    {
        $this->ensureConnected();

        $rows = $this->readQueryRows(
            (new Query('/ppp/active/print'))
                ->equal('.proplist', 'name,uptime,caller-id,address')
                ->where('name', $username)
        );

        return $rows[0] ?? null;
    }

    /**
     * RCA-4 "Cek sekarang": whether the PPP secret is disabled and its profile (`/ppp/secret/print ?name=`),
     * read-only and WITHOUT the password. RouterOS v7 also answers when the user last logged out and why
     * (`last-logged-out`, `last-disconnect-reason`, router local time); v6 simply leaves them out.
     *
     * @return array{disabled?:string,profile?:string,'last-logged-out'?:string,'last-disconnect-reason'?:string}|null
     */
    public function secretStateFor(string $username): ?array
    {
        $this->ensureConnected();

        $rows = $this->readQueryRows(
            (new Query('/ppp/secret/print'))
                ->equal('.proplist', 'disabled,profile,last-logged-out,last-disconnect-reason')
                ->where('name', $username)
        );

        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function readQueryRows(Query $query): array
    {
        $response = $this->client->query($query)->read();

        return is_array($response) ? array_values(array_filter($response, 'is_array')) : [];
    }

    /**
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    protected function mergeSecretRows(array $seed, array $chunk): array
    {
        $seen = [];
        $merged = [];

        foreach (array_merge($seed, $chunk) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = (string) ($row['name'] ?? $row['.id'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $row;
        }

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchPPPSecretsBulk(string $proplist, int $expected = 0, ?string $service = null): array
    {
        $query = (new Query('/ppp/secret/print'))
            ->equal('.proplist', $proplist);
        if ($service !== null) {
            $query->where('service', $service);
        }

        $response = $this->readQueryRows($query);
        $got = count($response);

        // One cheap retry only if clearly truncated (no artificial sleep — hangs MikroTik queue).
        if ($expected > 0 && $got > 0 && $got < (int) floor($expected * 0.9)) {
            $retry = $this->readQueryRows($query);
            if (count($retry) > $got) {
                return $retry;
            }
        }

        return $response;
    }

    /**
     * Two-query split: enabled vs disabled secrets (often completes when single bulk truncates).
     *
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    protected function fetchPPPSecretsByDisabled(string $proplist, int $expected, array $seed = [], ?string $service = null): array
    {
        $merged = $seed;

        foreach (['no', 'yes'] as $disabledFlag) {
            try {
                $query = (new Query('/ppp/secret/print'))
                    ->equal('.proplist', $proplist)
                    ->where('disabled', $disabledFlag);
                if ($service !== null) {
                    $query->where('service', $service);
                }
                $merged = $this->mergeSecretRows($merged, $this->readQueryRows($query));
            } catch (\Exception $e) {
                Log::warning("PPP secret disabled={$disabledFlag} chunk failed on {$this->router->name}: {$e->getMessage()}");
            }

            if ($expected > 0 && count($merged) >= $expected) {
                break;
            }
        }

        return $merged;
    }

    /**
     * Last-resort: fetch per PPP profile (many round-trips — avoid on hot path).
     *
     * @param  list<array<string, mixed>>  $seed
     * @return list<array<string, mixed>>
     */
    protected function fetchPPPSecretsByProfile(string $proplist, int $expected, array $seed = []): array
    {
        $merged = $seed;

        $profileNames = collect($this->getProfiles())
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();

        if ($profileNames === []) {
            return $this->mergeSecretRows($merged, $this->fetchPPPSecretsBulk($proplist, $expected));
        }

        foreach ($profileNames as $profileName) {
            try {
                $query = (new Query('/ppp/secret/print'))
                    ->equal('.proplist', $proplist)
                    ->where('profile', $profileName);
                $merged = $this->mergeSecretRows($merged, $this->readQueryRows($query));
            } catch (\Exception $e) {
                Log::warning("PPP secret profile chunk failed on {$this->router->name} profile={$profileName}: {$e->getMessage()}");

                continue;
            }

            if ($expected > 0 && count($merged) >= $expected) {
                break;
            }
        }

        return $merged;
    }

    /**
     * Get a specific PPPoE secret by username
     */
    public function getPPPSecret(string $username): ?array
    {
        $this->ensureConnected();

        try {
            return $this->findPPPSecret($username);
        } catch (\Exception $e) {
            Log::error("Failed to get PPP secret for {$username}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Read the PPP secret assigned to an IP without reading or writing a
     * password. Active routers are checked in ID order and the first matching
     * secret is returned.
     *
     * @return array{name:string,profile:string,remote_address:string}|null
     */
    public function findPPPSecretByIp(string $ip): ?array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $remoteAddresses = [$ip];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $remoteAddresses[] = $ip.'/32';
        }

        foreach (Router::query()->where('is_active', true)->orderBy('id')->get() as $router) {
            try {
                $this->connect($router, ['timeout' => 5, 'attempts' => 1]);

                foreach ($remoteAddresses as $remoteAddress) {
                    $rows = $this->readQueryRows(
                        (new Query('/ppp/secret/print'))
                            ->equal('.proplist', self::PPP_SECRET_PORTAL_PROPLIST)
                            ->where('service', 'pppoe')
                            ->where('remote-address', $remoteAddress)
                    );

                    $secret = $rows[0] ?? null;
                    if (! is_array($secret)) {
                        continue;
                    }

                    return [
                        'name' => trim((string) ($secret['name'] ?? '')),
                        'profile' => trim((string) ($secret['profile'] ?? '')),
                        'remote_address' => trim((string) ($secret['remote-address'] ?? $remoteAddress)),
                    ];
                }
            } catch (\Throwable $exception) {
                Log::warning('Isolation portal PPP secret lookup failed.', [
                    'router_id' => $router->getKey(),
                    'error' => get_class($exception),
                ]);
            } finally {
                $this->disconnect();
            }
        }

        return null;
    }

    /**
     * Rename/update one PPPoE secret and verify the new name exists.
     *
     * @return array{success: bool, message: string, secret_id: ?string}
     */
    public function renamePPPSecret(
        string $oldUsername,
        string $newUsername,
        ?string $password = null,
        ?string $profile = null,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $comment = null
    ): array {
        $this->ensureConnected();

        $oldUsername = trim($oldUsername);
        $newUsername = trim($newUsername);
        if ($oldUsername === '' || $newUsername === '') {
            throw new \InvalidArgumentException('Username lama/baru wajib diisi.');
        }

        $sameUsername = strcasecmp($oldUsername, $newUsername) === 0;
        $existingNew = $this->findPPPSecret($newUsername);
        if ($existingNew && ! $sameUsername) {
            if ($this->findPPPSecret($oldUsername)) {
                throw new \RuntimeException("Secret target '{$newUsername}' sudah ada, sementara secret lama '{$oldUsername}' juga masih ada.");
            }

            return ['success' => true, 'message' => 'Secret baru sudah ada', 'secret_id' => $existingNew['.id'] ?? null];
        }

        $secret = $sameUsername ? $existingNew : $this->findPPPSecret($oldUsername);
        if (! $secret || empty($secret['.id'])) {
            throw new \RuntimeException("Secret lama '{$oldUsername}' tidak ditemukan.");
        }

        $set = (new Query('/ppp/secret/set'))
            ->equal('.id', $secret['.id'])
            ->equal('name', $newUsername);
        if ($password !== null && trim($password) !== '') {
            $set->equal('password', $password);
        }
        if ($profile !== null && trim($profile) !== '') {
            $set->equal('profile', $profile);
        }
        if ($localAddress !== null && trim($localAddress) !== '') {
            $set->equal('local-address', $localAddress);
        }
        if ($remoteAddress !== null && trim($remoteAddress) !== '') {
            $set->equal('remote-address', $remoteAddress);
        }
        if ($comment !== null && trim($comment) !== '') {
            $set->equal('comment', $comment);
        }

        $response = $this->client->query($set)->read();
        if ($err = $this->routerOsErrorMessage($response)) {
            throw new \RuntimeException($err);
        }

        $verified = $this->findPPPSecret($newUsername);
        if (! $verified) {
            throw new \RuntimeException("Secret '{$newUsername}' belum terverifikasi setelah rename.");
        }

        return ['success' => true, 'message' => 'Secret renamed', 'secret_id' => $verified['.id'] ?? ($secret['.id'] ?? null)];
    }

    /**
     * Disable one PPPoE secret after a verified cross-router copy.
     *
     * The source secret is deliberately disabled, not deleted, so an operator
     * can roll back without reconstructing the old password/IP information.
     *
     * @return array{success: bool, message: string, secret_id: ?string}
     */
    public function disablePPPSecret(string $username): array
    {
        $this->ensureConnected();

        $username = trim($username);
        if ($username === '') {
            throw new \InvalidArgumentException('Username PPPoE wajib diisi.');
        }

        $secret = $this->findPPPSecret($username);
        if (! $secret || empty($secret['.id'])) {
            throw new \RuntimeException("Secret '{$username}' tidak ditemukan.");
        }

        if (in_array(strtolower((string) ($secret['disabled'] ?? 'false')), ['true', 'yes', '1'], true)) {
            return [
                'success' => true,
                'message' => 'Secret source sudah disabled',
                'secret_id' => $secret['.id'],
            ];
        }

        $response = $this->client->query(
            (new Query('/ppp/secret/set'))
                ->equal('.id', $secret['.id'])
                ->equal('disabled', 'yes')
        )->read();
        if ($err = $this->routerOsErrorMessage($response)) {
            throw new \RuntimeException($err);
        }

        $verified = $this->findPPPSecret($username);
        if (! $verified || ! in_array(strtolower((string) ($verified['disabled'] ?? 'false')), ['true', 'yes', '1'], true)) {
            throw new \RuntimeException("Secret '{$username}' belum terverifikasi disabled.");
        }

        return [
            'success' => true,
            'message' => 'Secret source disabled',
            'secret_id' => $verified['.id'] ?? $secret['.id'],
        ];
    }

    /**
     * Get all PPP profiles from router
     */
    public function getProfiles(): array
    {
        $this->ensureConnected();

        try {
            $query = (new Query('/ppp/profile/print'))
                ->equal('.proplist', 'name,rate-limit,local-address,remote-address,only-one');
            $response = $this->readQueryRows($query);

            Log::info('Retrieved '.count($response)." PPP profiles from {$this->router->name}");

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get PPP profiles from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Create or update the PPP profile used for customer isolation.
     */
    public function ensureIsolationProfile(string $name, ?string $rateLimit = null, ?string $localAddress = null, ?string $remoteAddress = null): array
    {
        $this->ensureConnected();

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Isolation profile name is required.');
        }

        $this->profilesCache = null;
        $originalLocalAddress = $this->normalizeOptionalValue($localAddress);
        $localAddress = $this->normalizeOptionalValue($localAddress);
        $remoteAddress = $this->normalizeOptionalValue($remoteAddress);
        $profileLocalAddress = $localAddress ? Str::before($localAddress, '/') : null;

        if ($localAddress && $remoteAddress && $this->shouldCreateAddressPool($localAddress, $remoteAddress)) {
            $range = $this->deriveAddressPoolRange($localAddress);

            if (! $range) {
                throw new \InvalidArgumentException('Unable to derive an IP pool range from the local address.');
            }

            $this->ensureAddressPool($remoteAddress, $range);
        }

        $query = (new Query('/ppp/profile/print'))
            ->where('name', $name);
        $profiles = $this->client->query($query)->read();
        $profile = $profiles[0] ?? null;

        $payload = [
            'name' => $name,
            'rate-limit' => $rateLimit,
            'local-address' => $profileLocalAddress,
            'remote-address' => $remoteAddress,
        ];

        $payload = array_filter(
            $payload,
            fn ($value) => $value !== null && trim((string) $value) !== ''
        );

        if ($profile && isset($profile['.id'])) {
            $setQuery = (new Query('/ppp/profile/set'))
                ->equal('.id', $profile['.id']);

            foreach ($payload as $key => $value) {
                $setQuery->equal($key, (string) $value);
            }

            $response = $this->client->query($setQuery)->read();
            Log::info("Updated isolation profile {$name} on {$this->router->name}", [
                'payload' => $payload,
                'response' => $response,
            ]);

            return $this->verifiedIsolationProfileResult($name, false, $originalLocalAddress, $remoteAddress);
        }

        $addQuery = new Query('/ppp/profile/add');

        foreach ($payload as $key => $value) {
            $addQuery->equal($key, (string) $value);
        }

        $response = $this->client->query($addQuery)->read();
        Log::info("Created isolation profile {$name} on {$this->router->name}", [
            'payload' => $payload,
            'response' => $response,
        ]);

        return $this->verifiedIsolationProfileResult($name, true, $originalLocalAddress, $remoteAddress);
    }

    public function deleteIsolationProfile(string $name): array
    {
        $this->ensureConnected();
        $this->profilesCache = null;

        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Isolation profile name is required.');
        }

        $query = (new Query('/ppp/profile/print'))
            ->where('name', $name);
        $profiles = $this->client->query($query)->read();
        $profile = $profiles[0] ?? null;

        if (! $profile || ! isset($profile['.id'])) {
            return ['deleted' => false, 'name' => $name];
        }

        $removeQuery = (new Query('/ppp/profile/remove'))
            ->equal('.id', $profile['.id']);
        $this->client->query($removeQuery)->read();

        Log::info("Deleted isolation profile {$name} on {$this->router->name}");

        return ['deleted' => true, 'name' => $name];
    }

    /**
     * Count unique PPPoE usernames from active session rows (ignores duplicate sessions).
     */
    public function countUniqueActiveSessions(array $activeConnections): int
    {
        $names = array_filter(array_column($activeConnections, 'name'));

        return count(array_unique($names));
    }

    /**
     * Lightest LIVE metric: RouterOS count-only on /ppp/active (no row dump).
     * Prefer this for UI Online number — minimal MikroTik CPU/API payload.
     *
     * @param  bool  $stable  true = max of 2 samples (Krian-class flaky under-count)
     */
    public function countActiveConnectionsOnly(bool $stable = false): int
    {
        $this->ensureConnected();

        $readOnce = function (): int {
            $query = (new Query('/ppp/active/print'));
            $query->operations('count-only');
            $response = $this->client->query($query)->read();

            if (isset($response['after']['ret'])) {
                return (int) $response['after']['ret'];
            }

            foreach ($response as $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['ret'])) {
                    return (int) $row['ret'];
                }
                // Some builds return {"ret":"N"} as sole element without nested after.
                if (isset($row['=ret'])) {
                    return (int) $row['=ret'];
                }
            }

            // Some RouterOS builds return empty body + ret elsewhere — fall back name list.
            return $this->countUniqueActiveSessions($this->getActiveConnections(false));
        };

        try {
            $first = $readOnce();
            if (! $stable) {
                return $first;
            }

            usleep(120_000);
            $second = $readOnce();

            return max($first, $second);
        } catch (\Exception $e) {
            Log::warning("PPP active count-only failed on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Get active PPPoE connections.
     *
     * @param  bool  $withMeta  false = proplist name only (LIVE); true = +address/uptime (READ)
     */
    public function getActiveConnections(bool $withMeta = false): array
    {
        $this->ensureConnected();

        try {
            // name-only is enough for is_online flags; avoids heavy stream on Krian-class.
            $proplist = $withMeta
                ? 'name,address,uptime,caller-id,service'
                : 'name';
            $query = (new Query('/ppp/active/print'))
                ->equal('.proplist', $proplist);
            $response = $this->readQueryRows($query);

            Log::info('Retrieved '.count($response)." active PPP connections from {$this->router->name} (proplist={$proplist})");

            return $response;
        } catch (\Exception $e) {
            Log::error("Failed to get active connections from {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Isolate a user (block internet access)
     * Method: Change PPPoE profile to 'isolirebilling' (case-insensitive)
     */
    public function isolateUser(string $username): bool
    {
        $this->ensureConnected();
        $isolationProfile = $this->isolationProfileName();

        try {
            // Get all available profiles to find case-insensitive match
            $matchedProfile = $this->matchProfileName($isolationProfile);

            if (! $matchedProfile) {
                throw new \Exception("Isolation profile '{$isolationProfile}' not found on router {$this->router->name}");
            }

            // Find the PPP secret
            $secret = $this->findPPPSecret($username);

            if (! $secret) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");

                return false;
            }

            $currentProfile = $secret['profile'] ?? 'default';

            // Save previous profile if not already isolated
            if (strcasecmp($currentProfile, $isolationProfile) !== 0) {
                $customer = Customer::ebilling()->where('pppoe_user', $username)->first();
                if ($customer) {
                    $customer->update(['previous_profile' => $currentProfile]);
                }
            }

            // Change profile to isolation profile (using the exact case from router)
            $this->setPPPSecretProfile($secret, $matchedProfile);

            // Kick active session if any
            $this->kickUser($username);

            Log::info("Successfully isolated user: {$username} on {$this->router->name} (Profile: {$matchedProfile})");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to isolate user {$username} on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Reconnect a user (restore internet access)
     * Method: Change PPPoE profile back to saved previous profile
     */
    public function reconnectUser(string $username, string $profile = 'default'): bool
    {
        $this->ensureConnected();

        try {
            // Find the PPP secret
            $secret = $this->findPPPSecret($username);

            if (! $secret) {
                Log::warning("PPP secret not found for user: {$username} on {$this->router->name}");

                return false;
            }

            $customer = Customer::ebilling()->with('package')->where('pppoe_user', $username)->first();
            $targetProfile = $customer
                ? $this->reconnectProfileName($customer, $profile)
                : $profile;

            // PR-R1 (Q3): the target profile must exist on THIS router. Setting a
            // missing profile is rejected by RouterOS and used to be swallowed,
            // leaving DB=active while the router kept the isolation profile.
            $matchedProfile = $this->matchProfileName($targetProfile);
            if ($matchedProfile === null) {
                throw new RouterOsWriteException(
                    "Reconnect profile '{$targetProfile}' not found on router {$this->router->name}",
                    RouterOsWriteException::PROFILE_NOT_FOUND,
                );
            }
            // Never "reconnect" a customer onto the isolation profile (the
            // fallback customer.mikrotik_profile can still be the isolir one).
            if (strcasecmp($matchedProfile, $this->isolationProfileName()) === 0) {
                throw new RouterOsWriteException(
                    "reconnect_target_is_isolation_profile: '{$matchedProfile}' for {$username} on router {$this->router->name}",
                    RouterOsWriteException::TARGET_IS_ISOLATION_PROFILE,
                );
            }
            $targetProfile = $matchedProfile;

            if ($customer && ! empty($customer->previous_profile)) {
                Log::info("Restoring {$username} to previous profile: {$targetProfile}");
            }

            // Restore profile (exact case from the router)
            $this->setPPPSecretProfile($secret, $targetProfile);

            // Kick active session to force new profile
            $this->kickUser($username, true);

            if ($customer && ! empty($customer->previous_profile)) {
                $customer->update(['previous_profile' => null]);
            }

            Log::info("Successfully reconnected user: {$username} on {$this->router->name} to {$targetProfile}");

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to reconnect user {$username} on {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Kick an active PPPoE session
     */
    public function kickUser(string $username, bool $failOnError = false): void
    {
        try {
            $query = (new Query('/ppp/active/print'))
                ->where('name', $username);

            $active = $this->client->query($query)->read();

            if (! empty($active)) {
                $session = $active[0];

                $query = (new Query('/ppp/active/remove'))
                    ->equal('.id', $session['.id']);

                $response = $this->client->query($query)->read();
                $error = $this->routerOsErrorMessage($response);
                if ($error !== null) {
                    // The session ended between print and remove: nothing to kick.
                    if (stripos($error, 'no such item') !== false) {
                        Log::info("Active session for {$username} already gone on {$this->router->name}");

                        return;
                    }

                    throw new RouterOsWriteException("RouterOS rejected /ppp/active/remove for {$username} on {$this->router->name}: {$error}");
                }

                Log::info("Kicked active session for user: {$username} on {$this->router->name}");
            }
        } catch (\Exception $e) {
            Log::warning("Could not kick user {$username}: {$e->getMessage()}");

            if ($failOnError) {
                throw $e;
            }
        }
    }

    protected function ensureConnected(): void
    {
        if (! $this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }
    }

    protected function isolationProfileName(): string
    {
        $configuredProfile = trim((string) ($this->router?->isolation_profile ?? ''));

        return $configuredProfile !== '' ? $configuredProfile : 'isolirebilling';
    }

    protected function reconnectProfileName(Customer $customer, string $fallbackProfile = 'default'): string
    {
        return $customer->previous_profile
            ?: $customer->package?->mikrotik_profile
            ?: $customer->mikrotik_profile
            ?: $fallbackProfile;
    }

    protected function matchProfileName(string $profileName): ?string
    {
        $this->profilesCache ??= $this->getProfiles();

        foreach ($this->profilesCache as $profile) {
            if (isset($profile['name']) && strcasecmp($profile['name'], $profileName) === 0) {
                return $profile['name'];
            }
        }

        return null;
    }

    protected function findPPPSecret(string $username): ?array
    {
        $query = (new Query('/ppp/secret/print'))
            ->equal('.proplist', self::PPP_SECRET_PASSWORD_PROPLIST)
            ->where('name', $username);

        $secrets = $this->client->query($query)->read();

        return $secrets[0] ?? null;
    }

    protected function setPPPSecretProfile(array $secret, string $profile): void
    {
        $query = (new Query('/ppp/secret/set'))
            ->equal('.id', $secret['.id'])
            ->equal('profile', $profile);

        // PR-R1 (Q3): surface !trap instead of treating it as success.
        $response = $this->client->query($query)->read();
        $error = $this->routerOsErrorMessage($response);
        if ($error !== null) {
            $name = $secret['name'] ?? $secret['.id'];
            throw new RouterOsWriteException("RouterOS rejected profile '{$profile}' for PPP secret {$name} on {$this->router?->name}: {$error}");
        }
    }

    protected function normalizeOptionalValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    protected function shouldCreateAddressPool(string $localAddress, string $remoteAddress): bool
    {
        if (! filter_var(Str::before($localAddress, '/'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        return ! str_contains($remoteAddress, '-')
            && ! str_contains($remoteAddress, ',')
            && ! str_contains($remoteAddress, '/');
    }

    protected function deriveAddressPoolRange(string $localAddress): ?string
    {
        [$ip, $prefix] = array_pad(explode('/', $localAddress, 2), 2, null);
        $prefix = $prefix !== null ? (int) $prefix : 24;

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 1 || $prefix > 30) {
            return null;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return null;
        }

        $mask = (-1 << (32 - $prefix)) & 0xFFFFFFFF;
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        $firstHost = $network + 1;
        $lastHost = $broadcast - 1;

        if ($firstHost > $lastHost) {
            return null;
        }

        $ranges = [];
        if ($ipLong > $firstHost) {
            $ranges[] = long2ip($firstHost).'-'.long2ip($ipLong - 1);
        }
        if ($ipLong < $lastHost) {
            $ranges[] = long2ip($ipLong + 1).'-'.long2ip($lastHost);
        }

        return empty($ranges) ? null : implode(',', $ranges);
    }

    protected function ensureAddressPool(string $poolName, string $range): void
    {
        $existingQuery = (new Query('/ip/pool/print'))
            ->where('name', $poolName);
        $pools = $this->client->query($existingQuery)->read();

        if (! empty($pools)) {
            return;
        }

        $addQuery = (new Query('/ip/pool/add'))
            ->equal('name', $poolName)
            ->equal('ranges', $range);

        $response = $this->client->query($addQuery)->read();
        Log::info("Created address pool {$poolName} ({$range}) on {$this->router->name}", [
            'response' => $response,
        ]);
    }

    protected function verifiedIsolationProfileResult(string $name, bool $created, ?string $requestedLocalAddress = null, ?string $requestedRemoteAddress = null): array
    {
        $profile = $this->findProfileByName($name);

        if (! $profile) {
            Log::warning("Isolation profile readback failed on {$this->router->name}", [
                'profile' => $name,
                'available_profiles' => array_map(
                    fn (array $item) => $item['name'] ?? null,
                    $this->getProfiles()
                ),
            ]);
            throw new \RuntimeException("MikroTik did not return the isolation profile '{$name}' after save.");
        }

        if ($requestedRemoteAddress && $this->shouldVerifyAddressPool($requestedRemoteAddress)) {
            $pool = $this->findAddressPoolByName($requestedRemoteAddress);

            if (! $pool) {
                throw new \RuntimeException("MikroTik did not return the address pool '{$requestedRemoteAddress}' after save.");
            }
        }

        return [
            'created' => $created,
            'name' => $profile['name'] ?? $name,
            'rate_limit' => $profile['rate-limit'] ?? null,
            'local_address' => $profile['local-address'] ?? ($requestedLocalAddress ? Str::before($requestedLocalAddress, '/') : null),
            'remote_address' => $profile['remote-address'] ?? $requestedRemoteAddress,
            'only_one' => $profile['only-one'] ?? 'yes',
        ];
    }

    protected function shouldVerifyAddressPool(string $remoteAddress): bool
    {
        return ! filter_var($remoteAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ! str_contains($remoteAddress, '-')
            && ! str_contains($remoteAddress, ',')
            && ! str_contains($remoteAddress, '/');
    }

    protected function findProfileByName(string $name): ?array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $query = (new Query('/ppp/profile/print'))
                ->where('name', $name);
            $profiles = $this->client->query($query)->read();

            foreach ($profiles as $profile) {
                if (isset($profile['name']) && strcasecmp((string) $profile['name'], $name) === 0) {
                    return $profile;
                }
            }

            foreach ($this->getProfiles() as $profile) {
                if (isset($profile['name']) && strcasecmp((string) $profile['name'], $name) === 0) {
                    return $profile;
                }
            }

            usleep(200000);
        }

        return null;
    }

    protected function findAddressPoolByName(string $name): ?array
    {
        $query = (new Query('/ip/pool/print'))
            ->where('name', $name);
        $pools = $this->client->query($query)->read();

        return $pools[0] ?? null;
    }

    /**
     * Test connection to router
     */
    public function testConnection(): array
    {
        if (! $this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            $query = new Query('/system/resource/print');
            $response = $this->client->query($query)->read();

            return [
                'success' => true,
                'router' => $this->router->name,
                'data' => $response[0] ?? [],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'router' => $this->router->name,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * LIVE health: resource + active count-only (optional name list for customer flags).
     * Never dumps full /ppp/active without proplist.
     *
     * @return array{cpu_load:?int,uptime:?string,version:?string,board_name:?string,free_memory:?int,total_memory:?int,online_count:int,active_names:list<string>,source:string}
     */
    public function getHealthStats(bool $withActiveNames = true): array
    {
        if (! $this->client) {
            throw new \Exception('Not connected to router. Call connect() first.');
        }

        try {
            $resourceQuery = new Query('/system/resource/print');
            $resource = $this->client->query($resourceQuery)->read();
            $system = is_array($resource[0] ?? null) ? $resource[0] : [];

            // Online KPI primary = count-only (must succeed for LIVE).
            // Large pools (Krian): stable double-sample — single read can under-report ~200–300 vs ~450.
            $prevOnline = (int) ($this->router->current_online_count ?? 0);
            $stableCount = $prevOnline >= 200 || (int) ($this->router->total_pppoe_count ?? 0) >= 300;
            $sessionRows = $this->countActiveConnectionsOnly($stableCount);
            $onlineCount = $sessionRows;
            $activeNames = [];
            $source = $stableCount ? 'count_only_stable' : 'count_only';
            $multiSessionUsers = 0;

            // Name dump: best-effort only.
            // liveStats / health (withActiveNames=false): NEVER dump names — Krian-class
            // full /ppp/active print causes Stream timed out and false under-counts (~365).
            // Only dump when caller needs is_online flags, or count-only failed on small pools.
            $secretPool = (int) ($this->router->total_pppoe_count ?? 0);
            $largePool = $prevOnline >= 200 || $secretPool >= 300 || $sessionRows >= 200;
            $shouldDumpNames = $withActiveNames
                || (! $largePool && $sessionRows <= 0);
            if ($shouldDumpNames) {
                try {
                    $active = $this->getActiveConnections(false);
                    $allNames = array_values(array_filter(array_column($active, 'name')));
                    $uniqueNames = array_values(array_unique($allNames));
                    $uniqueCount = count($uniqueNames);
                    $rowDupes = max(0, count($allNames) - $uniqueCount);

                    if ($withActiveNames) {
                        $activeNames = $uniqueNames;
                    }

                    if ($uniqueCount > 0) {
                        if ($sessionRows <= 0) {
                            $onlineCount = $uniqueCount;
                            $source = 'unique_name';
                            $multiSessionUsers = $rowDupes;
                        } elseif ($uniqueCount > $sessionRows) {
                            $onlineCount = $uniqueCount;
                            $source = 'unique_name';
                            $multiSessionUsers = $rowDupes;
                        } else {
                            $onlineCount = $sessionRows;
                            $source = 'count_only';
                            $multiSessionUsers = max($rowDupes, $sessionRows - $uniqueCount);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Active name list failed on {$this->router->name}, using session-rows count-only={$onlineCount}: {$e->getMessage()}");
                }
            }

            // Light secrets count-only — keeps denominator fresh (avoids stale fullSync total).
            $secretCount = null;
            try {
                $secretCount = $this->getPPPSecretCount();
            } catch (\Throwable $e) {
                Log::warning("Secret count-only failed on {$this->router->name}: {$e->getMessage()}");
            }

            return [
                'cpu_load' => isset($system['cpu-load']) ? (int) $system['cpu-load'] : null,
                'uptime' => $system['uptime'] ?? null,
                'version' => $system['version'] ?? null,
                'board_name' => $system['board-name'] ?? null,
                'free_memory' => isset($system['free-memory']) ? (int) $system['free-memory'] : null,
                'total_memory' => isset($system['total-memory']) ? (int) $system['total-memory'] : null,
                'online_count' => $onlineCount,
                'session_rows' => $sessionRows,
                'multi_session_users' => $multiSessionUsers,
                'active_names' => $withActiveNames ? $activeNames : [],
                'source' => $source,
                'total_pppoe_count' => $secretCount,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get health stats for {$this->router->name}: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Sync Customer is_online from authoritative active list.
     * Never call with empty array on fetch failure — that would mass-offline everyone.
     */
    public function syncCustomerOnlineStatus(array $activeConnections, bool $authoritative = true): void
    {
        if (! $this->router || ! $authoritative) {
            return;
        }

        $activeUsernames = array_values(array_unique(array_filter(array_column($activeConnections, 'name'))));
        // Every outcome of an authoritative sync is fresh evidence (online AND offline).
        $checked = PppoeOnlineFlagWriter::hasCheckedAtColumn() ? ['pppoe_online_checked_at' => now()] : [];

        if ($activeUsernames !== []) {
            // P6: Auto-sync router_id if customer active connection is detected on THIS router
            // but customer.router_id in DB currently points to a different router
            Customer::where(function ($query) {
                $query->whereNotIn('router_id', [$this->router->id])
                    ->orWhereNull('router_id');
            })
                ->ebilling()
                ->whereIn('pppoe_user', $activeUsernames)
                ->get()
                ->each(function ($c) use ($checked) {
                    if ($this->isConfirmedOnlineOnOwnRouter($c)) {
                        // Dual-active (e.g. Krian router 15 <-> 514 during a migration
                        // before source cutover): the same secret has a session on two
                        // routers. Moving router_id here made customers flip-flop
                        // between routers on every sync. Flag it, do not move.
                        Log::warning("[RouterSync] dual-active PPPoE: customer {$c->id} is confirmed online on router_id={$c->router_id} and also active on {$this->router->id} ({$this->router->name}); router_id NOT moved.");

                        return;
                    }
                    Log::info("[RouterSync] Auto-moving customer {$c->id} ({$c->name} / {$c->pppoe_user}) from router_id={$c->router_id} to {$this->router->id} ({$this->router->name}) based on active connection.");
                    $c->update([
                        'router_id' => $this->router->id,
                        'is_online' => true,
                        'mikrotik_sync_status' => 'synced',
                        'mikrotik_synced_at' => now(),
                        'mikrotik_sync_checked_at' => now(),
                    ] + $checked);
                });

            Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->whereIn('pppoe_user', $activeUsernames)
                ->update([
                    'is_online' => true,
                    'mikrotik_sync_status' => 'synced',
                    'mikrotik_synced_at' => now(),
                    'mikrotik_sync_checked_at' => now(),
                ] + $checked);

            Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->whereNotIn('pppoe_user', $activeUsernames)
                ->update(['is_online' => false] + $checked);
        } else {
            // Authoritative empty = truly no active sessions on router
            Customer::where('router_id', $this->router->id)
                ->ebilling()
                ->update(['is_online' => false] + $checked);
        }

        Log::info("Synced online status for Router: {$this->router->name} (".count($activeUsernames).' active)');
    }

    /**
     * True when the customer's CURRENT router recently confirmed an active
     * session (is_online + fresh pppoe_online_checked_at), i.e. a session seen
     * on another router means the username is active on two routers at once.
     */
    protected function isConfirmedOnlineOnOwnRouter(Customer $customer): bool
    {
        if (! $customer->router_id || ! $customer->is_online || ! PppoeOnlineFlagWriter::hasCheckedAtColumn()) {
            return false;
        }

        $checkedAt = $customer->pppoe_online_checked_at;
        $maxAge = max(1, (int) config('services.online_freshness.ppp_max_age_minutes', 15));

        return $checkedAt !== null && $checkedAt->gt(now()->subMinutes($maxAge));
    }

    /**
     * Get the RouterOS client instance
     */
    public function getClient(): ?Client
    {
        return $this->client;
    }

    /**
     * Add (or update) a PPPoE secret on the connected router.
     * Used by Auto PPPoE / PSB push.
     *
     * Always re-reads the secret after write. RouterOS often returns
     * {after: {message: "not enough permissions (9)"}} without throwing —
     * treat that as failure (never false-success).
     *
     * @return array{success: bool, pushed: bool, message: string, secret_id: ?string, existed?: bool, code?: string}
     */
    public function addPPPSecret(
        string $username,
        string $password,
        string $profile,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $service = 'pppoe',
        ?string $comment = null
    ): array {
        if (! $this->client) {
            return [
                'success' => false,
                'pushed' => false,
                'message' => 'Not connected to router',
                'secret_id' => null,
                'code' => 'not_connected',
            ];
        }

        try {
            $existing = $this->findPPPSecret($username);

            if ($existing) {
                $set = (new Query('/ppp/secret/set'))
                    ->equal('.id', $existing['.id'])
                    ->equal('password', $password)
                    ->equal('profile', $profile)
                    ->equal('service', $service ?: 'pppoe');

                if ($localAddress) {
                    $set->equal('local-address', $localAddress);
                }
                if ($remoteAddress) {
                    $set->equal('remote-address', $remoteAddress);
                }
                if ($comment) {
                    $set->equal('comment', $comment);
                }

                $response = $this->client->query($set)->read();
                if ($err = $this->routerOsErrorMessage($response)) {
                    return $this->pppSecretFail($username, $err, $existing['.id'] ?? null);
                }

                $verified = $this->findPPPSecret($username);
                if (! $verified) {
                    return $this->pppSecretFail($username, 'Secret missing after update (verify failed)', null, 'verify_failed');
                }

                Log::info("Updated existing PPPoE secret '{$username}' on {$this->router?->name}");

                return [
                    'success' => true,
                    'pushed' => true,
                    'message' => 'Secret exists, updated',
                    'secret_id' => $verified['.id'] ?? ($existing['.id'] ?? null),
                    'existed' => true,
                    'code' => 'updated',
                ];
            }

            $query = (new Query('/ppp/secret/add'))
                ->equal('name', $username)
                ->equal('password', $password)
                ->equal('profile', $profile)
                ->equal('service', $service ?: 'pppoe');

            if ($localAddress) {
                $query->equal('local-address', $localAddress);
            }
            if ($remoteAddress) {
                $query->equal('remote-address', $remoteAddress);
            }
            if ($comment) {
                $query->equal('comment', $comment);
            }

            $response = $this->client->query($query)->read();
            if ($err = $this->routerOsErrorMessage($response)) {
                return $this->pppSecretFail($username, $err, null);
            }

            // Re-read — never trust silent add without a row
            $verified = $this->findPPPSecret($username);
            if (! $verified) {
                return $this->pppSecretFail(
                    $username,
                    'Secret not found after add (likely no write permission)',
                    null,
                    'verify_failed'
                );
            }

            $secretId = $verified['.id']
                ?? (is_array($response) ? ($response['after']['ret'] ?? ($response[0]['.id'] ?? null)) : null);

            Log::info("Added PPPoE secret '{$username}' on {$this->router?->name}");

            return [
                'success' => true,
                'pushed' => true,
                'message' => 'PPPoE secret added',
                'secret_id' => is_string($secretId) ? $secretId : null,
                'existed' => false,
                'code' => 'added',
            ];
        } catch (\Throwable $e) {
            Log::error("addPPPSecret failed for '{$username}' on {$this->router?->name}: ".$e->getMessage());

            return $this->pppSecretFail($username, $e->getMessage(), null, 'exception');
        }
    }

    /**
     * Extract RouterOS API error from evilrouteros-style responses.
     */
    protected function routerOsErrorMessage(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $msg = $response['after']['message']
            ?? $response['message']
            ?? null;

        if (is_string($msg) && $msg !== '') {
            return $msg;
        }

        // !trap rows sometimes appear as list items
        foreach ($response as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['message']) && is_string($row['message']) && $row['message'] !== '') {
                // skip ret-only success shapes
                if (isset($row['ret']) || isset($row['.id'])) {
                    continue;
                }

                return $row['message'];
            }
        }

        return null;
    }

    /**
     * @return array{success: false, pushed: false, message: string, secret_id: ?string, code: string}
     */
    protected function pppSecretFail(string $username, string $message, ?string $secretId = null, ?string $code = null): array
    {
        $lower = strtolower($message);
        if ($code === null) {
            if (str_contains($lower, 'not enough permissions') || str_contains($lower, 'permission denied')) {
                $code = 'mikrotik_write_denied';
            } elseif (str_contains($lower, 'no such item') || str_contains($lower, 'profile')) {
                $code = 'profile_or_item_error';
            } else {
                $code = 'push_failed';
            }
        }

        Log::error("addPPPSecret fail '{$username}' on {$this->router?->name}: [{$code}] {$message}");

        return [
            'success' => false,
            'pushed' => false,
            'message' => $message,
            'secret_id' => $secretId,
            'code' => $code,
        ];
    }

    /**
     * Disconnect from router
     */
    public function disconnect(): void
    {
        $this->profilesCache = null;
        if ($this->client) {
            $this->client = null;
            Log::info("Disconnected from router: {$this->router->name}");
        }
    }

    /** Raw RouterOS client for one-off operational tooling (read-only intent). */
    public function client(): ?Client
    {
        return $this->client;
    }
}
