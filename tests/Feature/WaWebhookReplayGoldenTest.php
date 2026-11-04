<?php

namespace Tests\Feature;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Support\TesseractRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\TestCase;

/**
 * Golden replay of the Evolution webhook (plan #7 "thin WA webhook").
 *
 * Every scenario in tests/Fixtures/wa-webhook/scenarios/*.json is replayed through the real
 * route + controller + services. Only the edges are faked (Evolution HTTP, OCR binary, queue).
 * For every step we record: HTTP status + json, pushed jobs (class, queue, unique id, payload,
 * photo paths replaced by a content hash), outbound HTTP calls in order (sendText bodies,
 * media downloads) and a DB snapshot (no ids, no timestamps).
 *
 * The recording is the behaviour of main BEFORE any change. It is compared byte for byte
 * with tests/Fixtures/wa-webhook/golden/<scenario>.json. It is rewritten ONLY when the env
 * WA_REPLAY_GOLDEN_WRITE=1 is set (never in CI). Later PRs may add scenarios; they must not
 * change an existing golden file.
 *
 * Schedule: "inline" = queued jobs are captured and NOT run (what the webhook itself does).
 */
#[Group('blocking-psb')]
class WaWebhookReplayGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'canary-pw';

    private const PSB_GROUP = '120363000000000001@g.us';

    private const SENDER = '62812000001@s.whatsapp.net';

    /** @var array<string, int> ref name => id (seeded rows) */
    private array $refs = [];

    /** @var array<int, int> draft id => ordinal */
    private array $draftOrdinals = [];

    /** @var array<int, int> migration id => ordinal */
    private array $migrationOrdinals = [];

    /** @var array<string, int> */
    private array $queueSeen = [];

    private int $httpSeen = 0;

    private string $mediaMode = 'ok';

    private string $defaultMediaMode = 'ok';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
        config([
            'filesystems.default' => 'local',
            'services.evolution_api.api_key' => self::SECRET,
            'services.evolution_api.webhook_secret' => self::SECRET,
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.instance_name' => 'ops',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => self::SECRET,
            'services.ocrspace.async_media_enabled' => true,
        ]);
        Storage::fake('local');
        Queue::fake();

        TesseractRunner::fakeRunner(function (array $cmd, int $timeout): array {
            if (($cmd[0] ?? '') !== 'tesseract') {
                return ['text' => null, 'timed_out' => false, 'ms' => 1];
            }
            if (in_array('--list-langs', $cmd, true)) {
                return ['text' => "List of available languages (2):\nind\neng", 'timed_out' => false, 'ms' => 1];
            }

            return ['text' => SyntheticOcrFixtures::ktpColumnWiseText(), 'timed_out' => false, 'ms' => 100];
        });
    }

    protected function tearDown(): void
    {
        TesseractRunner::fakeRunner(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $files = glob(__DIR__.'/../Fixtures/wa-webhook/scenarios/*.json') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $out[$name] = [$name];
        }

        return $out;
    }

    public function test_scenarios_exist(): void
    {
        $this->assertNotEmpty(self::scenarios());
    }

    #[DataProvider('scenarios')]
    public function test_replay_matches_golden(string $name): void
    {
        $scenario = json_decode((string) file_get_contents($this->fixturePath("scenarios/{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
        $this->prepare($scenario);

        $steps = [];
        foreach ($scenario['steps'] as $i => $step) {
            $steps[] = ['label' => $step['label'] ?? 'step '.($i + 1)] + $this->runStep($step);
        }

        $recorded = $this->encode(['scenario' => $name, 'steps' => $steps]);
        $goldenPath = $this->fixturePath("golden/{$name}.json");

        if (getenv('WA_REPLAY_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($goldenPath))) {
                mkdir(dirname($goldenPath), 0775, true);
            }
            file_put_contents($goldenPath, $recorded);
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertFileExists($goldenPath, "No golden for {$name}. Generate it on main with WA_REPLAY_GOLDEN_WRITE=1.");
        $this->assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents($goldenPath)),
            $recorded,
            "Webhook behaviour changed for scenario {$name}"
        );
    }

    private function fixturePath(string $rel): string
    {
        return __DIR__.'/../Fixtures/wa-webhook/'.$rel;
    }

    /** @param array<string, mixed> $scenario */
    private function prepare(array $scenario): void
    {
        $defaults = [
            'psb_wa_group_ids' => [self::PSB_GROUP],
            'attendance_group_id' => '120363000000000003@g.us',
            'report_wa_group_ids' => ['120363000000000002@g.us'],
            'psb_recap_cmd_enabled' => '1',
            'recap_onu_cmd_enabled' => '1',
            'report_recap_cmd_enabled' => '1',
            'report_pppoe_cmd_enabled' => '1',
        ];
        foreach (array_merge($defaults, $scenario['settings'] ?? []) as $key => $value) {
            Setting::set($key, $value);
        }
        foreach ($scenario['config'] ?? [] as $key => $value) {
            config([$key => $value]);
        }
        $this->defaultMediaMode = (string) ($scenario['media'] ?? 'ok');

        $this->burnIds();

        foreach ($scenario['drafts'] ?? [] as $row) {
            $draft = PsbDraft::create(array_merge([
                'status' => $row['status'] ?? PsbDraft::STATUS_RECEIVED,
                'group_jid' => $row['group_jid'] ?? self::PSB_GROUP,
                'sender_jid' => $row['sender_jid'] ?? self::SENDER,
                'message_id' => $row['message_id'] ?? 'SEED-'.$row['ref'],
                'full_name' => $row['full_name'] ?? 'Pelanggan Uji',
                'meta' => $row['meta'] ?? [],
            ], $row['attrs'] ?? []));
            $this->refs[$row['ref']] = (int) $draft->id;
        }
        foreach ($scenario['migrations'] ?? [] as $row) {
            $migration = CustomerMigration::create(array_merge([
                'status' => $row['status'] ?? CustomerMigration::STATUS_RECEIVED,
                'group_jid' => $row['group_jid'] ?? self::PSB_GROUP,
                'sender_jid' => $row['sender_jid'] ?? self::SENDER,
                'message_id' => $row['message_id'] ?? 'SEED-'.$row['ref'],
                'full_name' => $row['full_name'] ?? 'Migrasi Uji',
                'meta' => $row['meta'] ?? [],
            ], $row['attrs'] ?? []));
            $migration->forceFill(['created_at' => now()->subMinutes((int) ($row['minutes_ago'] ?? 5))])->saveQuietly();
            $this->refs[$row['ref']] = (int) $migration->id;
        }

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            if (str_contains($url, '/chat/getBase64FromMediaMessage/')) {
                if ($this->mediaMode === 'fail') {
                    return Http::response(['error' => 'not found'], 404);
                }
                $id = (string) data_get($request->data(), 'message.key.id', 'unknown');

                return Http::response(['base64' => base64_encode(str_pad('IMG:'.$id.':', 160, 'x'))]);
            }
            if (str_contains($url, '/message/sendText/')) {
                return Http::response(['key' => ['id' => 'OUT-FAKE'], 'status' => 'PENDING']);
            }

            return Http::response(['error' => 'unexpected fake'], 404);
        });

        $this->queueSeen = [];
        $this->httpSeen = 0;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function runStep(array $step): array
    {
        $this->mediaMode = (string) ($step['media'] ?? $this->defaultMediaMode);
        $path = $step['path'] ?? '/webhooks/evolution/attendance';
        $headers = array_key_exists('headers', $step) ? $step['headers'] : ['apikey' => self::SECRET];

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }

        if (isset($step['raw'])) {
            $content = (string) $step['raw'];
        } elseif (isset($step['body'])) {
            $content = json_encode($this->substitute($step['body']), JSON_UNESCAPED_UNICODE);
        } else {
            $content = json_encode([
                'event' => $step['event'] ?? 'messages.upsert',
                'instance' => 'skynet-ebilling',
                'data' => $this->substitute($step['msg']),
                'destination' => 'http://app.test'.$path,
                'date_time' => '2026-10-20T10:00:00.000+07:00',
                'sender' => '6281200000299@s.whatsapp.net',
                'server_url' => 'http://evolution.test',
                'apikey' => 'FAKE-INSTANCE-KEY',
            ], JSON_UNESCAPED_UNICODE);
        }

        $response = $this->call('POST', $path, [], [], [], $server, $content);

        $json = null;
        try {
            $json = $response->json();
        } catch (\Throwable) {
            $json = null;
        }

        $this->indexRows();

        return [
            'status' => $response->getStatusCode(),
            'json' => $this->scrub($json),
            'jobs' => $this->newJobs(),
            'http' => $this->newHttp(),
            'db' => $this->snapshot(),
        ];
    }

    /**
     * Shift the auto-increment ids by a random amount on every run, so a golden that
     * accidentally depends on a row id fails here instead of only in a different test order.
     */
    private function burnIds(): void
    {
        for ($i = random_int(1, 25); $i > 0; $i--) {
            $draft = PsbDraft::create(['status' => PsbDraft::STATUS_CANCELLED, 'message_id' => 'BURN-D-'.$i]);
            $draft->delete();
            $migration = CustomerMigration::create(['status' => CustomerMigration::STATUS_CANCELLED, 'message_id' => 'BURN-M-'.$i]);
            $migration->delete();
        }
    }

    private function substitute(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->substitute($v), $value);
        }
        if ($value === '{{ts}}') {
            return now()->timestamp;
        }
        if (is_string($value) && str_contains($value, '{{')) {
            return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $m): string {
                return (string) ($this->refs[$m[1]] ?? $m[0]);
            }, $value);
        }

        return $value;
    }

    private function indexRows(): void
    {
        foreach (PsbDraft::query()->orderBy('id')->pluck('id') as $id) {
            $this->draftOrdinals[(int) $id] ??= count($this->draftOrdinals) + 1;
        }
        foreach (CustomerMigration::query()->orderBy('id')->pluck('id') as $id) {
            $this->migrationOrdinals[(int) $id] ??= count($this->migrationOrdinals) + 1;
        }
    }

    /** @return list<array<string, mixed>> */
    private function newJobs(): array
    {
        $fake = Queue::getFacadeRoot();
        $prop = new ReflectionProperty($fake, 'jobs');
        $prop->setAccessible(true);
        /** @var array<string, list<array<string, mixed>>> $all */
        $all = $prop->getValue($fake);

        $out = [];
        ksort($all);
        foreach ($all as $class => $entries) {
            $seen = $this->queueSeen[$class] ?? 0;
            foreach (array_slice($entries, $seen) as $entry) {
                $job = $entry['job'];
                $vars = is_object($job) ? get_object_vars($job) : [];
                unset($vars['job']);
                $out[] = [
                    'class' => $class,
                    'queue' => $entry['queue'] ?? null,
                    'unique' => $this->scrub((is_object($job) && method_exists($job, 'uniqueId')) ? $job->uniqueId() : null),
                    'data' => $this->scrub(json_decode((string) json_encode($vars), true)),
                ];
            }
            $this->queueSeen[$class] = count($entries);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function newHttp(): array
    {
        $recorded = Http::recorded();
        $out = [];
        foreach ($recorded->slice($this->httpSeen) as [$request]) {
            /** @var HttpRequest $request */
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $row = ['path' => $request->method().' '.$path];
            $data = $request->data();
            if (str_contains($path, '/message/sendText/')) {
                $row['number'] = $data['number'] ?? null;
                $row['text'] = $this->scrub($data['text'] ?? null);
            } elseif (str_contains($path, '/getBase64FromMediaMessage/')) {
                $row['id'] = data_get($data, 'message.key.id');
            }
            $out[] = $row;
        }
        $this->httpSeen = $recorded->count();

        return $out;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['psb_drafts', 'customer_migrations', 'attendances', 'wa_chat_signals'] as $table) {
            $rows = [];
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                $rows[] = $this->scrubRow((array) $row);
            }
            $out[$table] = $rows;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function scrubRow(array $row): array
    {
        unset($row['id'], $row['created_at'], $row['updated_at']);
        foreach ($row as $key => $value) {
            if (str_ends_with((string) $key, '_at')) {
                $row[$key] = $value === null ? null : '<ts>';

                continue;
            }
            if (is_string($value) && $value !== '' && in_array($value[0], ['{', '['], true)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            $row[$key] = $this->scrub($value);
        }
        ksort($row);

        return $row;
    }

    private function scrubHashes(string $value): string
    {
        return (string) preg_replace('/\b[0-9a-f]{40,64}\b/', '<hash>', $value);
    }

    private function scrub(mixed $value): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k)) {
                    $k = $this->scrubHashes($k);
                }
                if (is_string($k) && str_ends_with($k, '_at') && is_string($v)) {
                    $out[$k] = '<ts>';
                } elseif (in_array($k, ['draftId', 'draft_id'], true) && is_int($v) && isset($this->draftOrdinals[$v])) {
                    $out[$k] = '<draft'.$this->draftOrdinals[$v].'>';
                } elseif (in_array($k, ['migrationId', 'migration_id'], true) && is_int($v) && isset($this->migrationOrdinals[$v])) {
                    $out[$k] = '<migration'.$this->migrationOrdinals[$v].'>';
                } else {
                    $out[$k] = $this->scrub($v);
                }
            }
            if (! array_is_list($out)) {
                ksort($out);
            }

            return $out;
        }
        if (! is_string($value)) {
            return $value;
        }
        if (preg_match('#^(?:storage:)?((?:psb|migrasi|migration|attendance)/\S+)$#', $value, $m)) {
            $disk = Storage::disk('local');
            if ($disk->exists($m[1])) {
                return '<file sha1:'.substr(sha1((string) $disk->get($m[1])), 0, 12).'>';
            }

            return (string) preg_replace('#\d{4}/\d{2}(/\d{2})?#', '<date>', $value);
        }
        $value = (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?/', '<ts>', $value);
        // Batch keys and file digests are plain hashes; secret scanners flag them next to a "key" name.
        $value = $this->scrubHashes($value);

        // Unique job ids like "psb-media-ocr:12:<sha1>" carry a draft id.
        $value = (string) preg_replace_callback('/^([a-z][a-z-]*):(\d+):/', function (array $m): string {
            $id = (int) $m[2];

            return isset($this->draftOrdinals[$id]) ? $m[1].':<draft'.$this->draftOrdinals[$id].'>:' : $m[0];
        }, $value);

        // Draft ids in WhatsApp texts ("#12").
        return (string) preg_replace_callback('/#(\d+)\b/', function (array $m): string {
            $id = (int) $m[1];

            return isset($this->draftOrdinals[$id]) ? '#<draft'.$this->draftOrdinals[$id].'>' : $m[0];
        }, $value);
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
