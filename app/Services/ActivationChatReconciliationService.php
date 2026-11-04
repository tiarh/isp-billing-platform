<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\WaChatSignal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ActivationChatReconciliationService
{
    public function __construct(
        protected PsbMessageParser $psbParser,
        protected CustomerMigrationMessageParser $migrationParser,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(CarbonImmutable $from, CarbonImmutable $to, int $maxPages = 80): array
    {
        $groups = $this->allowedGroups();
        if ($groups === []) {
            throw new RuntimeException('Grup aktivasi allowlist belum dikonfigurasi.');
        }

        $records = [];
        $truncated = false;
        $pagesFetched = 0;
        foreach ($groups as $group) {
            [$groupRecords, $groupTruncated, $groupPagesFetched] = $this->fetchGroup(
                $group,
                $from,
                $to,
                $maxPages,
            );
            array_push($records, ...$groupRecords);
            $truncated = $truncated || $groupTruncated;
            $pagesFetched += $groupPagesFetched;
        }

        $rows = $this->reconcile($records, $groups, $from, $to);
        $summary = array_count_values(array_column($rows, 'classification'));
        ksort($summary);

        return [
            'source' => 'evolution_read_only',
            'range_wib' => [
                'from' => $from->timezone('Asia/Jakarta')->toIso8601String(),
                'to' => $to->timezone('Asia/Jakarta')->toIso8601String(),
            ],
            'group_count' => count($groups),
            'groups' => array_map(fn (int $index) => 'AKTIVASI-'.($index + 1), array_keys($groups)),
            'records_in_range' => count($records),
            'pages_fetched' => $pagesFetched,
            'truncated' => $truncated,
            'summary' => $summary,
            'rows' => $rows,
            'safety' => [
                'database_writes' => false,
                'wa_replies' => false,
                'device_actions' => false,
                'replay_jobs' => false,
            ],
        ];
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: bool, 2: int}
     */
    protected function fetchGroup(
        string $group,
        CarbonImmutable $from,
        CarbonImmutable $to,
        int $maxPages,
    ): array {
        $baseUrl = rtrim((string) (config('services.evolution_api.base_url')
            ?: config('services.evolution.url')), '/');
        $apiKey = (string) (config('services.evolution_api.api_key')
            ?: config('services.evolution.key'));
        $instance = (string) Setting::get(
            'wa_instance_ops',
            config('services.evolution_api.instance_ops')
                ?: config('services.evolution_api.instance_name')
                ?: config('services.evolution.instance'),
        );

        if ($baseUrl === '' || $apiKey === '' || $instance === '') {
            throw new RuntimeException('Evolution ops history belum terkonfigurasi.');
        }

        $records = [];
        $page = 1;
        $pages = 1;
        $olderPageStreak = 0;
        $reachedOlderBoundary = false;
        $maxPages = max(1, min($maxPages, 100));
        do {
            $response = Http::connectTimeout(5)
                ->timeout(25)
                ->acceptJson()
                ->withHeaders(['apikey' => $apiKey])
                ->post("{$baseUrl}/chat/findMessages/{$instance}", [
                    'where' => [
                        'key' => ['remoteJid' => $group],
                        'messageTimestamp' => [
                            '$gte' => (string) $from->timestamp,
                            '$lte' => (string) $to->timestamp,
                        ],
                    ],
                    'limit' => 50,
                    'page' => $page,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException('Evolution history gagal HTTP '.$response->status().'.');
            }

            $json = $response->json();
            $batch = data_get($json, 'messages.records')
                ?? data_get($json, 'records')
                ?? data_get($json, 'messages')
                ?? [];
            if (! is_array($batch) || $batch === []) {
                $reachedOlderBoundary = true;
                break;
            }

            $batchTimestamps = [];
            foreach ($batch as $record) {
                if (! is_array($record) || data_get($record, 'key.remoteJid') !== $group) {
                    continue;
                }
                $occurredAt = $this->timestamp($record);
                if ($occurredAt) {
                    $batchTimestamps[] = $occurredAt;
                }
                if ($occurredAt && $occurredAt->betweenIncluded($from, $to)) {
                    $records[] = $record;
                }
            }

            $allOlder = $batchTimestamps !== []
                && collect($batchTimestamps)->every(fn (CarbonImmutable $at) => $at->lessThan($from));
            $olderPageStreak = $allOlder ? $olderPageStreak + 1 : 0;
            $pages = max(1, (int) (data_get($json, 'messages.pages') ?? data_get($json, 'pages') ?? 1));
            $page++;
            if ($olderPageStreak >= 5) {
                $reachedOlderBoundary = true;
                break;
            }
        } while ($page <= $pages && $page <= $maxPages);

        $pagesFetched = max(0, $page - 1);
        $truncated = ! $reachedOlderBoundary && $page <= $pages;

        return [$records, $truncated, $pagesFetched];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @param  array<int, string>  $groups
     * @return array<int, array<string, mixed>>
     */
    protected function reconcile(
        array $records,
        array $groups,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): array {
        $grouped = [];
        foreach ($records as $record) {
            $text = $this->messageText($record);
            $kind = $this->kind($text, (bool) data_get($record, 'key.fromMe'));
            if ($kind === null) {
                continue;
            }
            $messageId = trim((string) data_get($record, 'key.id'));
            $key = $messageId !== ''
                ? $messageId
                : hash('sha256', $text.'|'.(string) data_get($record, 'messageTimestamp'));
            $grouped[$key][] = compact('record', 'text', 'kind', 'messageId');
        }

        $rows = [];
        foreach ($grouped as $groupKey => $items) {
            $item = $items[0];
            $parsed = $item['kind'] === 'migration'
                ? $this->migrationParser->parse($item['text'])
                : $this->psbParser->parse($item['text']);
            $messageId = $item['messageId'];
            [$draft, $draftAmbiguous] = $this->matchDraft($messageId, $parsed);
            [$migration, $migrationAmbiguous] = $this->matchMigration($messageId, $parsed);
            [$customer, $customerAmbiguous] = $this->matchCustomer($parsed);

            if ($item['kind'] === 'formatter_error') {
                $draft = $draft ?: $this->nearestPostSecretDraft($this->timestamp($item['record']), $from, $to);
            }

            $duplicateCount = count($items);
            $classification = $this->classification(
                $item['kind'],
                $draft,
                $migration,
                $draftAmbiguous || $migrationAmbiguous || $customerAmbiguous,
                $duplicateCount,
            );
            $entity = in_array($classification, ['resolved_reclassified', 'resolved_manual'], true)
                ? $migration
                : ($item['kind'] === 'psb' ? ($draft ?: $migration) : ($migration ?: $draft));
            $groupIndex = array_search((string) data_get($item['record'], 'key.remoteJid'), $groups, true);

            $rows[] = [
                'message_ref' => 'sha256:'.substr(hash('sha256', $messageId ?: $groupKey), 0, 12),
                'occurred_at_wib' => $this->timestamp($item['record'])?->timezone('Asia/Jakarta')->format('Y-m-d H:i:s'),
                'group' => 'AKTIVASI-'.(($groupIndex === false ? 0 : $groupIndex) + 1),
                'direction' => data_get($item['record'], 'key.fromMe') ? 'bot' : 'teknisi',
                'command' => $item['kind'] === 'formatter_error' ? 'formatter_error' : '#'.$item['kind'],
                'name' => $this->safe($parsed['full_name'] ?? $draft?->full_name ?? $migration?->full_name),
                'odp' => $this->safe($parsed['odp'] ?? $draft?->odp ?? $migration?->odp),
                'sn' => $item['kind'] === 'formatter_error'
                    ? null
                    : $this->maskedSn($parsed['sn'] ?? $parsed['new_sn'] ?? $draft?->sn ?? $migration?->new_sn),
                'classification' => $classification,
                'duplicate_count' => $duplicateCount,
                'signal' => $messageId !== '' && WaChatSignal::query()->where('message_id', $messageId)->exists()
                    ? 'exists'
                    : 'missing',
                'entity_type' => $entity ? class_basename($entity) : null,
                'entity_id' => $entity?->getKey(),
                'entity_status' => $entity?->status,
                'linked_psb_draft_id' => $migration
                    ? data_get($migration->meta, 'reclassified_to_psb_draft_id')
                    : $draft?->id,
                'customer_id' => $draft?->customer_id ?? $migration?->customer_id ?? $customer?->id,
                'operator_action' => $this->operatorAction($classification, $draft),
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp(
            (string) $a['occurred_at_wib'],
            (string) $b['occurred_at_wib'],
        ));

        return $rows;
    }

    protected function kind(string $text, bool $fromBot): ?string
    {
        if (preg_match('/(?:Proses\s+#psb\s+gagal|undefined\s+method\s+App\\\\Services\\\\PsbWaMessages)/iu', $text)) {
            return 'formatter_error';
        }
        if ($fromBot) {
            return null;
        }

        $migration = $this->migrationParser->parse($text);
        if (($migration['is_migrasi'] ?? false)
            && ! ($migration['is_help'] ?? false)
            && ! ($migration['is_status'] ?? false)
            && ! ($migration['is_cek'] ?? false)
            && ! ($migration['is_lanjut'] ?? false)
            && ! ($migration['is_batal'] ?? false)) {
            return 'migration';
        }

        $psb = $this->psbParser->parse($text);
        if (($psb['is_psb'] ?? false)
            && ! ($psb['is_help'] ?? false)
            && ! ($psb['is_status'] ?? false)
            && ! ($psb['is_cek'] ?? false)
            && ! ($psb['is_lanjut'] ?? false)
            && ! ($psb['is_batal'] ?? false)
            && ($psb['full_name'] || $psb['sn'] || $psb['odp'])) {
            return 'psb';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: ?PsbDraft, 1: bool}
     */
    protected function matchDraft(string $messageId, array $parsed): array
    {
        if ($messageId !== '' && ($exact = PsbDraft::query()->where('message_id', $messageId)->first())) {
            return [$exact, false];
        }

        return $this->matchModel(PsbDraft::class, $parsed, 'sn', null);
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: ?CustomerMigration, 1: bool}
     */
    protected function matchMigration(string $messageId, array $parsed): array
    {
        if ($messageId !== '' && ($exact = CustomerMigration::query()->where('message_id', $messageId)->first())) {
            return [$exact, false];
        }

        return $this->matchModel(CustomerMigration::class, $parsed, 'new_sn', 'pppoe_user');
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{0: ?Customer, 1: bool}
     */
    protected function matchCustomer(array $parsed): array
    {
        $customerParsed = $parsed;
        $customerParsed['sn'] = $parsed['sn'] ?? $parsed['new_sn'] ?? null;

        return $this->matchModel(Customer::class, $customerParsed, 'onu_serial', 'pppoe_user');
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $parsed
     * @return array{0: ?Model, 1: bool}
     */
    protected function matchModel(
        string $modelClass,
        array $parsed,
        string $snColumn,
        ?string $pppoeColumn,
    ): array {
        $sn = trim((string) ($parsed['sn'] ?? $parsed['new_sn'] ?? ''));
        $pppoe = trim((string) ($parsed['pppoe_user'] ?? ''));
        $name = trim((string) ($parsed['full_name'] ?? ''));
        $odp = trim((string) ($parsed['odp'] ?? ''));

        $candidates = collect();
        if ($sn !== '') {
            $candidates = $modelClass::query()->where($snColumn, $sn)->limit(3)->get();
        } elseif ($pppoeColumn && $pppoe !== '') {
            $candidates = $modelClass::query()->where($pppoeColumn, $pppoe)->limit(3)->get();
        } elseif ($name !== '') {
            $nameColumn = $modelClass === Customer::class ? 'name' : 'full_name';
            $candidates = $modelClass::query()->where($nameColumn, $name)->limit(6)->get();
        }

        $strongMatch = $sn !== '' || ($pppoeColumn && $pppoe !== '');
        if ($name !== '') {
            $expectedName = $this->normalizedName($name);
            $candidates = $candidates->filter(fn (Model $candidate) => $this->normalizedName(
                (string) ($candidate->full_name ?? $candidate->name ?? ''),
            ) === $expectedName || str_starts_with(
                $this->normalizedName((string) ($candidate->full_name ?? $candidate->name ?? '')),
                $expectedName.'RT',
            ));
        }
        if (! $strongMatch && $odp !== '') {
            $candidates = $candidates->filter(fn (Model $candidate) => $this->normalizedOdp(
                (string) ($candidate->odp ?? $candidate->odp_name ?? ''),
            ) === $this->normalizedOdp($odp));
        }

        return [$candidates->count() === 1 ? $candidates->first() : null, $candidates->count() > 1];
    }

    protected function classification(
        string $kind,
        ?PsbDraft $draft,
        ?CustomerMigration $migration,
        bool $ambiguous,
        int $duplicateCount,
    ): string {
        if ($duplicateCount > 1) {
            return 'duplicate';
        }
        if ($kind === 'formatter_error') {
            return $draft && in_array($draft->status, [
                PsbDraft::STATUS_SECRET_OK,
                PsbDraft::STATUS_OLT_OK,
                PsbDraft::STATUS_APPLIED,
            ], true) ? 'formatter_failed_after_secret' : 'needs_review';
        }
        if ($ambiguous) {
            return 'needs_review';
        }
        if ($migration && $migration->status === CustomerMigration::STATUS_CANCELLED
            && data_get($migration->meta, 'reclassified_to_psb_draft_id')) {
            return 'resolved_reclassified';
        }
        if ($migration && $migration->status === CustomerMigration::STATUS_SYNCED
            && ($migration->source === 'manual_fiberhome'
                || data_get($migration->meta, 'manual_fiberhome_recorded_at'))) {
            return 'resolved_manual';
        }
        if ($draft && $draft->status === PsbDraft::STATUS_CORRECTION_PENDING) {
            return 'needs_review';
        }
        if (($kind === 'psb' && $migration && ! $draft)
            || ($kind === 'migration' && $draft && ! $migration)) {
            return 'wrong_migration';
        }
        if ($draft || $migration) {
            return 'draft_exists';
        }

        return $kind === 'psb' ? 'missing_draft' : 'needs_review';
    }

    protected function nearestPostSecretDraft(
        ?CarbonImmutable $at,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): ?PsbDraft {
        if (! $at) {
            return null;
        }

        $start = ($at->subMinutes(30)->greaterThan($from) ? $at->subMinutes(30) : $from)->utc();
        $end = ($at->addMinutes(30)->lessThan($to) ? $at->addMinutes(30) : $to)->utc();

        return PsbDraft::query()
            ->whereIn('status', [PsbDraft::STATUS_SECRET_OK, PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED])
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('secret_at', [$start, $end])
                    ->orWhereBetween('olt_at', [$start, $end])
                    ->orWhereBetween('created_at', [$start, $end]);
            })
            ->get()
            ->sortBy(function (PsbDraft $draft) use ($at) {
                return collect([$draft->secret_at, $draft->olt_at, $draft->created_at])
                    ->filter()
                    ->map(fn ($time) => abs($time->diffInSeconds($at, false)))
                    ->min();
            })
            ->first();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function timestamp(array $record): ?CarbonImmutable
    {
        $raw = data_get($record, 'messageTimestamp');
        if (! is_numeric($raw)) {
            return null;
        }
        $timestamp = (int) $raw;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return CarbonImmutable::createFromTimestamp($timestamp, 'Asia/Jakarta');
    }

    /**
     * @param  array<string, mixed>  $record
     */
    protected function messageText(array $record): string
    {
        $message = data_get($record, 'message', []);
        for ($i = 0; $i < 4 && is_array($message); $i++) {
            foreach ([
                'conversation',
                'extendedTextMessage.text',
                'imageMessage.caption',
                'videoMessage.caption',
                'documentMessage.caption',
            ] as $path) {
                $text = data_get($message, $path);
                if (is_string($text) && trim($text) !== '') {
                    return trim($text);
                }
            }
            $message = data_get($message, 'ephemeralMessage.message')
                ?? data_get($message, 'viewOnceMessage.message')
                ?? data_get($message, 'viewOnceMessageV2.message')
                ?? null;
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    protected function allowedGroups(): array
    {
        $groups = [];
        foreach (['psb_wa_group_ids', 'wa_chat_activation_group_ids'] as $key) {
            $value = Setting::get($key, []);
            if (is_string($value)) {
                $value = preg_split('/[\s,;]+/', $value) ?: [];
            }
            if (is_array($value)) {
                array_push($groups, ...$value);
            }
        }

        return array_values(array_unique(array_filter(array_map('trim', $groups))));
    }

    protected function normalizedName(string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', mb_strtoupper($value)) ?? '';
    }

    protected function normalizedOdp(string $value): string
    {
        return preg_replace('/[^A-Z0-9.]+/', '', mb_strtoupper($value)) ?? '';
    }

    protected function maskedSn(mixed $value): ?string
    {
        $value = preg_replace('/[^A-Z0-9]/i', '', (string) $value) ?? '';
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, min(4, mb_strlen($value))).'…'.mb_substr($value, -4);
    }

    protected function safe(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $patterns = [
            '/\b\d{16}\b/u' => '[NIK]',
            '/(?<!\d)(?:\+?62|0)8\d{7,13}(?!\d)/u' => '[HP]',
            '/-?\d{1,3}\.\d{3,}\s*[,;]\s*-?\d{1,3}\.\d{3,}/u' => '[TIKOR]',
            '/\b\d{6,}@(?:g\.us|s\.whatsapp\.net)\b/iu' => '[JID]',
            '/\b(?:api[-_ ]?key|apikey|token|authorization|password)\s*[:=]\s*\S+/iu' => '[SECRET]',
            '/\bBearer\s+\S+/iu' => '[SECRET]',
        ];

        return mb_substr((string) preg_replace(array_keys($patterns), array_values($patterns), $value), 0, 120);
    }

    protected function operatorAction(string $classification, ?PsbDraft $draft): string
    {
        if ($draft && ($draft->id === 133
            || str_contains((string) $draft->ktp_photo_path, 'ktp_727Y0gjJ.jpg'))) {
            return 'Media FUJIANTO perlu dikirim ulang; jangan buat placeholder.';
        }

        return match ($classification) {
            'draft_exists', 'resolved_reclassified', 'resolved_manual' => 'Tidak ada aksi; jangan replay.',
            'duplicate' => 'Verifikasi satu record; jangan buat ulang.',
            'formatter_failed_after_secret' => 'Cek draft/secret existing; jangan recreate atau provision ulang.',
            'wrong_migration' => 'Review klasifikasi PSB vs migrasi tanpa write.',
            'missing_draft' => 'Review operator sebelum membuat draft.',
            default => $draft?->status === PsbDraft::STATUS_CORRECTION_PENDING
                ? 'Jangan jalankan #psb lanjut; verifikasi pelanggan dan batalkan koreksi yang salah.'
                : 'Review manual; tidak ada write otomatis.',
        };
    }
}
