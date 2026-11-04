<?php

namespace App\Services;

use App\Models\Router;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class WireGuardProvisioningService
{
    public function generateClientKeySet(): array
    {
        $privateKey = $this->runWireGuardCommand(['genkey']);
        $publicKey = $this->runWireGuardCommand(['pubkey'], $privateKey);
        $presharedKey = $this->runWireGuardCommand(['genpsk']);

        return [
            'private_key' => trim($privateKey),
            'public_key' => trim($publicKey),
            'preshared_key' => trim($presharedKey),
        ];
    }

    public function defaults(?Request $request = null): array
    {
        return [
            'vpn_interface' => 'wg-ebilling',
            'vpn_server_address' => config('services.wireguard.server_address', config('site.wireguard.server_address')),
            'vpn_server_public_key' => $this->serverPublicKey(),
            'vpn_server_endpoint' => $this->serverEndpoint($request),
            'vpn_address' => $this->getNextAvailableIp(),
            'vpn_server_port' => (int) config('services.wireguard.port', 443),
            'vpn_allowed_ips' => config('services.wireguard.allowed_ips', config('site.wireguard.allowed_ips')),
            'radius_auth_port' => 1812,
            'radius_acct_port' => 1813,
        ];
    }

    /**
     * Persist VPN fields on a clean model instance (avoids dirty display-only attrs
     * like mt_pulse_* from MtPlanCPulseService::decorateRouterForDisplay).
     *
     * @param  array<string, mixed>  $fields
     */
    private function persistVpnFields(Router $router, array $fields): void
    {
        $clean = Router::query()->find($router->id);
        if (! $clean) {
            throw new RuntimeException('Router #'.$router->id.' not found for VPN persist');
        }

        foreach ($fields as $key => $value) {
            $clean->setAttribute($key, $value);
            $router->setAttribute($key, $value);
        }
        $clean->save();

        // Keep in-memory router consistent and not re-dirty display attrs
        foreach (array_keys($fields) as $key) {
            $router->syncOriginalAttribute($key);
        }
    }

    public function mikrotikScript(Router $router): string
    {
        // Auto-generate keys if missing (so MikroTik script has real keys, not placeholders)
        if (empty($router->vpn_client_private_key) || empty($router->vpn_client_public_key)) {
            try {
                $keys = $this->generateClientKeySet();
                $fields = [
                    'vpn_client_private_key' => $keys['private_key'],
                    'vpn_client_public_key' => $keys['public_key'],
                ];
                if (empty($router->vpn_preshared_key)) {
                    $fields['vpn_preshared_key'] = $keys['preshared_key'];
                }
                $this->persistVpnFields($router, $fields);
                Log::info('[WireGuard] Auto-generated keys for router '.$router->id);
            } catch (\Throwable $e) {
                Log::warning('[WireGuard] Cannot auto-generate keys: '.$e->getMessage());
            }
        }

        // Get server public key if missing (prefer key file over stale env var)
        if (empty($router->vpn_server_public_key)) {
            $serverPub = $this->serverPublicKey();
            if ($serverPub) {
                $this->persistVpnFields($router, ['vpn_server_public_key' => $serverPub]);
            }
        }

        // Auto-assign VPN IP if missing
        if (empty($router->vpn_address)) {
            $ip = $this->getNextAvailableIp();
            $this->persistVpnFields($router, ['vpn_address' => $ip]);
            Log::info('[WireGuard] Auto-assigned IP '.$router->vpn_address.' for router '.$router->id);
        }

        // Add peer to VPS WireGuard — try webhook first (host-level), then direct runtime
        if ($router->vpn_client_public_key && $router->vpn_address) {
            $webhookOk = $this->addPeerToVps($router);
            if (! $webhookOk) {
                // Webhook failed or not configured — try direct runtime add from container
                try {
                    $this->addPeerToRuntime($router);
                } catch (\Throwable $e) {
                    Log::warning('[WireGuard] addPeerToRuntime failed: '.$e->getMessage());
                }
                // Also persist to config file (non-fatal if it fails — e.g. no /etc/wireguard in Docker)
                try {
                    $this->savePeerToConfig($router);
                } catch (\Throwable $e) {
                    Log::warning('[WireGuard] savePeerToConfig failed: '.$e->getMessage());
                }
            }
        }

        $interface = $router->vpn_interface ?: 'wg-ebilling';
        $vpnAddress = $router->vpn_address ?: config('site.wireguard.peer_address');
        $serverAddress = $router->vpn_server_address ?: config('site.wireguard.server_address');
        $serverEndpoint = $router->vpn_server_endpoint ?: $this->serverEndpoint() ?: 'YOUR_VPS_PUBLIC_IP';
        $serverPort = $router->vpn_server_port ?: 51820;
        $allowedIps = $router->vpn_allowed_ips ?: config('site.wireguard.allowed_ips');
        $authPort = $router->radius_auth_port ?: 1812;
        $acctPort = $router->radius_acct_port ?: 1813;
        $radiusSecret = $router->radius_secret ?: 'CHANGE_ME_RADIUS_SECRET';
        $clientPrivateKey = $router->vpn_client_private_key ?: 'PASTE_CLIENT_PRIVATE_KEY';
        $serverPublicKey = $router->vpn_server_public_key ?: 'PASTE_SERVER_PUBLIC_KEY';

        $lines = [];
        $lines[] = '# Skynet E-Billing - '.$router->name;
        $lines[] = '';
        $lines[] = ':put ">>> [1/5] Membuat interface WireGuard..."';
        $lines[] = ':do { /interface wireguard remove [find name="'.$interface.'"] } on-error={}';
        $lines[] = '/interface wireguard add name="'.$interface.'" private-key="'.$clientPrivateKey.'" listen-port=13231';
        $lines[] = ':delay 2s';
        $lines[] = '';
        $lines[] = ':put ">>> [2/5] Menambahkan IP address ke interface..."';
        // Use /24 on MikroTik interface so it can reach the whole WG subnet
        $mikrotikAddress = preg_replace('#/\d+$#', '/24', $vpnAddress);
        $lines[] = ':do { /ip address remove [find interface="'.$interface.'"] } on-error={}';
        $lines[] = '/ip address add address="'.$mikrotikAddress.'" interface="'.$interface.'"';
        $lines[] = ':delay 1s';
        $lines[] = '';

        // WireGuard peer
        // allowed-address: route subnet traffic through WG peer
        $peerAllowedAddress = $allowedIps;
        $lines[] = ':do { /interface wireguard peers remove [find interface="'.$interface.'"] } on-error={}';
        $peerCmd = '/interface wireguard peers add interface="'.$interface.'" public-key="'.$serverPublicKey.'" endpoint-address="'.$serverEndpoint.'" endpoint-port='.$serverPort.' allowed-address="'.$peerAllowedAddress.'" persistent-keepalive=25s';
        if ($router->vpn_preshared_key) {
            $peerCmd .= ' preshared-key="'.$router->vpn_preshared_key.'"';
        }
        $lines[] = ':put ">>> [3/5] Menambahkan peer WireGuard (VPS)..."';
        $lines[] = $peerCmd;
        $lines[] = ':delay 2s';
        $lines[] = '';

        $lines[] = ':put ">>> [4/5] Menambahkan server RADIUS..."';
        $lines[] = ':do { /radius remove [find service=ppp address="'.$serverAddress.'"] } on-error={}';
        $lines[] = '/radius add service=ppp address="'.$serverAddress.'" secret="'.$radiusSecret.'" authentication-port='.$authPort.' accounting-port='.$acctPort.' timeout=3s';
        $lines[] = ':delay 1s';
        $lines[] = '';

        $lines[] = ':put ">>> [5/5] Konfigurasi PPP AAA..."';
        $lines[] = '/ppp aaa set use-radius=yes accounting=yes interim-update=5m';
        $lines[] = '';
        $lines[] = ':put ""';
        $lines[] = ':put "✅ Selesai! Cek koneksi VPN:"';
        $lines[] = ':put "/ping '.$serverAddress.' count=3"';
        $lines[] = ':put "Kalau reply, VPN sudah terhubung! 🎉"';

        return implode("\n", $lines);
    }

    public function serverPeerConfig(Router $router): string
    {
        $allowedIp = $this->peerAllowedIp($router);

        $lines = [
            "# {$router->name}",
            '[Peer]',
            'PublicKey = '.($router->vpn_client_public_key ?: 'PASTE_MIKROTIK_PUBLIC_KEY'),
        ];

        if ($router->vpn_preshared_key) {
            $lines[] = "PresharedKey = {$router->vpn_preshared_key}";
        }

        $lines[] = "AllowedIPs = {$allowedIp}";

        return implode("\n", $lines);
    }

    public function applyPeer(Router $router): array
    {
        if (! $router->vpn_client_public_key) {
            return ['applied' => false, 'message' => 'MikroTik WireGuard public key is empty.'];
        }

        try {
            $pskFile = null;
            $command = [
                $this->wireGuardBinary(),
                'set',
                config('services.wireguard.interface', 'wg0'),
                'peer',
                $router->vpn_client_public_key,
                'allowed-ips',
                $this->peerAllowedIp($router),
            ];

            if ($router->vpn_preshared_key) {
                $pskFile = tempnam(sys_get_temp_dir(), 'wg-psk-');
                file_put_contents($pskFile, $router->vpn_preshared_key.PHP_EOL);
                $command[] = 'preshared-key';
                $command[] = $pskFile;
            }

            $this->runProcess($command);

            return ['applied' => true, 'message' => 'WireGuard peer applied to the VPS interface.'];
        } catch (\Throwable $e) {
            // The failing command line may include key-file paths or peer
            // material; keep the detail in the (redacted) log only.
            Log::warning('[WireGuard] peer apply failed', [
                'router_id' => $router->id,
                'exception_class' => $e::class,
                'message' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);

            return ['applied' => false, 'message' => 'WireGuard peer could not be applied on the VPS; check the application log.'];
        } finally {
            if (isset($pskFile) && $pskFile && file_exists($pskFile)) {
                @unlink($pskFile);
            }
        }
    }

    public function peerAllowedIp(Router $router): string
    {
        $address = trim((string) $router->vpn_address);

        if ($address === '') {
            return (string) config('site.wireguard.peer_allowed_ip');
        }

        $ip = Str::before($address, '/');

        return "{$ip}/32";
    }

    private function runWireGuardCommand(array $arguments, ?string $input = null): string
    {
        return $this->runProcess(array_merge([$this->wireGuardBinary()], $arguments), $input);
    }

    private function runProcess(array $command, ?string $input = null): string
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start wg command. Install wireguard-tools on the server.');
        }

        fwrite($pipes[0], $input ?? '');
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new RuntimeException(trim($error) ?: 'WireGuard key generation failed.');
        }

        return trim($output);
    }

    public function serverPublicKey(): ?string
    {
        // 1) Read from key file FIRST — this is always accurate even when Docker
        //    env vars are stale (Docker caches env vars at container start, .env file
        //    changes don't propagate until container restart).
        $path = config('services.wireguard.public_key_path', '/etc/wireguard/server_public.key');
        if (is_readable($path)) {
            $fromFile = trim((string) file_get_contents($path));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        // 2) Fallback to config (env var) — may be stale if container not restarted
        $configured = config('services.wireguard.public_key');
        if ($configured) {
            return $configured;
        }

        // 3) Last resort: ask wg binary
        try {
            return trim($this->runWireGuardCommand(['show', config('services.wireguard.interface', 'wg0'), 'public-key']));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run `wg show <interface>` and return raw output.
     */
    public function runWgShow(string $interface = 'wg0'): string
    {
        try {
            return $this->runProcess([$this->wireGuardBinary(), 'show', $interface]);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function serverEndpoint(?Request $request = null): string
    {
        $configured = config('services.wireguard.endpoint');
        if ($configured) {
            return $configured;
        }

        $host = $request?->getHost();
        if ($host && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return $host;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $appHost ?: 'YOUR_VPS_PUBLIC_IP';
    }

    private function wireGuardBinary(): string
    {
        foreach (['/usr/bin/wg', '/bin/wg', 'wg'] as $binary) {
            if ($binary === 'wg' || is_executable($binary)) {
                return $binary;
            }
        }

        throw new RuntimeException('WireGuard tools are not installed. Run: apt install wireguard-tools');
    }

    public function getNextAvailableIp(string $subnet = '10.99.0', int $start = 2, int $max = 254): string
    {
        $used = [];

        // 1) Check existing routers in the database
        try {
            $routers = Router::whereNotNull('vpn_address')
                ->where('vpn_address', '!=', '')
                ->get(['vpn_address']);
            foreach ($routers as $r) {
                $ip = Str::before(trim($r->vpn_address), '/');
                if (strpos($ip, $subnet) === 0) {
                    $octet = (int) substr($ip, strrpos($ip, '.') + 1);
                    if ($octet >= $start && $octet <= $max) {
                        $used[$octet] = true;
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore DB errors */
        }

        // 2) Check WG interface on VPS for already-assigned IPs
        try {
            $output = $this->runProcess([$this->wireGuardBinary(), 'show', config('services.wireguard.interface', 'wg0'), 'allowed-ips']);
            if ($output) {
                foreach (explode("\n", trim($output)) as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, '#') === 0) {
                        continue;
                    }
                    $parts = preg_split('/\s+/', $line);
                    if (! empty($parts[0])) {
                        $ip = Str::before($parts[0], '/');
                        if (strpos($ip, $subnet) === 0) {
                            $octet = (int) substr($ip, strrpos($ip, '.') + 1);
                            if ($octet >= $start && $octet <= $max) {
                                $used[$octet] = true;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) { /* ignore wg errors */
        }

        for ($i = $start; $i <= $max; $i++) {
            if (! isset($used[$i])) {
                return "{$subnet}.{$i}/32";
            }
        }

        return "{$subnet}.{$start}/32";
    }

    public function savePeerToConfig(Router $router): array
    {
        $configPath = config('services.wireguard.config_path', '/etc/wireguard/wg0.conf');
        $interface = config('services.wireguard.interface', 'wg0');
        $allowedIp = $this->peerAllowedIp($router);
        $publicKey = $router->vpn_client_public_key;

        if (! $publicKey) {
            return ['applied' => false, 'message' => 'Cannot save peer: client public key is empty.'];
        }

        // --- Read existing config and deduplicate peers ---
        $configContent = '';
        $peerExists = false;
        if (file_exists($configPath)) {
            $configContent = file_get_contents($configPath);

            // Check if this peer's public key already exists in the config
            if (strpos($configContent, $publicKey) !== false) {
                $peerExists = true;
                // Remove the old [Peer] block for this public key to avoid duplicates
                $configContent = $this->removePeerBlockFromConfig($configContent, $publicKey);
            }
        }

        // Build the new peer block
        $peerBlock = "# {$router->name} (auto-added)\n[Peer]\nPublicKey = {$publicKey}\nAllowedIPs = {$allowedIp}";
        if ($router->vpn_preshared_key) {
            $peerBlock .= "\nPresharedKey = {$router->vpn_preshared_key}";
        }

        // Append the peer block to the config content
        $newContent = rtrim($configContent)."\n\n".$peerBlock."\n";

        // --- Write to wg0.conf with retry (handles brief permission/lock contention) ---
        $written = false;
        $lastError = '';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                // Ensure the directory exists
                $dir = dirname($configPath);
                if (! is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }

                // Try to ensure the file is writable (chmod if needed & possible)
                if (file_exists($configPath) && ! is_writable($configPath)) {
                    @chmod($configPath, 0600);
                }
                if (! file_exists($configPath) && is_writable($dir)) {
                    @touch($configPath);
                    @chmod($configPath, 0600);
                }

                $bytes = file_put_contents($configPath, $newContent, LOCK_EX);
                if ($bytes !== false) {
                    $written = true;
                    break;
                }
            } catch (\Throwable $e) {
                $lastError = SensitiveDataRedactor::redact($e->getMessage());
            }

            // Brief pause before retry
            if ($attempt < 3) {
                usleep(250000); // 250ms
            }
        }

        if (! $written) {
            Log::error('[WireGuard] Failed to write peer to config after 3 attempts: '.$lastError);
            // Don't abort — the webhook path may still add the peer to runtime
        }

        // --- Add peer to live WireGuard interface (runtime) ---
        $runtimeAdded = $this->addPeerToRuntime($router);

        if ($written && $runtimeAdded) {
            return ['applied' => true, 'message' => "Peer saved to {$configPath} and added to {$interface} runtime."];
        } elseif ($written) {
            return ['applied' => true, 'message' => "Peer saved to {$configPath} but could not be added to runtime. Use webhook or manual wg set."];
        } elseif ($runtimeAdded) {
            return ['applied' => true, 'message' => "Peer added to {$interface} runtime but config file write failed: {$lastError}"];
        } else {
            return ['applied' => false, 'message' => "Failed to save peer: config write error ({$lastError}) and runtime add failed."];
        }
    }

    /**
     * Remove a [Peer] block containing the given public key from config content.
     * Returns the modified config content with that peer block removed.
     */
    private function removePeerBlockFromConfig(string $content, string $publicKey): string
    {
        // Split config into sections by [Peer] boundaries
        // Keep everything before the first [Peer] as the interface section
        $lines = explode("\n", $content);
        $result = [];
        $inTargetPeer = false;
        $lastLineWasPeerSep = true; // Treat start-of-file as a boundary

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Detect section headers
            if (preg_match('/^\[(Interface|Peer)\]/', $trimmed)) {
                // We're entering a new section
                $inTargetPeer = false;

                // Check if this is a [Peer] section that contains our target public key
                // We'll need to look ahead, so mark it tentatively
                if ($trimmed === '[Peer]') {
                    // Tentatively add — we'll check the content below
                    $result[] = $line;

                    // We don't know yet if this peer has our key, so we track a buffer
                    continue;
                }
            }

            $result[] = $line;
        }

        // Simpler approach: use regex to remove the peer block containing the public key
        // Match [Peer] block that contains our public key
        $pattern = '/\n*#?[^\n]*\n\[Peer\]\n(?:[^\n]*\n)*?PublicKey\s*=\s*'.preg_quote($publicKey, '/').'\s*\n(?:[^\n]*\n)*/';
        $result = preg_replace($pattern, "\n", $content);

        if ($result === null) {
            return $content; // regex error, return original
        }

        return $result;
    }

    /**
     * Add a peer to the live WireGuard interface using `wg set`.
     * Works inside the container where wg binary is available.
     */
    public function addPeerToRuntime(Router $router): bool
    {
        try {
            $interface = config('services.wireguard.interface', 'wg0');
            $allowedIp = $this->peerAllowedIp($router);
            $publicKey = $router->vpn_client_public_key;

            if (! $publicKey) {
                return false;
            }

            $command = [$this->wireGuardBinary(), 'set', $interface, 'peer', $publicKey, 'allowed-ips', $allowedIp];

            if ($router->vpn_preshared_key) {
                $pskFile = tempnam(sys_get_temp_dir(), 'wg-psk-');
                file_put_contents($pskFile, $router->vpn_preshared_key.PHP_EOL);
                $command[] = 'preshared-key';
                $command[] = $pskFile;
            }

            $this->runProcess($command);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[WireGuard] addPeerToRuntime failed: '.$e->getMessage());

            return false;
        } finally {
            if (isset($pskFile) && $pskFile && file_exists($pskFile)) {
                @unlink($pskFile);
            }
        }
    }

    /**
     * Add peer to VPS WireGuard via webhook (runs on host, can modify wg0 runtime + config).
     * This is the PRIMARY path — the webhook script runs as root on the host
     * and can both `wg set` the runtime and append to wg0.conf.
     *
     * Returns true if webhook succeeded, false otherwise.
     */
    public function addPeerToVps(Router $router): bool
    {
        $webhookUrl = config('services.wireguard.webhook_url');

        if (! $webhookUrl) {
            Log::debug('[WireGuard] No webhook URL configured, skipping webhook peer add.');

            return false;
        }

        try {
            $wgToken = (string) config('services.wireguard.webhook_token', '');
            if ($wgToken === '') {
                throw new RuntimeException('WIREGUARD_WEBHOOK_TOKEN not set — refuse peer webhook (fail-closed)');
            }
            $allowedIp = $this->peerAllowedIp($router);
            $payload = [
                'token' => $wgToken,
                'public_key' => $router->vpn_client_public_key,
                'allowed_ips' => $allowedIp,
                'preshared_key' => $router->vpn_preshared_key ?: '',
                'router_name' => $router->name,
            ];

            // If the router had a previous public key that's different from current,
            // send it so the webhook can remove the stale peer
            if ($router->isDirty('vpn_client_public_key') && $router->getOriginal('vpn_client_public_key')) {
                $payload['remove_public_key'] = $router->getOriginal('vpn_client_public_key');
            }

            $response = Http::timeout(10)->post($webhookUrl, $payload);

            if ($response->successful()) {
                Log::info('[WireGuard] Peer added to VPS via webhook for router '.$router->id);

                return true;
            } else {
                Log::warning('[WireGuard] Webhook returned non-2xx: '.$response->status().' for router '.$router->id);

                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('[WireGuard] Webhook failed for router '.$router->id.': '.$e->getMessage());

            return false;
        }
    }
}
