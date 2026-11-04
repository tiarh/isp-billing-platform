<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use App\Jobs\ProcessRecapTopicCommandJob;
use App\Jobs\ProcessReportPppoeCariJob;
use App\Models\Attendance;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\TechnicianMember;
use App\Services\AttendanceExportService;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\EvolutionApiService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbRecapCommandParser;
use App\Services\PsbWaMessages;
use App\Services\Recap\RecapTopics;
use App\Services\Report\ReportGroupRegistry;
use App\Services\TechnicianProgressService;
use App\Services\Wa\WaWebhookTimer;
use App\Services\WaChatIntelligenceService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AttendanceController extends Controller
{
    /** Measure-only timer of the webhook request in flight (null outside the webhook). */
    private ?WaWebhookTimer $webhookTimer = null;

    public function export(Request $request, AttendanceExportService $exportService): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        Log::info('attendance.export', [
            'ip' => $request->ip(),
            'date' => $request->query('date'),
            'from' => $request->query('from'),
            'to' => $request->query('to'),
        ]);

        return response()->json($exportService->export($request));
    }

    public function index(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 50), 1), 500);

        // Slim columns only — keep HTML data-page small.
        $attendances = Attendance::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($limit, [
                'id', 'sender_number', 'sender_name', 'caption', 'photo_url',
                'created_at', 'updated_at', 'message_id',
            ])
            ->withQueryString();

        $attendanceGroupId = Setting::get('attendance_group_id', '');
        $attendanceGroupLabel = Setting::get('attendance_group_label', '');

        return Inertia::render('Attendances/Index', [
            'attendances' => $attendances,
            'attendanceGroupId' => $attendanceGroupId,
            'attendanceGroupLabel' => $attendanceGroupLabel,
            // The group is edited on Pengaturan > Grup WhatsApp (superadmin + admin without area scope);
            // the link only shows for the roles that page admits.
            'canManageSettings' => (bool) ($request->user()?->isSuperAdmin()
                || ($request->user()?->isAdmin() && ! $request->user()->hasAreaScope())),
        ]);
    }

    public function getSettings()
    {
        return response()->json([
            'attendance_group_id' => Setting::get('attendance_group_id', ''),
            'attendance_group_label' => Setting::get('attendance_group_label', ''),
        ]);
    }

    public function saveSettings(Request $request)
    {
        // Single source of truth: the attendance group + label are written only by WaGroupSettingsWriter
        // (Pengaturan > Grup WhatsApp). A stale browser tab gets a clear message, never a silent write.
        return response()->json([
            'status' => 'moved',
            'error' => 'Pilihan grup absensi dipindah ke Pengaturan › Grup WhatsApp.',
        ], 410);
    }

    public function fetchGroups(EvolutionApiService $evolution): JsonResponse
    {
        $evolution = $evolution->forChannel('ops');

        if (! $evolution->isConfigured()) {
            return response()->json([
                'groups' => [],
                'configured' => false,
                'connected' => false,
                'state' => 'not_configured',
                'message' => 'Evolution API belum dikonfigurasi.',
            ]);
        }

        if (request()->boolean('fresh')) {
            $evolution->forgetStatusCache();
        }

        // One cached state read (not two separate HTTP calls).
        $state = $evolution->getConnectionState();

        // Whitelist only what the UI needs; raw Evolution rows carry member phone
        // numbers (participants), last message content and pushName.
        $groups = [];
        foreach ($evolution->fetchGroups() as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_string($row['id'])) {
                continue;
            }
            $groups[] = [
                'id' => $row['id'],
                'subject' => is_string($row['subject'] ?? null) ? $row['subject'] : null,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : null,
                'members' => is_numeric($row['members'] ?? null) ? (int) $row['members'] : 0,
            ];
        }

        return response()->json([
            'groups' => $groups,
            'configured' => true,
            'connected' => $state === 'open',
            'state' => $state,
        ]);
    }

    /**
     * Measure-only wrapper (plan #7 phase 0): times the request, never changes the response.
     */
    public function evolutionWebhook(Request $request)
    {
        $this->webhookTimer = WaWebhookTimer::start($request);
        try {
            $response = $this->handleEvolutionWebhook($request);
        } catch (\Throwable $e) {
            $this->webhookTimer->finish(500);
            $this->webhookTimer = null;

            throw $e;
        }
        $this->webhookTimer->finishResponse($response);
        $this->webhookTimer = null;

        return $response;
    }

    private function handleEvolutionWebhook(Request $request)
    {
        Cache::put('psb_wa:last_webhook_attempt_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
        if (! $this->verifyEvolutionWebhook($request)) {
            Cache::put('psb_wa:last_webhook_rejected_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
            Cache::put('psb_wa:last_webhook_rejected_error', 'Unauthorized webhook token/header', now()->addDays(7));
            Cache::increment('psb_wa:webhook_rejected_count');
            Log::warning('Attendance webhook rejected: invalid signature/token', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->all();
        $event = strtolower(str_replace('_', '.', (string) ($data['event'] ?? '')));
        Cache::put('psb_wa:last_webhook_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));

        if ($event !== 'messages.upsert') {
            Cache::put('psb_wa:webhook_status', 'OK', now()->addDays(7));
            Cache::put('psb_wa:last_webhook_ok_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
            Cache::put('psb_wa:last_webhook_event', $event ?: 'empty', now()->addDays(7));
            Cache::forget('psb_wa:last_webhook_error');

            return response()->json(['status' => 'ignored', 'event' => $event]);
        }

        $messages = $this->extractWebhookMessages($data);
        Cache::put('psb_wa:webhook_status', 'OK', now()->addDays(7));
        Cache::put('psb_wa:last_webhook_ok_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
        Cache::forget('psb_wa:last_webhook_error');
        $allowedGroupId = Setting::get('attendance_group_id', '');
        $psb = app(PsbOrchestratorService::class);
        $parser = app(PsbMessageParser::class);
        $migrationParser = app(CustomerMigrationMessageParser::class);
        $technicianProgress = app(TechnicianProgressService::class);
        $chatIntelligence = app(WaChatIntelligenceService::class);
        $processingFailures = [];

        foreach ($messages as $msg) {
            if (! is_array($msg) || ! is_array($msg['key'] ?? null) || ! is_array($msg['message'] ?? null)) {
                Log::warning('Evolution webhook skipped malformed message item', [
                    'event' => $event,
                    'item_type' => get_debug_type($msg),
                ]);

                continue;
            }

            $this->webhookTimer?->message($msg);
            $remoteJid = $msg['key']['remoteJid'] ?? '';
            $pushName = $msg['pushName'] ?? $data['pushName'] ?? 'Unknown';
            $this->webhookTimer?->begin('chat_ms');
            try {
                $chatIntelligence->observeWebhookMessage($msg, (string) $pushName);
            } catch (\Throwable $e) {
                Log::warning('[WA Chat Intelligence] observe failed', [
                    'message_id' => $msg['key']['id'] ?? null,
                    'remote_jid' => $remoteJid,
                    'error' => $e->getMessage(),
                ]);
            }
            $this->webhookTimer?->end('chat_ms');

            // --- PSB WA bot (#psb only, allowlisted groups; settings live via Setting::get) ---
            $remoteNorm = $psb->normalizeGroupJid((string) $remoteJid);
            if ($psb->isAllowedGroup((string) $remoteJid) || ($remoteNorm !== '' && $psb->isAllowedGroup($remoteNorm))) {
                $this->webhookTimer?->begin('psb_branch_ms');
                try {
                    $botText = $this->extractBotText($msg);
                    $psbParsed = $parser->parse($botText);
                    if ($psbParsed['is_ktp_cmd'] ?? false) {
                        $this->webhookTimer?->branchKtp($msg);
                        // Explicit #psb ktp #ID must not be consumed by an active migration photo fallback.
                        $this->rememberAlbumOtherFlow(
                            $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid,
                            $this->albumParentKey($msg, $this->unwrapMessageContent($msg['message'] ?? []))
                        );
                        $this->routePsbMessage($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, $parser);
                    } elseif ($migrationParser->isBotTrigger($botText)) {
                        $this->webhookTimer?->branch('migration_cmd');
                        $this->routeMigrationMessage($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, $migrationParser);
                    } elseif ($this->isPsbRecapCommand($msg, $botText, $parser, $migrationParser)) {
                        // "#rekap psb ..." (read-only recap, flag-gated). Own try/catch inside:
                        // a failure here never reaches $processingFailures / 503.
                        $this->webhookTimer?->branch('psb_recap');
                        $this->routePsbRecapCommand($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid);
                    } elseif ($this->isRecapTopicCommand($msg, $botText, $parser, $migrationParser)) {
                        // "#rekap onu ..." (read-only topic recap, own flag). Own try/catch inside: never a 503.
                        $this->webhookTimer?->branch('recap_topic');
                        $this->routeRecapTopicCommand($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid);
                    } elseif ($this->tryAttachMigrationFollowUpPhoto($msg, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, $parser)) {
                        // Foto lanjutan untuk #migrasi aktif; jangan lempar ke PSB.
                        $this->webhookTimer?->branch('migration_photo');
                    } else {
                        $this->webhookTimer?->branch('psb');
                        $this->routePsbMessage($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, $parser);
                    }
                } catch (\Throwable $e) {
                    Log::error('PSB webhook processing failed: '.$e->getMessage(), [
                        'message_id' => $msg['key']['id'] ?? null,
                        'remote_jid' => $remoteJid,
                    ]);
                    $processingFailures[] = [
                        'message_id' => $msg['key']['id'] ?? null,
                        'path' => 'psb',
                    ];
                }
                $this->webhookTimer?->end('psb_branch_ms');
            } elseif ($this->isReportGroupPppoeCari($msg, (string) $remoteJid, $remoteNorm, $migrationParser)) {
                $this->webhookTimer?->branch('report_pppoe');
                // Report group: ONLY an explicit single-line "#pppoe cari ..." (flag report_pppoe_cmd_enabled).
                // Own try/catch inside; never replies through the PSB path.
                $this->routeReportPppoeCari($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid);
            } elseif ($this->isReportGroupRecapCommand($msg, (string) $remoteJid, $remoteNorm, $parser, $migrationParser)) {
                // "Skynet Report" group: ONLY the read-only "#rekap psb ..." command. #psb / #migrasi
                // never run here (they fall through to the log-only branch below). Own try/catch
                // inside; no $processingFailures and no `continue` (technician observation unchanged).
                $this->webhookTimer?->branch('report_recap');
                $this->routePsbRecapCommand($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, 'report');
            } elseif ($this->isReportGroupRecapTopicCommand($msg, (string) $remoteJid, $remoteNorm, $parser, $migrationParser)) {
                // "Skynet Report" group: "#rekap onu ..." (topic recap). Same guarantees as the psb recap branch.
                $this->webhookTimer?->branch('report_topic');
                $this->routeRecapTopicCommand($msg, $pushName, $remoteNorm !== '' ? $remoteNorm : (string) $remoteJid, 'report');
            } elseif (
                str_contains((string) $remoteJid, '@g.us')
                && (
                    $parser->isBotTrigger($this->extractBotText($msg))
                    || $migrationParser->isBotTrigger($this->extractBotText($msg))
                )
            ) {
                // #psb di grup yang belum di-settings — log saja (tidak spam balasan ke grup asing)
                $this->webhookTimer?->branch('unlisted_trigger');
                Log::warning('[PSB] #psb ignored — group not in psb_wa_group_ids (save settings dulu)', [
                    'remote_jid' => $remoteJid,
                    'remote_norm' => $remoteNorm,
                    'allowed' => $psb->allowedGroupJids(),
                ]);
            }

            // --- Technician progress bot: KERJAAN for input, SKYNET REPORT for technician reports/photos.
            if ($technicianProgress->isObservedGroup((string) $remoteJid)) {
                $this->webhookTimer?->branch('technician');
                $this->webhookTimer?->begin('technician_ms');
                try {
                    $technicianProgress->handleWebhookMessage($msg, $pushName);
                } catch (\Throwable $e) {
                    Log::error('Technician progress webhook processing failed: '.$e->getMessage(), [
                        'message_id' => $msg['key']['id'] ?? null,
                        'remote_jid' => $remoteJid,
                    ]);
                }
                $this->webhookTimer?->end('technician_ms');

                continue;
            }

            // --- Attendance (image-only, configured group) ---
            if ($allowedGroupId && $allowedGroupId !== '') {
                if ($remoteJid !== $allowedGroupId) {
                    continue;
                }
            } elseif (! str_contains($remoteJid, '@g.us')) {
                continue;
            }

            $this->webhookTimer?->branch('attendance');
            $this->webhookTimer?->begin('attendance_ms');
            try {
                $this->processAttendanceMessage($msg, $pushName);
            } catch (\Throwable $e) {
                Log::error('Attendance webhook processing failed: '.$e->getMessage(), [
                    'message_id' => $msg['key']['id'] ?? null,
                    'remote_jid' => $remoteJid,
                ]);
            }
            $this->webhookTimer?->end('attendance_ms');
        }

        if ($processingFailures !== []) {
            Cache::put('psb_wa:webhook_status', 'error', now()->addDays(7));
            Cache::put('psb_wa:last_webhook_error', 'processing_failed', now()->addDays(7));

            return response()->json([
                'status' => 'retry',
                'processed' => count($messages),
                'failed' => count($processingFailures),
            ], 503);
        }

        return response()->json(['status' => 'ok', 'processed' => count($messages)]);
    }

    /**
     * Dispatch #psb from group chat (text and/or image caption + media).
     */
    protected function routePsbMessage(array $msg, string $pushName, string $remoteJid, PsbMessageParser $parser): void
    {
        if (! empty($msg['key']['fromMe'])) {
            Log::debug('[PSB] skip fromMe', ['group' => $remoteJid]);

            return;
        }

        $msgContent = $msg['message'] ?? [];
        // Unwrap ephemeral / viewOnce / documentWithCaption wrappers (Evolution / WA)
        if (! empty($msgContent['ephemeralMessage']['message']) && is_array($msgContent['ephemeralMessage']['message'])) {
            $msgContent = $msgContent['ephemeralMessage']['message'];
        }
        if (! empty($msgContent['viewOnceMessage']['message']) && is_array($msgContent['viewOnceMessage']['message'])) {
            $msgContent = $msgContent['viewOnceMessage']['message'];
        }
        if (! empty($msgContent['viewOnceMessageV2']['message']) && is_array($msgContent['viewOnceMessageV2']['message'])) {
            $msgContent = $msgContent['viewOnceMessageV2']['message'];
        }

        $text = '';
        if (! empty($msgContent['conversation'])) {
            $text = (string) $msgContent['conversation'];
        } elseif (! empty($msgContent['extendedTextMessage']['text'])) {
            $text = (string) $msgContent['extendedTextMessage']['text'];
        } elseif (! empty($msgContent['imageMessage']['caption'])) {
            $text = (string) $msgContent['imageMessage']['caption'];
        } elseif (! empty($msgContent['documentWithCaptionMessage']['message']['documentMessage']['caption'])) {
            $text = (string) $msgContent['documentWithCaptionMessage']['message']['documentMessage']['caption'];
        } elseif (! empty($msgContent['documentMessage']['caption'])) {
            $text = (string) $msgContent['documentMessage']['caption'];
        }

        $messageId = $msg['key']['id'] ?? null;
        // participant often @lid (privacy); participantAlt / participantPn = real phone for mentions
        $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
        $senderJidPn = $msg['key']['participantAlt']
            ?? $msg['key']['participantPn']
            ?? $msg['participantAlt']
            ?? $msg['senderPn']
            ?? null;
        if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
            $senderJidPn .= '@s.whatsapp.net';
        }
        // Prefer phone JID as primary when available (mentions + merge draft by sender)
        $senderForDraft = (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : $senderJid;

        $parsedText = $parser->parse($text);
        if (($parsedText['is_psb'] ?? false) || ($parsedText['is_ktp_cmd'] ?? false)) {
            // Real PSB intake (text has #psb, incl. "#psb ktp #ID"): its unlabeled photos are PSB's,
            // never an open migration's. Other bot triggers (sudah, #pppoe, #status...) do not set this.
            $this->rememberPsbIntake((string) $remoteJid, (string) $senderForDraft);
        }
        if ($parsedText['is_ktp_cmd'] ?? false) {
            if ($this->hasInboundMedia($msgContent) && $messageId) {
                $this->attachPsbKtpByDraftId(
                    $msg,
                    $remoteJid,
                    (string) $senderForDraft,
                    (string) $messageId,
                    (int) ($parsedText['draft_id'] ?? 0)
                );
            } else {
                app(PsbOrchestratorService::class)->handleInbound([
                    'group_jid' => $remoteJid,
                    'sender_jid' => $senderForDraft,
                    'message_id' => $messageId,
                    'text' => $text,
                ]);
            }

            return;
        }

        // An unlabeled photo may follow an explicit #psb anchor. It is never
        // attached through the generic recent-sender merge heuristic.
        if (! $parser->isBotTrigger($text)) {
            if ($this->hasInboundMedia($msgContent) && $messageId) {
                $path = $this->storePsbMediaOnce($msg, (string) $messageId);
                if ($path !== null) {
                    $anchor = $this->psbAnchor((string) $remoteJid, (string) $senderForDraft);
                    if ($anchor !== null) {
                        $this->dispatchPsbFollowUp($remoteJid, $senderForDraft, $pushName, $anchor, $path, (string) $messageId, $msgContent);
                    } else {
                        // Buffer only briefly; it is consumed only after the
                        // same sender sends an explicit #psb command.
                        $this->bufferPsbMedia((string) $remoteJid, (string) $senderForDraft, $path, (string) $messageId, $msgContent);
                    }
                }
            }
            if ($parser->isNearMissPsb($text)) {
                try {
                    app(PsbOrchestratorService::class)
                        ->replyNearMiss($remoteJid, (string) $senderForDraft);
                } catch (\Throwable $e) {
                    Log::warning('[PSB] near-miss reply failed', ['e' => $e->getMessage()]);
                }
            }

            return;
        }

        $imagePaths = [];
        $mediaContexts = [];
        if ($this->hasInboundMedia($msgContent) && $messageId) {
            $path = $this->storePsbMediaOnce($msg, (string) $messageId);
            if ($path !== null) {
                $imagePaths[] = $path;
                $mediaContexts[] = $this->psbMediaContext($path, (string) $messageId, (string) $senderForDraft, $msgContent);
            }
        }

        $anchor = (string) ($messageId ?: sha1($remoteJid.'|'.$senderForDraft.'|'.$text));
        $this->rememberPsbAnchor((string) $remoteJid, (string) $senderForDraft, $anchor);
        foreach ($this->consumeBufferedPsbMedia((string) $remoteJid, (string) $senderForDraft) as $item) {
            $imagePaths[] = $item['path'];
            $mediaContexts[] = $item['context'];
        }

        Log::info('[PSB] dispatch ProcessPsbWaJob', [
            'group' => $remoteJid,
            'message_id' => $messageId,
            'text_preview' => mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 80),
            'images' => count($imagePaths),
            'sender' => $senderForDraft,
            'sender_lid' => $senderJid,
        ]);
        Cache::put('psb_wa:last_psb_inbound_at', now('Asia/Jakarta')->toIso8601String(), now()->addDays(7));
        Cache::put('psb_wa:last_psb_inbound_message_id', $messageId, now()->addDays(7));

        ProcessPsbWaJob::dispatch([
            'group_jid' => $remoteJid,
            'sender_jid' => $senderForDraft,
            'sender_jid_lid' => $senderJid,
            'sender_jid_pn' => $senderJidPn,
            'sender_name' => $pushName,
            'message_id' => $messageId,
            'text' => $text,
            'image_paths' => $imagePaths,
            'media_contexts' => $mediaContexts,
        ]);
    }

    private function psbMediaKey(string $groupJid, string $senderJid): string
    {
        return 'psb:media-batch:'.sha1(strtolower(trim($groupJid)).'|'.strtolower(trim($senderJid)));
    }

    private function storePsbMediaOnce(array $msg, string $messageId): ?string
    {
        $seenKey = 'psb:media-seen:'.sha1($messageId);
        if (! Cache::add($seenKey, 1, now()->addMinutes(15))) {
            return null;
        }
        $bytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
        if (! is_string($bytes) || strlen($bytes) < 100) {
            Cache::forget($seenKey);

            return null;
        }
        $rel = 'psb/inbox/'.date('Y/m').'/'.sha1($messageId).'.jpg';
        Storage::disk(config('filesystems.default'))->put($rel, $bytes);

        return $rel;
    }

    private function psbMediaContext(string $path, string $messageId, string $senderJid, array $content): array
    {
        $mime = $content['imageMessage']['mimetype']
            ?? $content['documentMessage']['mimetype']
            ?? $content['documentWithCaptionMessage']['message']['documentMessage']['mimetype']
            ?? 'application/octet-stream';

        return [
            'path' => $path,
            'message_id' => $messageId,
            'sender' => $senderJid,
            'received_at' => now('Asia/Jakarta')->toIso8601String(),
            'mime' => (string) $mime,
        ];
    }

    private function psbIntakeKey(string $groupJid, string $senderJid): string
    {
        return 'wa:psb-intake:'.sha1(strtolower(trim($groupJid)).'|'.strtolower(trim($senderJid)));
    }

    private function rememberPsbIntake(string $groupJid, string $senderJid): void
    {
        Cache::put($this->psbIntakeKey($groupJid, $senderJid), 1, now()->addSeconds(120));
    }

    private function rememberPsbAnchor(string $groupJid, string $senderJid, string $anchor): void
    {
        Cache::put($this->psbMediaKey($groupJid, $senderJid).':anchor', [
            'message_id' => $anchor,
            'expires_at' => now()->addSeconds(120)->toIso8601String(),
        ], now()->addSeconds(120));
    }

    private function psbAnchor(string $groupJid, string $senderJid): ?string
    {
        $value = Cache::get($this->psbMediaKey($groupJid, $senderJid).':anchor');
        if (! is_array($value) || empty($value['message_id'])) {
            return null;
        }

        return (string) $value['message_id'];
    }

    private function bufferPsbMedia(string $groupJid, string $senderJid, string $path, string $messageId, array $content): void
    {
        $key = $this->psbMediaKey($groupJid, $senderJid).':pending';
        $items = Cache::get($key, []);
        $items[] = ['path' => $path, 'context' => $this->psbMediaContext($path, $messageId, $senderJid, $content)];
        Cache::put($key, array_slice($items, -5), now()->addSeconds(120));
    }

    private function consumeBufferedPsbMedia(string $groupJid, string $senderJid): array
    {
        $key = $this->psbMediaKey($groupJid, $senderJid).':pending';
        $items = Cache::pull($key, []);

        return is_array($items) ? $items : [];
    }

    private function dispatchPsbFollowUp(string $groupJid, string $senderJid, string $pushName, string $anchor, string $path, string $messageId, array $content): void
    {
        ProcessPsbWaJob::dispatch([
            'group_jid' => $groupJid,
            'sender_jid' => $senderJid,
            'sender_name' => $pushName,
            'message_id' => $messageId,
            'text' => '',
            'image_paths' => [$path],
            'media_contexts' => [$this->psbMediaContext($path, $messageId, $senderJid, $content)],
            'follow_up_anchor_message_id' => $anchor,
        ]);
    }

    /**
     * Teknisi sering kirim: #psb (teks) → foto KTP → foto SN (pesan terpisah).
     * Tanpa ini hanya 1 imageMessage ter-capture dan bisa masuk ke kolom KTP.
     */
    /**
     * Dispatch #migrasi from the same activation allowlist as #psb.
     */
    protected function routeMigrationMessage(array $msg, string $pushName, string $remoteJid, CustomerMigrationMessageParser $parser): void
    {
        if (! empty($msg['key']['fromMe'])) {
            Log::debug('[MIGRASI] skip fromMe', ['group' => $remoteJid]);

            return;
        }

        $msgContent = $this->unwrapMessageContent($msg['message'] ?? []);
        $text = $this->extractBotText($msg);
        if (! $parser->isBotTrigger($text)) {
            return;
        }

        $messageId = $msg['key']['id'] ?? null;
        $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
        $senderJidPn = $msg['key']['participantAlt']
            ?? $msg['key']['participantPn']
            ?? $msg['participantAlt']
            ?? $msg['senderPn']
            ?? null;
        if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
            $senderJidPn .= '@s.whatsapp.net';
        }
        $senderForDraft = (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : $senderJid;

        $imagePaths = [];
        if ($this->hasInboundMedia($msgContent) && $messageId) {
            $bytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
            if ($bytes) {
                $rel = 'migrasi/inbox/'.date('Y/m').'/'.uniqid('wa_', true).'.jpg';
                Storage::disk(config('filesystems.default'))->put($rel, $bytes);
                $imagePaths[] = $rel;
            }
        }

        Log::info('[MIGRASI] dispatch ProcessCustomerMigrationWaJob', [
            'group' => $remoteJid,
            'message_id' => $messageId,
            'text_preview' => mb_substr(preg_replace('/\s+/', ' ', $text) ?? $text, 0, 100),
            'images' => count($imagePaths),
            'sender' => $senderForDraft,
        ]);

        ProcessCustomerMigrationWaJob::dispatch([
            'group_jid' => $remoteJid,
            'sender_jid' => $senderForDraft,
            'sender_jid_lid' => $senderJid,
            'sender_jid_pn' => $senderJidPn,
            'sender_name' => $pushName,
            'message_id' => $messageId,
            'text' => $text,
            'image_paths' => $imagePaths,
        ]);
    }

    /**
     * Conservative gate for "#rekap psb ...": every message that PSB or migration
     * would take keeps its old path. Never throws (false on any error).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function isPsbRecapCommand(array $msg, string $text, PsbMessageParser $parser, CustomerMigrationMessageParser $migrationParser): bool
    {
        try {
            if ($parser->isBotTrigger($text) || $migrationParser->isBotTrigger($text)) {
                return false;
            }
            if ($this->hasInboundMedia($this->unwrapMessageContent($msg['message'] ?? []))) {
                return false;
            }
            if (! app(PsbRecapCommandParser::class)->matches($text)) {
                return false;
            }

            return ProcessPsbRecapCommandJob::isEnabled();
        } catch (\Throwable $e) {
            Log::warning('[PSB-RECAP] gate check failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Gate for "#rekap psb ..." inside the Skynet Report group (never the PSB group: that
     * branch is checked first). Rejects everything PSB / migration would take, media, and
     * anything that is not a pure recap command. Never throws (false on any error).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function isReportGroupRecapCommand(array $msg, string $remoteJid, string $remoteNorm, PsbMessageParser $parser, CustomerMigrationMessageParser $migrationParser): bool
    {
        try {
            $registry = app(ReportGroupRegistry::class);
            if (! $registry->isReportGroup($remoteJid) && ! ($remoteNorm !== '' && $registry->isReportGroup($remoteNorm))) {
                return false;
            }
            $text = $this->extractBotText($msg);
            if ($parser->isBotTrigger($text) || $migrationParser->isBotTrigger($text)) {
                return false;
            }
            if ($this->hasInboundMedia($this->unwrapMessageContent($msg['message'] ?? []))) {
                return false;
            }
            if (! app(PsbRecapCommandParser::class)->matches($text)) {
                return false;
            }

            return $registry->commandsEnabled();
        } catch (\Throwable $e) {
            Log::warning('[REPORT-GROUP] gate check failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Conservative gate for "#rekap <topic>" (onu): every message that PSB or migration would take keeps
     * its old path; no media, single line, a known topic and its flag ON. Never throws (false on any error).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function isRecapTopicCommand(array $msg, string $text, PsbMessageParser $parser, CustomerMigrationMessageParser $migrationParser, string $context = RecapTopics::CONTEXT_PSB): bool
    {
        try {
            if ($parser->isBotTrigger($text) || $migrationParser->isBotTrigger($text)) {
                return false;
            }
            if ($this->hasInboundMedia($this->unwrapMessageContent($msg['message'] ?? []))) {
                return false;
            }
            if (preg_match('/[\r\n]/', trim($text))) {
                return false;
            }
            $topic = RecapTopics::matchTopic($text);
            if ($topic === null) {
                return false;
            }

            return RecapTopics::enabled($topic, $context);
        } catch (\Throwable $e) {
            Log::warning('[RECAP-TOPIC] gate check failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Same gate inside the Skynet Report group (never the PSB group: that branch is checked first).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function isReportGroupRecapTopicCommand(array $msg, string $remoteJid, string $remoteNorm, PsbMessageParser $parser, CustomerMigrationMessageParser $migrationParser): bool
    {
        try {
            $registry = app(ReportGroupRegistry::class);
            if (! $registry->isReportGroup($remoteJid) && ! ($remoteNorm !== '' && $registry->isReportGroup($remoteNorm))) {
                return false;
            }

            return $this->isRecapTopicCommand($msg, $this->extractBotText($msg), $parser, $migrationParser, RecapTopics::CONTEXT_REPORT);
        } catch (\Throwable $e) {
            Log::warning('[RECAP-TOPIC] report gate check failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Queue the topic recap on `wa`. Failure only logs a warning (never a 503, never replays the batch).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function routeRecapTopicCommand(array $msg, string $pushName, string $remoteJid, string $context = 'psb'): void
    {
        try {
            if (! empty($msg['key']['fromMe'])) {
                return;
            }

            $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
            $senderJidPn = $msg['key']['participantAlt']
                ?? $msg['key']['participantPn']
                ?? $msg['participantAlt']
                ?? $msg['senderPn']
                ?? null;
            if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
                $senderJidPn .= '@s.whatsapp.net';
            }

            $payload = [
                'group_jid' => $remoteJid,
                'sender_jid' => (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : (string) $senderJid,
                'sender_name' => $pushName,
                'message_id' => $msg['key']['id'] ?? null,
                'text' => $this->extractBotText($msg),
            ];
            if ($context === 'report') {
                $payload['context'] = 'report';
            }

            ProcessRecapTopicCommandJob::dispatch($payload);
        } catch (\Throwable $e) {
            Log::warning('[RECAP-TOPIC] dispatch failed', [
                'exception_class' => $e::class,
                'message_id' => $msg['key']['id'] ?? null,
            ]);
        }
    }

    /**
     * Gate for "#pppoe cari ..." inside the Skynet Report group (never the PSB group: that branch is
     * checked first). Needs the flag report_pppoe_cmd_enabled, no media, a strict single-line
     * "#pppoe cari [query]" and nothing that PSB / migration would take. Never throws.
     *
     * @param  array<string, mixed>  $msg
     */
    protected function isReportGroupPppoeCari(array $msg, string $remoteJid, string $remoteNorm, CustomerMigrationMessageParser $migrationParser): bool
    {
        try {
            $registry = app(ReportGroupRegistry::class);
            if (! $registry->isReportGroup($remoteJid) && ! ($remoteNorm !== '' && $registry->isReportGroup($remoteNorm))) {
                return false;
            }
            if (! $registry->pppoeCommandEnabled()) {
                return false;
            }
            $text = $this->extractBotText($msg);
            if ($this->hasInboundMedia($this->unwrapMessageContent($msg['message'] ?? []))) {
                return false;
            }
            $query = ProcessReportPppoeCariJob::extractQuery($text);
            if ($query === null || str_contains($query, '#') || $migrationParser->isBotTrigger($text)) {
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[REPORT-GROUP] pppoe cari gate check failed', ['exception_class' => $e::class]);

            return false;
        }
    }

    /**
     * Queue the Report-group "#pppoe cari" reply on `wa`. Failure only logs a warning.
     *
     * @param  array<string, mixed>  $msg
     */
    protected function routeReportPppoeCari(array $msg, string $pushName, string $remoteJid): void
    {
        try {
            if (! empty($msg['key']['fromMe'])) {
                return;
            }

            $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
            $senderJidPn = $msg['key']['participantAlt']
                ?? $msg['key']['participantPn']
                ?? $msg['participantAlt']
                ?? $msg['senderPn']
                ?? null;
            if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
                $senderJidPn .= '@s.whatsapp.net';
            }

            ProcessReportPppoeCariJob::dispatch([
                'group_jid' => $remoteJid,
                'sender_jid' => (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : (string) $senderJid,
                'sender_name' => $pushName,
                'message_id' => $msg['key']['id'] ?? null,
                'text' => $this->extractBotText($msg),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[REPORT-PPPOE] dispatch failed', [
                'exception_class' => $e::class,
                'message_id' => $msg['key']['id'] ?? null,
            ]);
        }
    }

    /**
     * Queue the recap on `wa`. Failure only logs a warning (never a 503, never replays the batch).
     *
     * @param  array<string, mixed>  $msg
     */
    protected function routePsbRecapCommand(array $msg, string $pushName, string $remoteJid, string $context = 'psb'): void
    {
        try {
            if (! empty($msg['key']['fromMe'])) {
                return;
            }

            $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
            $senderJidPn = $msg['key']['participantAlt']
                ?? $msg['key']['participantPn']
                ?? $msg['participantAlt']
                ?? $msg['senderPn']
                ?? null;
            if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
                $senderJidPn .= '@s.whatsapp.net';
            }

            $payload = [
                'group_jid' => $remoteJid,
                'sender_jid' => (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : (string) $senderJid,
                'sender_name' => $pushName,
                'message_id' => $msg['key']['id'] ?? null,
                'text' => $this->extractBotText($msg),
            ];
            // PSB-group payload stays byte-identical: the context key exists only for the Report group.
            if ($context === 'report') {
                $payload['context'] = 'report';
            }

            ProcessPsbRecapCommandJob::dispatch($payload);
        } catch (\Throwable $e) {
            Log::warning('[PSB-RECAP] dispatch failed', [
                'exception_class' => $e::class,
                'message_id' => $msg['key']['id'] ?? null,
            ]);
        }
    }

    protected function extractBotText(array $msg): string
    {
        $msgContent = $this->unwrapMessageContent($msg['message'] ?? []);

        return (string) (
            $msgContent['conversation']
            ?? $msgContent['extendedTextMessage']['text']
            ?? $msgContent['imageMessage']['caption']
            ?? $msgContent['documentWithCaptionMessage']['message']['documentMessage']['caption']
            ?? $msgContent['documentMessage']['caption']
            ?? ''
        );
    }

    /**
     * WhatsApp may deliver a photo as imageMessage or as an image document.
     * Both forms use the same Evolution media endpoint.
     *
     * @param  array<string, mixed>  $msgContent
     */
    protected function hasInboundMedia(array $msgContent): bool
    {
        return ! empty($msgContent['imageMessage'])
            || ! empty($msgContent['documentMessage'])
            || ! empty($msgContent['documentWithCaptionMessage']['message']['documentMessage']);
    }

    /**
     * @param  array<string, mixed>  $msgContent
     * @return array<string, mixed>
     */
    protected function unwrapMessageContent(array $msgContent): array
    {
        if (! empty($msgContent['ephemeralMessage']['message']) && is_array($msgContent['ephemeralMessage']['message'])) {
            $msgContent = $msgContent['ephemeralMessage']['message'];
        }
        if (! empty($msgContent['viewOnceMessage']['message']) && is_array($msgContent['viewOnceMessage']['message'])) {
            $msgContent = $msgContent['viewOnceMessage']['message'];
        }
        if (! empty($msgContent['viewOnceMessageV2']['message']) && is_array($msgContent['viewOnceMessageV2']['message'])) {
            $msgContent = $msgContent['viewOnceMessageV2']['message'];
        }

        return $msgContent;
    }

    protected function attachPsbKtpByDraftId(array $msg, string $groupJid, string $senderJid, string $messageId, int $draftId): void
    {
        try {
            $draft = PsbDraft::query()
                ->whereKey($draftId)
                ->where('group_jid', $groupJid)
                ->where('status', '!=', PsbDraft::STATUS_CANCELLED)
                ->first();

            if (! $draft) {
                Log::warning('[PSB] exact KTP draft not found', ['draft' => $draftId, 'message_id' => $messageId]);
                app(PsbOrchestratorService::class)->reply(
                    $groupJid,
                    app(PsbWaMessages::class)->draftNotFound($draftId)
                );

                return;
            }
            $meta = is_array($draft->meta) ? $draft->meta : [];
            if (($meta['document_context']['message_id'] ?? null) === $messageId) {
                return;
            }

            $seenKey = 'psb:ktp-media-seen:'.sha1($messageId);
            if (! Cache::add($seenKey, 1, now()->addMinutes(15))) {
                return;
            }

            $bytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
            if (! $bytes || strlen($bytes) < 100) {
                Cache::forget($seenKey);

                return;
            }

            /** @var PsbOrchestratorService $psb */
            $psb = app(PsbOrchestratorService::class);
            $wasWaitingPhoto = ! empty($meta['waiting_photo'])
                || str_contains((string) $draft->last_error, 'Menunggu foto');
            $updated = $psb->attachFollowUpImages($draft, [$bytes], [
                'actor' => $senderJid,
                'source' => 'wa_exact_caption',
                'message_id' => $messageId,
            ]);

            // The orchestrator already replies while a draft is waiting for a photo.
            // Completed drafts still need an explicit ACK so technicians do not resend.
            if (! $wasWaitingPhoto) {
                $ktpRead = data_get($meta, 'document_gate.status') === 'verified'
                    || data_get($updated->meta, 'document_gate.status') === 'verified';
                $psb->reply($groupJid, app(PsbWaMessages::class)->ktpPhotoAck($updated, $ktpRead));
            }

            Log::info('[PSB] exact KTP photo processed', [
                'draft' => $draft->id,
                'message_id' => $messageId,
                'ktp' => $draft->fresh()?->ktp_photo_path,
                'sn_photo' => $draft->fresh()?->router_photo_path,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PSB] follow-up photo failed', ['error' => $e->getMessage()]);
        }
    }

    private function albumOtherFlowKey(string $groupJid, string $albumKey): string
    {
        return 'wa:album-other-flow:'.sha1($groupJid.'|'.$albumKey);
    }

    private function rememberAlbumOtherFlow(string $groupJid, ?string $albumKey): void
    {
        if ($albumKey !== null) {
            Cache::put($this->albumOtherFlowKey($groupJid, $albumKey), 1, now()->addMinutes(30));
        }
    }

    /**
     * True when the caption starts a flow other than migration: anything the PSB parser
     * accepts (incl. bold/LRM/mid-text #psb and near-misses) or another bot command.
     */
    protected function isOtherFlowCaption(string $caption, PsbMessageParser $parser): bool
    {
        if (trim($caption) === '') {
            return false;
        }
        // Only flow STARTS count. Bare "Sudah" / "done" / "selesai" stay migration follow-ups,
        // so the generic isBotTrigger() is deliberately not used here.
        if ($parser->parse($caption)['is_psb'] || $parser->isNearMissPsb($caption)) {
            return true;
        }
        $clean = preg_replace('/[\p{Cf}*_~`]+/u', '', $caption) ?? $caption;

        return preg_match('/^\s*\/(psb|pppoe)\b|#\s*pppoe\b|^\s*[#\/]\s*(rekap|help|bantuan|status|lanjut|batal|pilih)\b/iu', $clean) === 1;
    }

    /**
     * WhatsApp album images carry messageContextInfo.messageAssociation.parentMessageKey
     * (the key of the albumMessage). Based on the WhatsApp proto, NOT verified against a
     * captured Evolution payload; the psb-intake marker is the fallback. Returns that
     * parent message id, or null.
     *
     * @param  array<string, mixed>  $msg
     * @param  array<string, mixed>  $msgContent
     */
    protected function albumParentKey(array $msg, array $msgContent): ?string
    {
        $info = $msgContent['messageContextInfo']
            ?? $msg['message']['messageContextInfo']
            ?? $msg['messageContextInfo']
            ?? [];
        $id = is_array($info) ? ($info['messageAssociation']['parentMessageKey']['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function tryAttachMigrationFollowUpPhoto(array $msg, string $groupJid, PsbMessageParser $parser): bool
    {
        try {
            $msgContent = $this->unwrapMessageContent($msg['message'] ?? []);
            $messageId = (string) ($msg['key']['id'] ?? '');
            if (empty($msgContent['imageMessage']) || $messageId === '') {
                return false;
            }

            $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
            $senderJidPn = $msg['key']['participantAlt']
                ?? $msg['key']['participantPn']
                ?? $msg['participantAlt']
                ?? $msg['senderPn']
                ?? null;
            if (is_string($senderJidPn) && $senderJidPn !== '' && ! str_contains($senderJidPn, '@')) {
                $senderJidPn .= '@s.whatsapp.net';
            }
            $senderForDraft = (is_string($senderJidPn) && $senderJidPn !== '') ? $senderJidPn : $senderJid;

            // A caption that belongs to another flow (#psb in any form the PSB parser accepts,
            // a PSB near-miss, #pppoe, /psb, ...) is never a migration follow-up. Hint captions
            // like "#ktp" / "#sn" stay migration follow-ups. Remember its WhatsApp album so the
            // caption-less siblings follow the same flow.
            $caption = (string) ($msgContent['imageMessage']['caption'] ?? '');
            $albumKey = $this->albumParentKey($msg, $msgContent);
            if ($this->isOtherFlowCaption($caption, $parser)) {
                $this->rememberAlbumOtherFlow($groupJid, $albumKey);

                return false;
            }
            if ($albumKey !== null && Cache::has($this->albumOtherFlowKey($groupJid, $albumKey))) {
                return false;
            }
            // A real #psb intake from this sender in the last ~2 minutes: its photos belong to PSB,
            // whether or not the album field is present in the payload. Not the generic PSB anchor:
            // that is also set by "sudah", "#pppoe", "#status" ... which must not block migration photos.
            if (Cache::has($this->psbIntakeKey($groupJid, (string) $senderForDraft))) {
                return false;
            }

            // Only migrations still in progress take photos; finished ones never do.
            $migration = CustomerMigration::query()
                ->where('group_jid', $groupJid)
                ->where('sender_jid', (string) $senderForDraft)
                ->where('created_at', '>=', now()->subMinutes(60))
                ->open()
                ->where(function ($q) {
                    $q->whereNull('ktp_photo_path')
                        ->orWhereNull('router_photo_path')
                        ->orWhereIn('status', [
                            CustomerMigration::STATUS_RECEIVED,
                            CustomerMigration::STATUS_WAITING_SN,
                            CustomerMigration::STATUS_FAILED,
                        ]);
                })
                ->orderByDesc('id')
                ->first();

            if (! $migration) {
                return false;
            }

            // Logged only when an open migration is a candidate (not for every PSB photo).
            Log::info('[MIGRASI] follow-up photo candidate', [
                'message_id' => $messageId,
                'migration' => $migration->id,
                'album_parent_key' => $albumKey !== null ? 'present' : 'absent',
            ]);

            $bytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
            if (! $bytes || strlen($bytes) < 100) {
                return false;
            }

            app(CustomerMigrationService::class)->attachFollowUpImages($migration, [$bytes], $caption);

            Log::info('[MIGRASI] follow-up photo attached', [
                'migration' => $migration->id,
                'message_id' => $messageId,
                'caption' => mb_substr($caption, 0, 80),
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('[MIGRASI] follow-up photo failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function verifyEvolutionWebhook(Request $request): bool
    {
        $secret = (string) config('services.evolution_api.webhook_secret', '');
        if ($secret === '') {
            $secret = (string) config('services.evolution_api.api_key', '');
        }

        if ($secret === '') {
            Log::error('Attendance webhook secret not configured — rejecting request');

            return false;
        }

        $provided = (string) (
            $request->header('apikey')
            ?: $request->header('x-api-key')
            ?: $request->header('x-evolution-signature')
            ?: $request->bearerToken()
            ?: ''
        );

        return $provided !== '' && hash_equals($secret, $provided);
    }

    /**
     * Evolution API v2 sends a single message in data; older payloads use data.messages[].
     *
     * @return array<int, array<string, mixed>>
     */
    protected function extractWebhookMessages(array $data): array
    {
        $body = $data['data'] ?? [];

        if (! is_array($body)) {
            return [];
        }

        if (isset($body['messages']) && is_array($body['messages'])) {
            $messages = $body['messages'];

            // Evolution v2 may send one message object here, not an array of messages.
            if (isset($messages['key']) || isset($messages['message'])) {
                return [$messages];
            }

            return array_values(array_filter($messages, 'is_array'));
        }

        if (isset($body['key']) || isset($body['message'])) {
            return [$body];
        }

        return [];
    }

    public function processAttendanceMessage($msg, $pushName)
    {
        $msgContent = $msg['message'] ?? [];
        if (! isset($msgContent['imageMessage'])) {
            return;
        }

        $messageId = $msg['key']['id'];

        // 1. Deduplicate by message ID
        $exists = Attendance::query()
            ->where('message_id', $messageId)
            ->orWhere('evidence_message_id', $messageId)
            ->exists();
        if ($exists) {
            Log::info("Attendance message $messageId already processed, skipping.");

            return;
        }

        // 2. Clean up name if it's "Você", "." or empty
        $senderJid = $msg['key']['participant'] ?? $msg['key']['remoteJid'] ?? '';
        $phoneNumber = explode('@', $senderJid)[0];
        if (empty($pushName) || $pushName === 'Você' || $pushName === '.') {
            $pushName = $phoneNumber;
        }

        $caption = $msgContent['imageMessage']['caption'] ?? '';
        // Always interpret WA timestamps in Asia/Jakarta (absensi business day).
        $timestamp = isset($msg['messageTimestamp'])
            ? Carbon::createFromTimestamp((int) $msg['messageTimestamp'], 'Asia/Jakarta')
            : now('Asia/Jakarta');

        Log::info("Downloading attendance media for message $messageId from $pushName");
        $photoBytes = $this->downloadMedia($messageId, $msg['key'] ?? []);
        if (! $photoBytes) {
            Log::error("Failed to download media for message $messageId");

            return;
        }

        Log::info('Uploading attendance media...');
        $remotePath = $this->storeAttendancePhoto($photoBytes, $pushName, $timestamp);

        $technicians = $this->resolveAttendanceTechnicians((string) $caption, (string) $pushName);
        foreach ($technicians as $row) {
            $techId = $row['technician_id'] ?? null;
            Attendance::create([
                'sender_number' => $msg['key']['remoteJid'] ?? '',
                'sender_jid' => $senderJid,
                'sender_name' => $row['name'] ?? $pushName,
                'technician_id' => $techId,
                'caption' => $caption,
                'photo_url' => $remotePath,
                'message_id' => $techId ? "{$messageId}#tech{$techId}" : $messageId,
                'evidence_message_id' => $messageId,
                'review_status' => $row['review_status'] ?? 'accepted',
                // Storage is UTC (APP_TIMEZONE); $timestamp is WIB and is only used for the photo path/name.
                'created_at' => $timestamp->copy()->utc(),
            ]);
        }

        Log::info("Successfully processed attendance for $pushName", [
            'message_id' => $messageId,
            'rows' => count($technicians),
        ]);
    }

    /**
     * @return list<array{name: string, technician_id: ?int, review_status: string}>
     */
    protected function resolveAttendanceTechnicians(string $caption, string $fallbackName): array
    {
        $text = $this->normalizeAttendanceName($caption.' '.$fallbackName);
        $members = TechnicianMember::query()
            ->where('is_active', true)
            ->get(['id', 'name']);

        $matches = [];
        foreach ($members as $member) {
            $name = trim((string) $member->name);
            $needle = $this->normalizeAttendanceName($name);
            if (strlen($needle) >= 3 && str_contains($text, $needle)) {
                $matches[$member->id] = [
                    'name' => $name,
                    'technician_id' => (int) $member->id,
                    'review_status' => 'accepted',
                ];
            }
        }

        if ($matches !== []) {
            return array_values($matches);
        }

        $ambiguous = preg_match('/\b(berdua|dua|2|bareng|bersama|team|tim|rombongan)\b/i', $caption) === 1;

        return [[
            'name' => $fallbackName,
            'technician_id' => null,
            'review_status' => $ambiguous ? 'pending_review' : 'accepted',
        ]];
    }

    private function normalizeAttendanceName(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)) ?? '');
    }

    /**
     * @param  array<string, mixed>  $messageKey  full WA key (id + remoteJid + fromMe + participant)
     */
    /** Measure-only wrapper: adds the download time to media_ms of the webhook in flight. */
    private function downloadMedia($messageId, array $messageKey = [])
    {
        $startNs = hrtime(true);
        try {
            return $this->downloadMediaUntimed($messageId, $messageKey);
        } finally {
            $this->webhookTimer?->add('media_ms', hrtime(true) - $startNs);
        }
    }

    private function downloadMediaUntimed($messageId, array $messageKey = [])
    {
        $base = config('services.evolution.url') ?: config('services.evolution_api.base_url');
        $instance = config('services.evolution.instance') ?: config('services.evolution_api.instance_name');
        $key = config('services.evolution.key') ?: config('services.evolution_api.api_key');
        $url = rtrim((string) $base, '/').'/chat/getBase64FromMediaMessage/'.$instance;

        $keyPayload = array_filter([
            'id' => $messageId,
            'remoteJid' => $messageKey['remoteJid'] ?? null,
            'fromMe' => $messageKey['fromMe'] ?? null,
            'participant' => $messageKey['participant'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! isset($keyPayload['id'])) {
            $keyPayload['id'] = $messageId;
        }

        $response = Http::timeout(90)->withHeaders([
            'apikey' => $key,
        ])->post($url, [
            'message' => [
                'key' => $keyPayload,
            ],
            'convertToMp4' => false,
        ]);

        if ($response->successful()) {
            $base64 = $response->json('base64') ?? $response->json('base64OrPath');
            if ($base64) {
                if (str_contains((string) $base64, ',')) {
                    $base64 = explode(',', (string) $base64, 2)[1];
                }

                return base64_decode((string) $base64);
            }
        }

        Log::warning('getBase64FromMediaMessage failed', [
            'message_id' => $messageId,
            'status' => $response->status(),
            'body' => substr($response->body(), 0, 200),
        ]);

        return null;
    }

    private function storeAttendancePhoto($photoBytes, $pushName, $timestamp): string
    {
        try {
            return $this->uploadToNextcloud($photoBytes, $pushName, $timestamp);
        } catch (\Throwable $e) {
            Log::warning('Attendance Nextcloud upload unavailable, falling back to app storage: '.$e->getMessage());

            return $this->storeAttendancePhotoLocally($photoBytes, $pushName, $timestamp);
        }
    }

    private function storeAttendancePhotoLocally($photoBytes, $pushName, $timestamp): string
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9]+/', '_', $pushName);
        $filename = trim((string) $cleanName, '_').'_'.$timestamp->format('His').'.jpg';
        $path = 'attendance/'.$timestamp->format('Y/m/d').'/'.$filename;

        Storage::disk(config('filesystems.default'))->put($path, $photoBytes);

        return 'storage:'.$path;
    }

    private function uploadToNextcloud($photoBytes, $pushName, $timestamp)
    {
        $url = rtrim((string) config('services.nextcloud.url', config('site.nextcloud_internal_url')), '/');
        $user = (string) config('services.nextcloud.user', 'admin');
        $pass = (string) config('services.nextcloud.pass', '');
        if ($pass === '') {
            // RuntimeException so artisan catch works (abort_if → HttpException 500 was opaque).
            throw new \RuntimeException(
                'Nextcloud credentials not configured — set NEXTCLOUD_PASS in .env and map services.nextcloud in config/services.php'
            );
        }

        $monthStr = $this->formatIndoMonth($timestamp->month);
        $year = $timestamp->year;
        $day = $timestamp->day;

        $cleanName = preg_replace('/[^a-zA-Z0-9]+/', '_', $pushName);
        $filename = trim($cleanName, '_').'_'.$timestamp->format('His').'.jpg';

        $remotePath = "ABSENSI/$monthStr $year/$day $monthStr/$filename";

        $client = new Client;

        // 1. Authenticate with FileBrowser API
        try {
            $loginResponse = $client->request('POST', "$url/api/login", [
                'json' => [
                    'username' => $user,
                    'password' => $pass,
                ],
            ]);
            $token = $loginResponse->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error('FileBrowser login failed: '.$e->getMessage());
            throw $e;
        }

        // 2. Upload file to FileBrowser resources API
        try {
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
            $uploadUrl = "$url/api/resources/$encodedPath?override=true";

            $client->request('POST', $uploadUrl, [
                'headers' => [
                    'X-Auth' => $token,
                ],
                'body' => $photoBytes,
            ]);
        } catch (\Exception $e) {
            Log::error('FileBrowser upload failed: '.$e->getMessage());
            throw $e;
        }

        return $remotePath;
    }

    private function formatIndoMonth($month)
    {
        $months = [
            1 => 'JANUARI', 2 => 'FEBRUARI', 3 => 'MARET', 4 => 'APRIL',
            5 => 'MEI', 6 => 'JUNI', 7 => 'JULI', 8 => 'AGUSTUS',
            9 => 'SEPTEMBER', 10 => 'OKTOBER', 11 => 'NOVEMBER', 12 => 'DESEMBER',
        ];

        return $months[$month] ?? '';
    }
}
