<?php

namespace App\Services;

use App\Models\DeviceRecoveryCase;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class EvolutionApiService
{
    // Evolution API — Self-hosted unofficial WA gateway
    // SAFER than Fonnte because: self-hosted, no shared sessions, configurable delays
    // Still unofficial — use a SEPARATE business number, not personal
    // Free (self-hosted), Docker-based

    private string $baseUrl;

    private string $apiKey;

    private string $instanceName;

    private int $delayBetweenMessages;

    /** Short timeout for status checks — never block page render on slow Evo. */
    private int $statusTimeout = 3;

    /** Group list timeout (can be larger payloads). */
    private int $groupsTimeout = 10;

    /** Read timeout for message sends (Evolution waits `delay` ms + presence before replying). */
    private int $sendTimeout = 45;

    /** TCP connect timeout for every call; a connect failure means nothing was sent. */
    private int $connectTimeout = 5;

    /** Read timeout for admin calls (instance create/connect, QR, group list). */
    private int $adminTimeout = 15;

    public function __construct(?string $instanceName = null)
    {
        $this->baseUrl = config('services.evolution_api.base_url', 'http://127.0.0.1:8085');
        $this->apiKey = $this->envFileValue('EVOLUTION_API_API_KEY')
            ?: config('services.evolution_api.api_key', '');
        $this->instanceName = $instanceName ?: config('services.evolution_api.instance_name', 'skynet-ebilling');
        $this->delayBetweenMessages = (int) config('services.evolution_api.delay_ms', 5000);
        $this->statusTimeout = max(2, (int) config('services.evolution_api.status_timeout', 3));
        $this->groupsTimeout = max(5, (int) config('services.evolution_api.groups_timeout', 10));
        $this->sendTimeout = max(10, (int) config('services.evolution_api.send_timeout', 45));
        $this->connectTimeout = max(1, (int) config('services.evolution_api.connect_timeout', 5));
        $this->adminTimeout = max(5, (int) config('services.evolution_api.admin_timeout', 15));
    }

    /**
     * HTTP client with explicit connect + read timeouts (never Guzzle's unbounded default).
     */
    protected function evoHttp(?int $timeout = null): PendingRequest
    {
        return Http::connectTimeout($this->connectTimeout)
            ->timeout($timeout ?? $this->adminTimeout);
    }

    /**
     * Map a send exception to a result. A failure before the request reached
     * Evolution (DNS/refused/connect timeout) is safe to retry. Anything after
     * that — typically a read timeout while Evolution is still applying its
     * `delay`/presence — means the message MAY have been delivered, so callers
     * must not auto-retry (`delivery_unknown`), otherwise customers get duplicates.
     *
     * @return array{success: false, error: string, delivery_unknown: bool}
     */
    public static function sendExceptionResult(\Throwable $e): array
    {
        $msg = $e->getMessage();
        $lower = strtolower($msg);
        $connectPhase = str_contains($lower, 'connection timed out')
            || str_contains($lower, 'failed to connect')
            || str_contains($lower, 'connection refused')
            || str_contains($lower, 'could not resolve')
            || str_contains($lower, 'curl error 6:')
            || str_contains($lower, 'curl error 7:');

        if ($connectPhase) {
            return [
                'success' => false,
                'error' => 'Gagal konek ke Evolution API (pesan belum terkirim) — cek container :8085',
                'delivery_unknown' => false,
            ];
        }

        if (str_contains($lower, 'timed out') || str_contains($lower, 'curl error 28')) {
            return [
                'success' => false,
                'error' => 'Timeout menunggu respons Evolution API — status kirim tidak pasti, tidak di-retry otomatis. Cek WA session (QR) & container :8085',
                'delivery_unknown' => true,
            ];
        }

        return ['success' => false, 'error' => $msg, 'delivery_unknown' => true];
    }

    private function envFileValue(string $key): string
    {
        $path = base_path('.env');
        if (! is_readable($path)) {
            return '';
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(trim($line), '#') || ! str_starts_with($line, $key.'=')) {
                continue;
            }

            return trim(substr($line, strlen($key) + 1), " \t\n\r\0\x0B\"'");
        }

        return '';
    }

    /**
     * Switch instance for channel: ops (PSB/monitoring), ticket, or billing.
     */
    public function forChannel(string $channel): self
    {
        $instanceName = match (strtolower($channel)) {
            'billing', 'broadcast' => Setting::get('wa_instance_billing', config('services.evolution_api.instance_billing', 'skynet-billing')),
            'ticket', 'tickets', 'progress' => Setting::get('wa_instance_ticket', config('services.evolution_api.instance_ticket', 'skynet-ticket')),
            default => Setting::get('wa_instance_ops', config('services.evolution_api.instance_ops', 'skynet-ebilling')),
        };

        $clone = clone $this;
        $clone->instanceName = (string) $instanceName;

        return $clone;
    }

    public function forInstance(string $instanceName): self
    {
        $clone = clone $this;
        $clone->instanceName = $instanceName;

        return $clone;
    }

    public function sendGroupMessage(string $groupJid, string $text): array
    {
        return $this->send($groupJid, $text);
    }

    /**
     * Pin a message in a WhatsApp group/chat.
     */
    public function pinMessage(string $remoteJid, string $messageId, int $durationSeconds = 604800): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'API not configured'];
        }

        try {
            $payload = [
                'key' => [
                    'remoteJid' => $remoteJid,
                    'fromMe' => true,
                    'id' => $messageId,
                ],
                'type' => 1,
                'duration' => $durationSeconds,
            ];

            $response = $this->evoHttp(10)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/chat/pinMessage/{$this->instanceName}", $payload);

            if ($response->successful()) {
                return ['success' => true, 'response' => $response->json()];
            }

            return ['success' => false, 'error' => 'Pin failed: HTTP '.$response->status()];
        } catch (\Throwable $e) {
            Log::warning('[EvolutionAPI] pinMessage error: '.$e->getMessage());

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * HTTP client for status/connect probes (fail-fast).
     */
    protected function httpStatus()
    {
        return Http::timeout($this->statusTimeout)
            ->connectTimeout(min(2, $this->statusTimeout))
            ->withHeaders(['apikey' => $this->apiKey]);
    }

    protected function cacheKey(string $suffix): string
    {
        return 'evo:'.$this->instanceName.':'.$suffix;
    }

    public function forgetStatusCache(): void
    {
        Cache::forget($this->cacheKey('state'));
        Cache::forget($this->cacheKey('connected'));
        Cache::forget($this->cacheKey('groups'));
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey) && ! empty($this->baseUrl);
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    /**
     * Check if instance exists in Evo (regardless of connection state).
     * Uses connect endpoint (404 = not exists, anything else = exists).
     */
    public function instanceExists(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }
        try {
            $response = $this->httpStatus()->get("{$this->baseUrl}/instance/connect/{$this->instanceName}");

            // 404 = instance doesn't exist, any other response = exists
            return $response->status() !== 404;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isInstanceConnected(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return $this->getConnectionState() === 'open';
    }

    /**
     * Live state without relying on sticky "open" cache (for heal/scheduler).
     */
    public function getLiveConnectionState(): string
    {
        $this->forgetStatusCache();

        return $this->getConnectionState();
    }

    /**
     * Soft heal when session is flaky (close/connecting after device conflict).
     * Never deletes the instance. Tries connect; if still down after soft attempts,
     * returns needs_qr so UI/ops can re-pair.
     *
     * @return array{success: bool, state: string, action: string, message: string, needs_qr?: bool}
     */
    public function healSession(): array
    {
        if (! $this->isConfigured()) {
            return [
                'success' => false,
                'state' => 'not_configured',
                'action' => 'none',
                'message' => 'Evolution API not configured',
            ];
        }

        $state = $this->getLiveConnectionState();
        if ($state === 'open') {
            return [
                'success' => true,
                'state' => 'open',
                'action' => 'none',
                'message' => 'WA already open',
            ];
        }

        // Soft connect (refresh QR / resume handshake) — safe even if already connecting.
        $connect = $this->connectInstance();
        $this->forgetStatusCache();
        usleep(400000);
        $stateAfter = $this->getLiveConnectionState();

        if ($stateAfter === 'open') {
            return [
                'success' => true,
                'state' => 'open',
                'action' => 'connect',
                'message' => 'WA recovered via soft connect',
            ];
        }

        // Stuck connecting/close: one more connect nudge after short wait
        if (in_array($stateAfter, ['connecting', 'close', 'qr', 'qr_required', 'unknown'], true)) {
            usleep(800000);
            $this->connectInstance();
            $this->forgetStatusCache();
            $stateAfter = $this->getLiveConnectionState();
            if ($stateAfter === 'open') {
                return [
                    'success' => true,
                    'state' => 'open',
                    'action' => 'connect_retry',
                    'message' => 'WA recovered on second connect',
                ];
            }
        }

        $hasQr = is_string($connect['qr_code'] ?? null) && ($connect['qr_code'] ?? '') !== '';

        return [
            'success' => false,
            'state' => $stateAfter,
            'action' => 'needs_qr',
            'needs_qr' => true,
            'message' => $hasQr
                ? 'WA down — QR ready; scan di Settings → WhatsApp Gateway'
                : 'WA down — buka Settings → WhatsApp Gateway → Pairing ulang',
        ];
    }

    public function createInstance(): array
    {
        if (! $this->isConfigured()) {
            Log::warning('[Evolution API] Not configured');

            return ['success' => false, 'error' => 'Not configured'];
        }

        // Short-circuit: if instance already exists & connected, return current state
        // instead of trying to create (which Evo rejects with 403 "already in use")
        if ($this->isInstanceConnected()) {
            Log::info('[Evolution API] Instance already exists & connected', ['name' => $this->instanceName]);

            return [
                'success' => true,
                'instance' => $this->instanceName,
                'state' => 'open',
                'message' => 'Instance sudah ada dan terhubung',
            ];
        }

        try {
            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/instance/create", [
                'instanceName' => $this->instanceName,
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['instance']['name'])) {
                Log::info('[Evolution API] Instance created', ['name' => $this->instanceName]);

                // Now connect to get QR code
                return $this->connectInstance();
            }

            // Evo returns 403 "This name X is already in use" when instance exists but disconnected.
            // Treat as graceful: report the existing state and let frontend prompt re-pair.
            $errMsg = is_array($body['response']['message'] ?? null)
                ? implode('; ', $body['response']['message'])
                : ($body['message'] ?? ($body['response']['message'] ?? 'Failed to create instance'));
            $alreadyExists = $response->status() === 403
                || (is_string($errMsg) && stripos($errMsg, 'already') !== false);
            if ($alreadyExists) {
                Log::info('[Evolution API] Instance already exists (rejected create)', ['name' => $this->instanceName, 'err' => $errMsg]);
                $isConnected = $this->isInstanceConnected();
                if ($isConnected) {
                    return [
                        'success' => true,
                        'instance' => $this->instanceName,
                        'state' => 'open',
                        'message' => 'Instance sudah ada dan terhubung',
                    ];
                }

                // Instance exists but disconnected — fetch fresh QR via connect endpoint.
                $connect = $this->connectInstance();

                return [
                    'success' => (bool) ($connect['qr_code'] ?? false) || ($connect['state'] ?? '') === 'connected',
                    'instance' => $this->instanceName,
                    'state' => ($connect['state'] ?? '') === 'connected' ? 'open' : 'close',
                    'qr_code' => $connect['qr_code'] ?? null,
                    'message' => ($connect['qr_code'] ?? null)
                        ? 'QR siap discan.'
                        : ($connect['error'] ?? 'Instance sudah ada tapi terputus. Coba Pairing Ulang.'),
                ];
            }

            return ['success' => false, 'error' => $errMsg, 'body' => $body];
        } catch (\Exception $e) {
            Log::error('[Evolution API] Create instance failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function connectInstance(): array
    {
        try {
            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->get("{$this->baseUrl}/instance/connect/{$this->instanceName}");

            $body = $response->json();
            Log::info('[Evolution API] Connect response', $this->sanitizeConnectLogContext($body));

            if (isset($body['base64'])) {
                // QR code available — user needs to scan with WhatsApp
                return ['success' => true, 'qr_code' => $this->normalizeBase64Image($body['base64']), 'state' => 'qr_required'];
            }

            if (isset($body['instance']['state']) && $body['instance']['state'] === 'open') {
                return ['success' => true, 'state' => 'connected'];
            }

            return ['success' => false, 'state' => $body['instance']['state'] ?? 'unknown', 'body' => $body];
        } catch (\Exception $e) {
            Log::error('[Evolution API] Connect failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function send(string $to, string $message): array
    {
        if (! $this->isConfigured()) {
            Log::warning('[Evolution API] Not configured');

            return ['success' => false, 'error' => 'Not configured'];
        }

        // Detect JID (group @g.us, broadcast @broadcast, etc) vs raw phone number
        $isJid = str_contains($to, '@');

        if (! $isJid) {
            $normalized = app(IndonesianPhoneNormalizer::class)->normalize($to);
            if (! $normalized['is_valid']) {
                return ['success' => false, 'error' => 'Nomor HP tidak valid: '.($normalized['reason'] ?? 'review')];
            }
            $to = $normalized['evolution_recipient'].'@s.whatsapp.net';
        } elseif (str_starts_with($to, '@') || $to === '@s.whatsapp.net') {
            return ['success' => false, 'error' => 'Nomor HP kosong — JID invalid'];
        }

        // Fail fast with clear message if WA session not open (mass broadcast otherwise floods "Unknown error")
        if (! $this->isInstanceConnected()) {
            return [
                'success' => false,
                'error' => 'WhatsApp gateway tidak terhubung (session closed). Scan QR di menu WA Gateway.',
            ];
        }

        try {
            // sendText can stall when session is dying — 45s better than silent 30s default
            $response = $this->evoHttp($this->sendTimeout)->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", [
                'number' => $to,
                'text' => $message,
                'delay' => $this->delayBetweenMessages, // ms delay — simulates human typing
                'presence' => 'composing', // show "typing..." before sending
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['key']['id'])) {
                Log::info('[Evolution API] Message sent', ['to' => $this->maskRecipient($to), 'msg_id' => $body['key']['id']]);

                return ['success' => true, 'message_id' => $body['key']['id']];
            }

            Log::error('[Evolution API] Send failed', ['to' => $this->maskRecipient($to), 'response' => $body, 'status' => $response->status()]);

            return ['success' => false, 'error' => $this->formatEvolutionError($body, $response->status())];
        } catch (\Exception $e) {
            Log::error('[Evolution API] Exception', ['error' => $e->getMessage()]);

            return self::sendExceptionResult($e);
        }
    }

    /**
     * Send an image campaign message using Evolution's sendMedia contract.
     *
     * The media is read from the configured public/local storage disk (or an
     * explicitly supplied existing file) and sent as base64. This method is
     * deliberately fail-closed when the file, recipient, or WA session is not
     * valid; it never falls back to sending a text message without the image.
     */
    public function sendImage(string $to, string $path, string $caption = ''): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured'];
        }

        $isJid = str_contains($to, '@');
        if (! $isJid) {
            $normalized = app(IndonesianPhoneNormalizer::class)->normalize($to);
            if (! $normalized['is_valid']) {
                return ['success' => false, 'error' => 'Nomor HP tidak valid: '.($normalized['reason'] ?? 'review')];
            }
            $to = $normalized['evolution_recipient'].'@s.whatsapp.net';
        }

        if (! $this->isInstanceConnected()) {
            return [
                'success' => false,
                'error' => 'WhatsApp gateway tidak terhubung (session closed). Scan QR di menu WA Gateway.',
            ];
        }

        $path = trim($path);
        $contents = null;
        $filename = basename($path);
        $mime = null;

        foreach (['public', 'local'] as $diskName) {
            $disk = Storage::disk($diskName);
            if (! $disk->exists($path)) {
                continue;
            }

            $contents = $disk->get($path);
            $mime = $disk->mimeType($path) ?: null;
            break;
        }

        if ($contents === null && is_file($path) && is_readable($path)) {
            $contents = file_get_contents($path);
            $mime = mime_content_type($path) ?: null;
        }

        if (! is_string($contents) || $contents === '') {
            return ['success' => false, 'error' => 'File gambar broadcast tidak ditemukan atau kosong.'];
        }

        $mime = is_string($mime) && str_starts_with($mime, 'image/') ? $mime : 'image/jpeg';
        $filename = $filename !== '' && $filename !== '.' ? $filename : 'broadcast.jpg';

        try {
            $response = $this->evoHttp($this->sendTimeout)->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendMedia/{$this->instanceName}", [
                'number' => $to,
                'mediatype' => 'image',
                'mimetype' => $mime,
                'caption' => $caption,
                'media' => base64_encode($contents),
                'fileName' => $filename,
                'delay' => $this->delayBetweenMessages,
            ]);

            $body = $response->json();
            if ($response->successful() && isset($body['key']['id'])) {
                Log::info('[Evolution API] Image message sent', [
                    'to' => $this->maskRecipient($to),
                    'msg_id' => $body['key']['id'],
                ]);

                return ['success' => true, 'message_id' => $body['key']['id']];
            }

            Log::error('[Evolution API] Image send failed', [
                'to' => $this->maskRecipient($to),
                'response' => $body,
                'status' => $response->status(),
            ]);

            return ['success' => false, 'error' => $this->formatEvolutionError($body, $response->status())];
        } catch (\Throwable $e) {
            Log::error('[Evolution API] Image send exception', ['error' => $e->getMessage()]);

            return self::sendExceptionResult($e);
        }
    }

    public const XLSX_MIMETYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Send a local file as a WhatsApp document (/message/sendMedia, mediatype=document).
     * Mirrors sendImage(): fail-closed when the file, recipient or WA session is not
     * valid; it never falls back to text. $path is an absolute readable file.
     *
     * @return array{success: bool, message_id?: string, error?: string, delivery_unknown?: bool}
     */
    public function sendDocument(string $to, string $path, string $caption = '', ?string $fileName = null, string $mimetype = self::XLSX_MIMETYPE): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured'];
        }

        if (! str_contains($to, '@')) {
            $normalized = app(IndonesianPhoneNormalizer::class)->normalize($to);
            if (! $normalized['is_valid']) {
                return ['success' => false, 'error' => 'Nomor HP tidak valid: '.($normalized['reason'] ?? 'review')];
            }
            $to = $normalized['evolution_recipient'].'@s.whatsapp.net';
        }

        if (! $this->isInstanceConnected()) {
            return [
                'success' => false,
                'error' => 'WhatsApp gateway tidak terhubung (session closed). Scan QR di menu WA Gateway.',
            ];
        }

        $path = trim($path);
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
        if (! is_string($contents) || $contents === '') {
            return ['success' => false, 'error' => 'File dokumen tidak ditemukan atau kosong.'];
        }

        $fileName = trim((string) ($fileName ?? basename($path)));
        $fileName = $fileName !== '' && $fileName !== '.' ? $fileName : 'dokumen.xlsx';

        try {
            $response = $this->evoHttp($this->sendTimeout)->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendMedia/{$this->instanceName}", [
                'number' => $to,
                'mediatype' => 'document',
                'mimetype' => $mimetype,
                'caption' => $caption,
                'media' => base64_encode($contents),
                'fileName' => $fileName,
                'delay' => $this->delayBetweenMessages,
            ]);

            $body = $response->json();
            if ($response->successful() && isset($body['key']['id'])) {
                Log::info('[Evolution API] Document message sent', [
                    'to' => $this->maskRecipient($to),
                    'msg_id' => $body['key']['id'],
                ]);

                return ['success' => true, 'message_id' => $body['key']['id']];
            }

            Log::error('[Evolution API] Document send failed', [
                'to' => $this->maskRecipient($to),
                'response' => $body,
                'status' => $response->status(),
            ]);

            return ['success' => false, 'error' => $this->formatEvolutionError($body, $response->status())];
        } catch (\Throwable $e) {
            Log::error('[Evolution API] Document send exception', ['error' => $e->getMessage()]);

            return self::sendExceptionResult($e);
        }
    }

    /**
     * Evolution often nests errors as response.message = "Connection Closed" or message = [{...}].
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function formatEvolutionError(?array $body, int $httpStatus = 0): string
    {
        if (! is_array($body) || $body === []) {
            return $httpStatus > 0 ? "HTTP {$httpStatus} (empty body)" : 'Unknown error';
        }

        $candidates = [
            $body['response']['message'] ?? null,
            $body['message'] ?? null,
            $body['error'] ?? null,
            $body['response']['error'] ?? null,
        ];

        foreach ($candidates as $c) {
            if (is_string($c) && trim($c) !== '') {
                // Map common gateway deaths to actionable Indonesian
                if (stripos($c, 'Connection Closed') !== false) {
                    return 'Connection Closed — WA session putus, scan QR ulang di WA Gateway';
                }

                return $c;
            }
            if (is_array($c)) {
                // e.g. [{"jid":"@s.whatsapp.net","exists":false,...}]
                $first = $c[0] ?? $c;
                if (is_array($first)) {
                    if (isset($first['exists']) && $first['exists'] === false) {
                        return 'Nomor tidak terdaftar di WhatsApp / nomor kosong';
                    }
                    if (! empty($first['message']) && is_string($first['message'])) {
                        return $first['message'];
                    }
                }
                $json = json_encode($c, JSON_UNESCAPED_UNICODE);

                return $json !== false ? $json : 'Unknown error';
            }
        }

        return $body['error'] ?? ('HTTP '.$httpStatus.' Unknown error');
    }

    private function maskRecipient(string $recipient): string
    {
        [$digits, $suffix] = array_pad(explode('@', $recipient, 2), 2, '');
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return $recipient;
        }

        $masked = strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : substr($digits, 0, 2).str_repeat('*', max(2, strlen($digits) - 4)).substr($digits, -2);

        return $masked.($suffix !== '' ? '@'.$suffix : '');
    }

    /**
     * Delete/revoke a sent message for everyone in a chat/group.
     */
    public function deleteMessageForEveryone(string $remoteJid, string $messageId): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Evolution API not configured'];
        }

        $endpoint = "{$this->baseUrl}/chat/deleteMessageForEveryone/{$this->instanceName}";
        try {
            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($endpoint, [
                'remoteJid' => $remoteJid,
                'fromMe' => true,
                'id' => $messageId,
            ]);

            if ($response->successful()) {
                return ['success' => true, 'response' => $response->json()];
            }

            $responseDelete = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(15)->delete($endpoint, [
                'remoteJid' => $remoteJid,
                'fromMe' => true,
                'id' => $messageId,
            ]);

            return ['success' => $responseDelete->successful(), 'response' => $responseDelete->json()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Fetch all WhatsApp groups the instance is part of.
     * Tries multiple Evo API endpoints for compatibility (different Evo versions
     * expose different routes). Returns empty array on failure.
     *
     * @return array List of groups with id, subject, participants etc.
     */
    public function fetchGroups(): array
    {
        if (! $this->isConfigured()) {
            Log::warning('[Evolution API] Not configured, cannot fetch groups');

            return [];
        }

        $cached = Cache::get($this->cacheKey('groups'));
        if (is_array($cached)) {
            return $cached;
        }

        $groups = $this->fetchGroupsFromChats();
        if ($groups !== []) {
            Cache::put($this->cacheKey('groups'), $groups, now()->addSeconds(90));

            return $groups;
        }

        // Evo 2.x: most list endpoints require ?getParticipants=false query.
        // Without this query, Evo returns 400 "getParticipants needs to be informed in the query".
        $queryParams = '?getParticipants=false';

        // Prefer working endpoint first — avoid serial 30s timeouts on dead paths.
        $endpoints = [
            "group/fetchAllGroups/{$this->instanceName}{$queryParams}",
            "group/fetchAllGroups/{$this->instanceName}",
            "groups/fetchAllGroups/{$this->instanceName}{$queryParams}",
        ];

        foreach ($endpoints as $ep) {
            try {
                $url = "{$this->baseUrl}/{$ep}";
                $response = Http::timeout($this->groupsTimeout)
                    ->connectTimeout(3)
                    ->withHeaders(['apikey' => $this->apiKey])
                    ->get($url);

                if ($response->successful()) {
                    $groups = $this->unwrapGroupList($response->json());
                    if ($groups !== []) {
                        $normalized = $this->normalizeGroupRows($groups);
                        Log::info('[Evolution API] Fetched groups', ['count' => count($normalized), 'endpoint' => $ep]);
                        Cache::put($this->cacheKey('groups'), $normalized, now()->addSeconds(90));

                        return $normalized;
                    }
                } else {
                    Log::debug('[Evolution API] Group endpoint returned non-2xx', [
                        'endpoint' => $ep, 'status' => $response->status(), 'body' => substr($response->body(), 0, 200),
                    ]);
                }
            } catch (\Exception $e) {
                Log::debug('[Evolution API] Group fetch endpoint failed', ['endpoint' => $ep, 'error' => $e->getMessage()]);
            }
        }

        Log::warning('[Evolution API] All group fetch endpoints failed');

        return [];
    }

    /**
     * Send a ticket assignment notification via Evolution API WA.
     * Includes ticket link so teknisi can open & update directly.
     */
    public function sendTicketAssignment(SupportTicket $ticket): array
    {
        $technician = $ticket->assignee;
        if (! $technician || empty($technician->phone)) {
            return ['success' => false, 'error' => 'No technician or phone number'];
        }

        $priorityLabels = [
            'low' => 'Rendah',
            'normal' => 'Normal',
            'high' => 'Tinggi',
            'urgent' => 'URGENT',
        ];
        $priorityLabel = $priorityLabels[$ticket->priority] ?? ucfirst($ticket->priority ?? 'Normal');
        $ticketUrl = config('app.url')."/tickets/{$ticket->id}";

        // Check teknisi availability
        $busyTickets = $technician->assignedTickets()
            ->whereIn('status', ['open', 'assigned', 'pending', 'in_progress'])
            ->where('id', '!=', $ticket->id)
            ->count();
        $availability = $busyTickets > 0 ? "🔴 Sibuk ({$busyTickets} tiket aktif)" : '🟢 Available';

        $message = '🎫 TICKET BARU DIASSIGN

'
            ."ID Tiket: {$ticket->code}
"
            ."Gangguan: {$ticket->gangguan}
"
            .'Keterangan: '.($ticket->keterangan ?: '-').'

'
            ."Pelanggan: {$ticket->customer_name}
"
            ."No HP: {$ticket->customer_phone}
"
            .'Koordinat: '.($ticket->customer_coords ?: '-').'

'
            ."Prioritas: {$priorityLabel}
"
            ."Status Teknisi: {$availability}

"
            ."🔗 Buka Tiket:
{$ticketUrl}

"
            .$this->recoveryPickupBlock($ticket)
            .'Silakan kerjakan segera. Terima kasih! 🙏';

        return $this->send($technician->phone, $message);
    }

    /**
     * Owner 2026-10-09: WA to the technician assigned to a pickup case
     * (Pengambilan Perangkat). Sent when no ticket-assignment WA goes out at
     * the same moment (lifecycle approve, retype, manual case assign, backfill).
     */
    public function sendRecoveryAssignment(DeviceRecoveryCase $case): array
    {
        $technician = $case->assignee;
        if (! $technician || empty($technician->phone)) {
            return ['success' => false, 'error' => 'No technician or phone number'];
        }

        $customer = $case->customer;
        $ticket = $case->ticket;
        $schedule = $case->scheduled_at
            ? $case->scheduled_at->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB'
            : '-';

        $message = "📦 PENGAMBILAN PERANGKAT\n\n"
            .'Tiket: '.($ticket?->code ?: '-')."\n"
            .'Pelanggan: '.trim(($customer?->code ?: '-').' - '.($customer?->name ?: ($ticket?->customer_name ?: '-')))."\n"
            .'Alamat: '.($customer?->address ?: '-')."\n"
            .'No HP: '.($customer?->phone ?: ($ticket?->customer_phone ?: '-'))."\n"
            .'SN diambil: '.$this->recoverySerials($case)."\n"
            ."Jadwal: {$schedule}\n\n"
            ."🔗 Buka Pengambilan:\n".$this->recoveryCaseUrl($case)."\n\n"
            .'Ambil perangkat lalu catat SN di aplikasi. Terima kasih! 🙏';

        return $this->send($technician->phone, $message);
    }

    /** Pickup info appended to the ticket-assignment WA so the technician gets one message. */
    private function recoveryPickupBlock(SupportTicket $ticket): string
    {
        try {
            $case = DeviceRecoveryCase::query()
                ->with('items')
                ->where('ticket_id', $ticket->id)
                ->whereIn('status', DeviceRecoveryService::PRE_START_STATUSES)
                ->first();
        } catch (\Throwable) {
            return '';
        }
        if (! $case || ! $case->assigned_to || (int) $case->assigned_to !== (int) $ticket->assigned_to) {
            return '';
        }

        return "📦 Pengambilan Perangkat\n"
            .'SN diambil: '.$this->recoverySerials($case)."\n"
            .$this->recoveryCaseUrl($case)."\n\n";
    }

    private function recoverySerials(DeviceRecoveryCase $case): string
    {
        $serials = $case->items
            ->map(fn ($item) => $item->expected_serial_number ?: strtoupper((string) $item->device_type).' (SN belum diketahui)')
            ->filter()
            ->values()
            ->all();

        return $serials === [] ? '-' : implode(', ', $serials);
    }

    private function recoveryCaseUrl(DeviceRecoveryCase $case): string
    {
        return rtrim((string) config('app.url'), '/')."/device-recovery/{$case->id}";
    }

    /**
     * Send a ticket closed/resolved notification to WA group.
     */
    public function sendTicketClosedToGroup(SupportTicket $ticket): array
    {
        // Priority: DB setting (user-set via /settings UI) > env var > legacy DB key
        $groupId = Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id')
            ?: Setting::where('key', 'ticket_wa_group_id')->value('value');

        if (empty($groupId)) {
            Log::warning('[Evolution API] No WA group ID configured for ticket notifications');

            return ['success' => false, 'error' => 'No WA group ID configured'];
        }

        $ticketUrl = config('app.url')."/tickets/{$ticket->id}";
        $techName = $ticket->assignee ? $ticket->assignee->name : '-';
        $resolvedAt = $ticket->resolved_at ? OpsTime::local($ticket->resolved_at)->format('d/m/Y H:i') : '-';
        $createdAt = $ticket->created_at ? OpsTime::local($ticket->created_at)->format('d/m/Y H:i') : '-';

        // Calculate resolution time
        $resolutionTime = '-';
        if ($ticket->created_at && $ticket->resolved_at) {
            $diff = Carbon::parse($ticket->resolved_at)->diffForHumans($ticket->created_at, ['parts' => 2]);
            $resolutionTime = $diff;
        }

        $message = "✅ TIKET SELESAI\n\n"
            ."ID Tiket: {$ticket->code}\n"
            ."Gangguan: {$ticket->gangguan}\n"
            ."Pelanggan: {$ticket->customer_name}\n"
            ."Teknisi: {$techName}\n"
            ."Dibuat: {$createdAt}\n"
            ."Selesai: {$resolvedAt}\n"
            ."Durasi: {$resolutionTime}\n\n"
            ."🔗 Detail: {$ticketUrl}";

        // Group JIDs end with @g.us instead of @s.whatsapp.net
        $groupJid = $groupId;
        if (! str_ends_with($groupJid, '@g.us')) {
            $groupJid = $groupJid.'@g.us';
        }

        try {
            $response = $this->evoHttp($this->sendTimeout)->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", [
                'number' => $groupJid,
                'text' => $message,
                'delay' => $this->delayBetweenMessages,
                'presence' => 'composing',
            ]);

            $body = $response->json();

            if ($response->successful() && isset($body['key']['id'])) {
                Log::info('[Evolution API] Group notification sent', ['group' => $groupJid, 'ticket' => $ticket->code, 'msg_id' => $body['key']['id']]);

                return ['success' => true, 'message_id' => $body['key']['id']];
            }

            Log::error('[Evolution API] Group notification failed', ['group' => $groupJid, 'response' => $body]);

            return ['success' => false, 'error' => $body['message'] ?? 'Unknown error'];
        } catch (\Exception $e) {
            Log::error('[Evolution API] Group notification exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send the full close report to the separately configured report group.
     */
    public function sendTicketClosedReportToGroup(SupportTicket $ticket): array
    {
        $groupId = Setting::where('key', 'wa_report_group_jid')->value('value');
        if (empty($groupId)) {
            return ['success' => false, 'error' => 'No WA report group configured'];
        }

        $ticketGroupId = Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id');
        $normalizeGroup = static fn ($jid) => $jid && ! str_ends_with($jid, '@g.us') ? $jid.'@g.us' : $jid;
        if ($normalizeGroup($groupId) === $normalizeGroup($ticketGroupId)) {
            return ['success' => false, 'error' => 'Report group matches ticket group'];
        }

        $ticketUrl = config('app.url')."/tickets/{$ticket->id}";
        $techName = $ticket->assignee ? $ticket->assignee->name : '-';
        $resolvedAt = $ticket->resolved_at ? OpsTime::local($ticket->resolved_at)->format('d/m/Y H:i') : '-';
        $createdAt = $ticket->created_at ? OpsTime::local($ticket->created_at)->format('d/m/Y H:i') : '-';
        $resolutionTime = ($ticket->created_at && $ticket->resolved_at)
            ? Carbon::parse($ticket->resolved_at)->diffForHumans($ticket->created_at, ['parts' => 2])
            : '-';

        $message = "📋 LAPORAN TIKET SELESAI\n\n"
            ."ID Tiket: {$ticket->code}\n"
            .'Gangguan: '.($ticket->gangguan ?: '-')."\n"
            .'Pelanggan: '.($ticket->customer_name ?: '-')."\n"
            ."Teknisi: {$techName}\n"
            ."Dibuat: {$createdAt}\n"
            ."Selesai: {$resolvedAt}\n"
            ."Durasi: {$resolutionTime}\n\n"
            .'Kendala: '.($ticket->kendala ?: '-')."\n"
            .'Tindakan: '.($ticket->action_taken ?: '-')."\n";

        $photos = is_array($ticket->photos) ? $ticket->photos : [];
        if ($photos !== []) {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $message .= "\n📸 Foto:\n";
            foreach (array_slice($photos, 0, 5) as $photo) {
                $url = preg_match('#^https?://#i', $photo)
                    ? $photo
                    : $baseUrl.'/storage/'.ltrim($photo, '/');
                $message .= "- {$url}\n";
            }
        }

        $message .= "\n🔗 Detail: {$ticketUrl}";

        try {
            return $this->sendToGroup($groupId, $message);
        } catch (\Throwable $e) {
            Log::error('[Evolution API] Closed report exception', [
                'ticket' => $ticket->code,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Normalize mention list for Evolution/Baileys.
     * WA only highlights when text has @PHONE and mentioned has matching JID.
     *
     * @lid alone often does NOT ping — prefer phone @s.whatsapp.net.
     *
     * @param  list<string>  $mentionedJids
     * @return array{0: list<string>, 1: list<string>} [fullJids, phoneDigitsOnly]
     */
    public function normalizeMentionJids(array $mentionedJids): array
    {
        $jids = [];
        $phones = [];
        foreach ($mentionedJids as $j) {
            $j = trim((string) $j);
            if ($j === '') {
                continue;
            }
            // skip pure @lid without phone (cannot mention reliably)
            if (str_ends_with(strtolower($j), '@lid') && ! preg_match('/^\d+@/', $j)) {
                // some lids are "12345@lid" — still not phone; keep for best-effort
                if (! str_contains($j, '@')) {
                    $j .= '@lid';
                }
                $jids[] = $j;

                continue;
            }
            if (! str_contains($j, '@')) {
                $digits = preg_replace('/\D+/', '', $j) ?? '';
                if ($digits !== '') {
                    $phones[] = $digits;
                    $jids[] = $digits.'@s.whatsapp.net';
                }

                continue;
            }
            [$user, $domain] = array_pad(explode('@', $j, 2), 2, '');
            $domain = strtolower($domain);
            // Prefer phone JID
            if ($domain === 's.whatsapp.net' || $domain === 'c.us') {
                $digits = preg_replace('/\D+/', '', $user) ?? '';
                if ($digits !== '') {
                    $phones[] = $digits;
                    $jids[] = $digits.'@s.whatsapp.net';
                }
            } else {
                $jids[] = $j;
            }
        }
        $jids = array_values(array_unique($jids));
        $phones = array_values(array_unique($phones));

        return [$jids, $phones];
    }

    /**
     * Ensure message text contains @phone for each mention (required by WhatsApp).
     *
     * @param  list<string>  $phoneDigits
     */
    public function ensureMentionTokensInText(string $message, array $phoneDigits): string
    {
        foreach ($phoneDigits as $ph) {
            if ($ph === '') {
                continue;
            }
            if (! str_contains($message, '@'.$ph)) {
                // prepend tokens so mention works even if caller used @Name only
                $message = '@'.$ph.' '.$message;
            }
        }

        return $message;
    }

    /**
     * Send a text message to a WA group via Evolution API.
     * Reusable for summaries, notifications, etc.
     *
     * @param  list<string>  $mentionedJids  optional JIDs to mention (e.g. 628xxx@s.whatsapp.net)
     * @param  bool  $mentionEveryone  try tag all (Evolution mentionsEveryOne when supported)
     */
    public function sendToGroup(string $groupJid, string $message, array $mentionedJids = [], bool $mentionEveryone = false): array
    {
        if (! $this->isConfigured()) {
            Log::warning('[Evolution API] Not configured for group message');

            return ['success' => false, 'error' => 'Not configured'];
        }

        // Ensure @g.us suffix for group JIDs
        if (! str_ends_with($groupJid, '@g.us')) {
            $groupJid = $groupJid.'@g.us';
        }

        [$mentions, $phones] = $this->normalizeMentionJids($mentionedJids);
        if ($phones !== []) {
            $message = $this->ensureMentionTokensInText($message, $phones);
        }

        try {
            $payload = [
                'number' => $groupJid,
                'text' => $message,
                'delay' => $this->delayBetweenMessages,
                'presence' => 'composing',
            ];
            // Evolution v2 variants: mentioned | mentions | mentionsEveryOne
            if ($mentionEveryone) {
                $payload['mentionsEveryOne'] = true;
            }
            if ($mentions !== []) {
                $payload['mentioned'] = $mentions;
                // some forks expect bare numbers
                $payload['mentions'] = $phones !== [] ? $phones : $mentions;
            }

            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout($this->sendTimeout)->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", $payload);

            $body = $response->json();

            if ($response->successful() && isset($body['key']['id'])) {
                Log::info('[Evolution API] Group message sent', [
                    'group' => $groupJid,
                    'msg_id' => $body['key']['id'],
                    'mentions' => count($mentions),
                    'everyone' => $mentionEveryone,
                ]);

                return ['success' => true, 'message_id' => $body['key']['id']];
            }

            // Retry: strip exotic fields, keep mentioned only
            if (($mentions !== [] || $mentionEveryone) && $response->status() >= 400) {
                Log::warning('[Evolution API] mention payload rejected, retry lean', [
                    'group' => $groupJid,
                    'status' => $response->status(),
                    'body' => is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null,
                ]);
                $lean = [
                    'number' => $groupJid,
                    'text' => $message,
                    'delay' => $this->delayBetweenMessages,
                ];
                if ($mentions !== []) {
                    $lean['mentioned'] = $mentions;
                }
                $response = $this->evoHttp()->withHeaders([
                    'apikey' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout($this->sendTimeout)->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", $lean);
                $body = $response->json();
                if ($response->successful() && isset($body['key']['id'])) {
                    return ['success' => true, 'message_id' => $body['key']['id'], 'mention_lean' => true];
                }
                // Final: plain text (still has @phone in body for visual tag)
                unset($lean['mentioned']);
                $response = $this->evoHttp()->withHeaders([
                    'apikey' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])->timeout($this->sendTimeout)->post("{$this->baseUrl}/message/sendText/{$this->instanceName}", $lean);
                $body = $response->json();
                if ($response->successful() && isset($body['key']['id'])) {
                    return ['success' => true, 'message_id' => $body['key']['id'], 'mention_fallback' => true];
                }
            }

            Log::error('[Evolution API] Group message failed', ['group' => $groupJid, 'response' => $body]);

            return ['success' => false, 'error' => $body['message'] ?? 'Unknown error'];
        } catch (\Exception $e) {
            Log::error('[Evolution API] Group message exception', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send a ticket status change notification to WA group.
     */
    public function sendTicketStatusChangeToGroup(SupportTicket $ticket, string $oldStatus, string $newStatus): array
    {
        $groupId = Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id')
            ?: Setting::where('key', 'ticket_wa_group_id')->value('value');

        if (empty($groupId)) {
            Log::warning('[Evolution API] No WA group ID configured for status change notifications');

            return ['success' => false, 'error' => 'No WA group ID configured'];
        }

        $techName = $ticket->assignee ? $ticket->assignee->name : '-';

        // Count active tickets for summary
        $activeCount = SupportTicket::whereIn('status', ['open', 'assigned', 'in_progress', 'pending'])->count();

        $statusLabels = [
            'open' => 'Open',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ];

        $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newLabel = $statusLabels[$newStatus] ?? $newStatus;

        // Build extra context: action taken + photos
        $actionLine = '';
        if (! empty($ticket->action_taken)) {
            $actionLine = "\n📝 Action: ".$ticket->action_taken;
        }
        $photoLines = '';
        $photos = is_array($ticket->photos) ? $ticket->photos : [];
        if (! empty($photos)) {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $photoLines = "\n\n📸 Foto:\n";
            foreach (array_slice($photos, 0, 5) as $p) {
                $url = $p;
                if (! preg_match('#^https?://#i', $p)) {
                    $url = $baseUrl.'/storage/'.ltrim($p, '/');
                }
                $photoLines .= '  - '.$url."\n";
            }
        }

        $message = "🔄 STATUS TIKET UPDATE\n\n"
            ."ID: {$ticket->code}\n"
            .'Gangguan: '.($ticket->gangguan ?? '-')."\n"
            ."Status: {$oldLabel} → {$newLabel}\n"
            ."Teknisi: {$techName}"
            .$actionLine
            .$photoLines."\n"
            ."📊 Total aktif: {$activeCount} tiket";

        return $this->sendToGroup($groupId, $message);
    }

    /**
     * Send status-change notification to the assigned teknisi (individual).
     * Called alongside group notification so teknisi gets a direct ping.
     */
    public function sendTicketStatusChangeToTeknisi(SupportTicket $ticket, string $oldStatus, string $newStatus): array
    {
        $technician = $ticket->assignee;
        if (! $technician || empty($technician->phone)) {
            return ['success' => false, 'error' => 'No technician or phone number'];
        }

        $statusLabels = [
            'open' => 'Open',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ];
        $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
        $newLabel = $statusLabels[$newStatus] ?? $newStatus;

        $ticketUrl = config('app.url')."/tickets/{$ticket->id}";

        $actionLine = '';
        if (! empty($ticket->action_taken)) {
            $actionLine = "\n📝 Action: ".$ticket->action_taken;
        }
        $photoLines = '';
        $photos = is_array($ticket->photos) ? $ticket->photos : [];
        if (! empty($photos)) {
            $baseUrl = rtrim((string) config('app.url'), '/');
            $photoLines = "\n📸 Foto:\n";
            foreach (array_slice($photos, 0, 5) as $p) {
                $url = $p;
                if (! preg_match('#^https?://#i', $p)) {
                    $url = $baseUrl.'/storage/'.ltrim($p, '/');
                }
                $photoLines .= '  - '.$url."\n";
            }
        }

        $message = "🔄 Update tiket kamu\n\n"
            ."ID: {$ticket->code}\n"
            .'Gangguan: '.($ticket->gangguan ?? '-')."\n"
            ."Status: {$oldLabel} → {$newLabel}"
            .$actionLine
            .$photoLines."\n"
            ."🔗 {$ticketUrl}";

        return $this->send($technician->phone, $message);
    }

    /**
     * Send new-ticket notification to WA group.
     * Called on TicketController::store() regardless of assigned_to.
     */
    public function sendTicketCreatedToGroup(SupportTicket $ticket): array
    {
        $groupId = Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id')
            ?: Setting::where('key', 'ticket_wa_group_id')->value('value');

        if (empty($groupId)) {
            Log::warning('[Evolution API] No WA group ID configured for new-ticket notifications');

            return ['success' => false, 'error' => 'No WA group ID configured'];
        }

        $customer = $ticket->customer;
        $customerName = $customer->name ?? ($ticket->customer_name ?? 'Tanpa Nama');
        $customerPhone = $customer->phone ?? ($ticket->customer_phone ?? '-');
        $priority = strtoupper($ticket->priority ?? 'normal');
        $type = $ticket->type ?? 'incident';
        $assignee = $ticket->assignee ? $ticket->assignee->name : '⏳ Belum di-assign';
        $createdBy = $ticket->creator ? $ticket->creator->name : '-';

        $priorityEmoji = match (true) {
            str_contains($priority, 'URGENT') => '🚨',
            str_contains($priority, 'HIGH') => '⚠️',
            str_contains($priority, 'LOW') => '🟢',
            default => '🔵',
        };

        $message = "🎫 TIKET BARU\n\n"
            ."{$priorityEmoji} {$priority} | ".strtoupper($type)."\n"
            ."ID: {$ticket->code}\n"
            ."Customer: {$customerName} ({$customerPhone})\n"
            .'Gangguan: '.($ticket->gangguan ?? '-')."\n"
            .'Lokasi: '.($ticket->customer_coords ?? '-')."\n\n"
            ."👷 Teknisi: {$assignee}\n"
            ."👤 Dibuat oleh: {$createdBy}\n"
            .'🕐 '.OpsTime::local($ticket->created_at)->format('d/m/Y H:i')."\n\n"
            .'Cek tiket: '.url("/tickets/{$ticket->id}");

        return $this->sendToGroup($groupId, $message);
    }

    public function getQrCode(): ?string
    {
        try {
            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
            ])->get("{$this->baseUrl}/instance/connect/{$this->instanceName}");

            $body = $response->json();
            $raw = $body['base64'] ?? null;

            return is_string($raw) && $raw !== '' ? $this->normalizeBase64Image($raw) : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Fetch all WhatsApp groups the connected instance is part of.
     */
    public function getGroups(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured', 'groups' => []];
        }

        try {
            $response = $this->evoHttp()->withHeaders([
                'apikey' => $this->apiKey,
            ])->get("{$this->baseUrl}/group/fetchAllGroups/{$this->instanceName}");

            if (! $response->successful()) {
                return ['success' => false, 'error' => 'API returned '.$response->status(), 'groups' => []];
            }

            $body = $response->json();

            // Evolution API returns array of groups with id, subject, etc.
            $groups = [];
            if (is_array($body)) {
                foreach ($body as $g) {
                    $groups[] = [
                        'id' => $g['id'] ?? '',
                        'name' => $g['subject'] ?? $g['name'] ?? 'Unknown',
                        'participants' => $g['participants'] ?? [],
                        'created' => $g['creation'] ?? null,
                    ];
                }
            }

            // Sort alphabetically
            usort($groups, fn ($a, $b) => strcmp($a['name'], $b['name']));

            return ['success' => true, 'groups' => $groups];
        } catch (\Exception $e) {
            Log::error('[Evolution API] getGroups failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage(), 'groups' => []];
        }
    }

    public function getConnectionState(): string
    {
        if (! $this->isConfigured()) {
            return 'not_configured';
        }

        $key = $this->cacheKey('state');
        $cached = Cache::get($key);
        // Only trust cached *open* briefly — never keep close/connecting sticky after re-pair.
        if (is_string($cached) && $cached === 'open') {
            return $cached;
        }

        try {
            $response = $this->httpStatus()
                ->get("{$this->baseUrl}/instance/connectionState/{$this->instanceName}");

            $body = $response->json();
            $state = strtolower((string) ($body['instance']['state'] ?? 'unknown'));
            // Evolution sometimes returns "connected" instead of "open".
            if (in_array($state, ['connected', 'online'], true)) {
                $state = 'open';
            }
            if ($state !== 'open') {
                $state = $this->fallbackConnectionStateFromInstances($state);
            }
            // open: cache ~15s for snappy pages; non-open: 2s so UI detects QR scan quickly.
            $ttl = $state === 'open' ? 15 : 2;
            Cache::put($key, $state, now()->addSeconds($ttl));

            return $state;
        } catch (\Exception $e) {
            Cache::put($key, 'error', now()->addSeconds(5));

            return 'error';
        }
    }

    /**
     * Read-only: the paired WhatsApp number of this instance as digits (from fetchInstances owner),
     * or null when unknown / not paired. Callers must mask it before it leaves the server.
     */
    public function fetchOwnerDigits(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpStatus()
                ->get("{$this->baseUrl}/instance/fetchInstances", ['instanceName' => $this->instanceName]);
            $rows = $response->successful() ? $response->json() : null;
            if (! is_array($rows)) {
                return null;
            }
            if (isset($rows['name']) || isset($rows['instance'])) {
                $rows = [$rows];
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row = is_array($row['instance'] ?? null) ? $row['instance'] : $row;
                if ((string) ($row['name'] ?? $row['instanceName'] ?? '') !== $this->instanceName) {
                    continue;
                }
                $raw = (string) ($row['ownerJid'] ?? $row['owner'] ?? '');
                $digits = preg_replace('/\D+/', '', explode('@', explode(':', $raw)[0])[0]) ?? '';

                return $digits !== '' ? $digits : null;
            }
        } catch (\Throwable) {
            // best effort
        }

        return null;
    }

    private function fallbackConnectionStateFromInstances(string $currentState): string
    {
        try {
            $response = $this->httpStatus()
                ->get("{$this->baseUrl}/instance/fetchInstances", [
                    'instanceName' => $this->instanceName,
                ]);

            if (! $response->successful()) {
                return $currentState;
            }

            $instances = $response->json();
            if (! is_array($instances)) {
                return $currentState;
            }

            foreach ($instances as $instance) {
                if (! is_array($instance)) {
                    continue;
                }
                $name = (string) ($instance['name'] ?? '');
                if ($name !== $this->instanceName) {
                    continue;
                }
                $status = strtolower((string) ($instance['connectionStatus'] ?? ''));
                if (in_array($status, ['open', 'connected', 'online'], true)) {
                    return 'open';
                }

                return $status !== '' ? $status : $currentState;
            }
        } catch (\Throwable $e) {
            Log::debug('[Evolution API] fetchInstances fallback failed', [
                'instance' => $this->instanceName,
                'error' => $e->getMessage(),
            ]);
        }

        return $currentState;
    }

    public function disconnectInstance(): array
    {
        if (! $this->isInstanceConnected()) {
            return [
                'success' => true,
                'state' => $this->getConnectionState(),
                'message' => 'Instance sudah tidak terhubung',
            ];
        }

        try {
            $this->forgetStatusCache();
            $response = $this->evoHttp(15)->withHeaders([
                'apikey' => $this->apiKey,
            ])->delete("{$this->baseUrl}/instance/logout/{$this->instanceName}");

            if (! $response->successful()) {
                $body = $response->json();
                $errMsg = is_array($body['response']['message'] ?? null)
                    ? implode('; ', $body['response']['message'])
                    : ($body['message'] ?? ($body['response']['message'] ?? 'Logout gagal'));

                if ($this->isAlreadyDisconnectedError($errMsg)) {
                    return [
                        'success' => true,
                        'state' => $this->getConnectionState(),
                        'message' => 'Instance sudah tidak terhubung',
                    ];
                }

                return ['success' => false, 'error' => $errMsg, 'status' => $response->status()];
            }

            return ['success' => true, 'state' => $this->getConnectionState()];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Logout device lalu ambil QR baru — untuk pairing ulang dari UI.
     */
    public function rePairInstance(): array
    {
        if ($this->isInstanceConnected()) {
            $logout = $this->disconnectInstance();
            if (! ($logout['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $logout['error'] ?? 'Gagal logout instance',
                ];
            }

            // Evolution butuh sedikit waktu sebelum state berubah ke close.
            for ($i = 0; $i < 5; $i++) {
                if (! $this->isInstanceConnected()) {
                    break;
                }
                usleep(500000);
            }
        }

        $connect = $this->connectInstance();
        if ($connect['qr_code'] ?? null) {
            return [
                'success' => true,
                'instance' => $this->instanceName,
                'state' => 'close',
                'qr_code' => $connect['qr_code'],
                'message' => 'QR siap discan.',
            ];
        }

        if (($connect['state'] ?? '') === 'connected') {
            return [
                'success' => true,
                'instance' => $this->instanceName,
                'state' => 'open',
                'message' => 'Instance masih terhubung setelah logout — coba refresh.',
            ];
        }

        return [
            'success' => false,
            'instance' => $this->instanceName,
            'state' => $connect['state'] ?? 'unknown',
            'error' => $connect['error'] ?? 'QR belum tersedia — coba Buat / Refresh QR.',
        ];
    }

    private function isAlreadyDisconnectedError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'not connected')
            || str_contains($normalized, 'is not connect')
            || str_contains($normalized, 'already disconnected')
            || str_contains($normalized, 'not logged in');
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function sanitizeConnectLogContext(?array $body): array
    {
        if (! is_array($body)) {
            return [];
        }

        $safe = $body;
        if (isset($safe['base64']) && is_string($safe['base64'])) {
            $safe['base64'] = '[redacted len='.strlen($safe['base64']).']';
        }

        return $safe;
    }

    /**
     * Evolution v2 may return raw base64 or a full data URI — normalize to raw base64 for API consumers.
     */
    private function normalizeBase64Image(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, ',')) {
            return substr($value, (int) strrpos($value, ',') + 1);
        }

        return $value;
    }

    /**
     * Read-only: groups this instance is really in, without participants
     * (fetchAllGroups?getParticipants=false). Unlike fetchGroups() it never uses
     * chat/findChats (which can list groups the number already left) and is not cached.
     *
     * @return array{success: bool, error: ?string, groups: array<int, array<string, mixed>>}
     */
    public function listGroupsLight(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured', 'groups' => []];
        }

        try {
            $response = $this->evoHttp()->withHeaders(['apikey' => $this->apiKey])
                ->get("{$this->baseUrl}/group/fetchAllGroups/{$this->instanceName}?getParticipants=false");
            if (! $response->successful()) {
                return ['success' => false, 'error' => 'API returned '.$response->status(), 'groups' => []];
            }

            return ['success' => true, 'error' => null, 'groups' => $this->normalizeGroupRows($this->unwrapGroupList($response->json()))];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e::class, 'groups' => []];
        }
    }

    /**
     * Read-only, uncached: group list from chat/findChats with a short timeout (12 s). Faster than
     * fetchAllGroups on big instances but may include groups the number already left, so it is only
     * a picker source (membership is checked separately). Additive: existing callers are unchanged.
     *
     * @return array{success: bool, error: ?string, groups: array<int, array<string, mixed>>}
     */
    public function listGroupsViaChats(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'Not configured', 'groups' => []];
        }

        try {
            $response = Http::timeout(12)
                ->connectTimeout(3)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/chat/findChats/{$this->instanceName}", new \stdClass);
            if (! $response->successful()) {
                return ['success' => false, 'error' => 'API returned '.$response->status(), 'groups' => []];
            }

            return ['success' => true, 'error' => null, 'groups' => $this->normalizeGroupRows($this->unwrapGroupList($response->json()), true)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e::class, 'groups' => []];
        }
    }

    /**
     * Read-only membership probe: is this instance a member of the group?
     * member: true / false / null (null = could not tell). Never sends anything.
     *
     * @return array{member: ?bool, subject: ?string, size: ?int, error: ?string}
     */
    public function groupMembership(string $groupJid): array
    {
        $result = ['member' => null, 'subject' => null, 'size' => null, 'error' => null];
        if (! $this->isConfigured()) {
            $result['error'] = 'Not configured';

            return $result;
        }
        if (! str_ends_with($groupJid, '@g.us')) {
            $groupJid .= '@g.us';
        }
        $clientError = false;

        try {
            $response = $this->evoHttp()->withHeaders(['apikey' => $this->apiKey])
                ->get("{$this->baseUrl}/group/findGroupInfos/{$this->instanceName}", ['groupJid' => $groupJid]);
            $body = $response->json();
            if ($response->successful() && is_array($body) && ! empty($body['id'])) {
                $result['member'] = true;
                $result['subject'] = isset($body['subject']) ? (string) $body['subject'] : null;
                $result['size'] = isset($body['size']) ? (int) $body['size'] : (is_countable($body['participants'] ?? null) ? count($body['participants']) : null);

                return $result;
            }
            // A 4xx may mean "not a participant", but also an unsupported route/parameter on this
            // Evolution build: the group list decides (below) before we ever say "not a member".
            $clientError = $response->status() >= 400 && $response->status() < 500;
            $result['error'] = 'API returned '.$response->status();
        } catch (\Throwable $e) {
            $result['error'] = $e::class;
        }

        // Fallback: the instance's own group list (membership only when it is listed).
        $list = $this->listGroupsLight();
        foreach ($list['groups'] as $group) {
            if (($group['id'] ?? '') === $groupJid) {
                $result['member'] = true;
                $result['subject'] = (string) ($group['subject'] ?? $group['name'] ?? '');
                $result['size'] = isset($group['members']) ? (int) $group['members'] : null;
                $result['error'] = null;

                return $result;
            }
        }

        // Only "the list loaded and the group is not in it" is a definite no.
        if ($clientError && $list['success']) {
            $result['member'] = false;
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchGroupsFromChats(): array
    {
        try {
            $response = Http::timeout(max(20, $this->groupsTimeout))
                ->connectTimeout(3)
                ->withHeaders(['apikey' => $this->apiKey])
                ->post("{$this->baseUrl}/chat/findChats/{$this->instanceName}", new \stdClass);

            if (! $response->successful()) {
                Log::debug('[Evolution API] Chat group fallback returned non-2xx', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 200),
                ]);

                return [];
            }

            $groups = $this->normalizeGroupRows($this->unwrapGroupList($response->json()), true);
            Log::info('[Evolution API] Fetched groups from chats fallback', ['count' => count($groups)]);

            return $groups;
        } catch (\Exception $e) {
            Log::debug('[Evolution API] Chat group fallback failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGroupRows(array $groups, bool $chatSource = false): array
    {
        $normalized = [];

        foreach ($groups as $g) {
            if (! is_array($g)) {
                continue;
            }

            $id = $g['remoteJid'] ?? $g['id'] ?? $g['jid'] ?? '';
            if (! is_string($id) || ! str_contains($id, '@g.us')) {
                continue;
            }

            $name = $g['subject'] ?? $g['name'] ?? null;
            // chat/findChats group records only: the chat-level pushName is the group's display name
            // (its subject). Never used for other sources, where pushName can be a person's name.
            if ($name === null && $chatSource && is_string($g['pushName'] ?? null) && trim($g['pushName']) !== '') {
                $name = trim($g['pushName']);
            }
            $name ??= $id;
            $participants = $g['participants'] ?? null;
            $members = $g['size'] ?? (is_countable($participants) ? count($participants) : ($g['members'] ?? 0));

            $normalized[$id] = array_merge($g, [
                'id' => $id,
                'subject' => $g['subject'] ?? $name,
                'name' => $name,
                'members' => $members,
            ]);
        }

        usort($normalized, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return array_values($normalized);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function unwrapGroupList(mixed $body): array
    {
        if (! is_array($body) || $body === []) {
            return [];
        }

        if (isset($body[0]) && is_array($body[0])) {
            return $body;
        }

        foreach (['data', 'response', 'groups', 'result'] as $key) {
            if (isset($body[$key]) && is_array($body[$key]) && $body[$key] !== []) {
                return $body[$key];
            }
        }

        return [];
    }
}
