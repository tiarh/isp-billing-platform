<?php

namespace Tests\Feature;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\PsbMediaOcrOrchestrator;
use App\Services\PsbOrchestratorService;
use App\Support\OcrBudget;
use App\Support\TesseractRunner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\TestCase;

/**
 * Characterization golden of the PSB OCR engine (PSB refactor phase 1b, step 2, PR A).
 *
 * Drives only PUBLIC entries of the real container orchestrators:
 *   - PsbMediaOcrOrchestrator::processStagedMediaOcr (the OCR worker path; the adapter
 *     does not continue after OCR), and
 *   - the container PsbOrchestratorService (HuaweiAware) classifyWaActivationPhoto,
 *     with and without a batch budget (the migration photo path).
 * Only the edges are faked: Tesseract (TesseractRunner::fakeRunner, keyed by the sha of a
 * synthetic image) and Paddle (Http::fake). The recording is taken on unchanged code, so
 * the OCR engine extraction (PR B) must reproduce it exactly.
 *
 * Fixture: tests/Fixtures/psb-flow/ocr-engine-matrix.golden.json. Written ONLY with env
 * PSB_FLOW_GOLDEN_WRITE=1 (never in CI); without the env a missing fixture is a failure.
 * Deterministic on purpose: no times or durations, random file names normalised, synthetic
 * images and fake people only.
 */
#[Group('blocking-psb')]
class PsbOcrEngineGoldenTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'psb-flow/ocr-engine-matrix.golden.json';

    /** @var array<string, array{name: string, text: string, timed_out: bool}> sha1 of image bytes => synthetic image */
    private array $images = [];

    /** @var array<string, string> sha256 of image bytes => synthetic image name */
    private array $shaNames = [];

    /** @var list<array<string, mixed>> */
    private array $tesseractLog = [];

    private int $paddleCalls = 0;

    private int $draftSeq = 0;

    private bool $httpFaked = false;

    private string $paddleMode = 'off';

    private string $installedLanguages = "List of available languages (2):\nind\neng";

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        TesseractRunner::fakeRunner(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_ocr_engine_matrix_matches_golden(): void
    {
        $cases = [
            '01-ktp-then-sn' => fn (): array => $this->workerCase([
                $this->image('ktp-a', 1, SyntheticOcrFixtures::ktpColumnWiseText()),
                $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
            ]),
            '02-sn-then-ktp' => fn (): array => $this->workerCase([
                $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
                $this->image('ktp-a', 1, SyntheticOcrFixtures::ktpColumnWiseText()),
            ]),
            '03-labels-only-ktp-paddle-ok' => fn (): array => $this->workerCase([
                $this->image('ktp-labels', 3, SyntheticOcrFixtures::ktpLabelsOnlyText()),
            ], paddle: 'ok'),
            '04-empty-tesseract-paddle-error' => fn (): array => $this->workerCase([
                $this->image('ktp-empty', 4, ''),
            ], paddle: 'error'),
            '05-ktp-timeout-retry-pending' => fn (): array => $this->workerCase([
                $this->image('ktp-slow', 5, '', timedOut: true),
            ]),
            '06-ktp-timeout-second-pass-slow-unreadable' => fn (): array => $this->workerCase([
                $this->image('ktp-slow', 5, '', timedOut: true),
            ], retry: true),
            '07-invalid-nik' => fn (): array => $this->workerCase([
                $this->image('ktp-bad-nik', 6, str_replace('3507120110000005', '3507129910000001', SyntheticOcrFixtures::ktpColumnWiseText())),
            ]),
            '08-odp-and-ktp' => fn (): array => $this->workerCase([
                $this->image('odp-a', 7, 'ODP GONDANG L3.1'),
                $this->image('ktp-a', 1, SyntheticOcrFixtures::ktpColumnWiseText()),
            ]),
            // Paddle reads no NIK, so nothing stops the batch except the cap: 2 calls, not 3.
            '09-three-ktp-candidates-paddle-cap-2' => fn (): array => $this->workerCase([
                $this->image('ktp-c1', 8, SyntheticOcrFixtures::ktpLabelsOnlyText()),
                $this->image('ktp-c2', 9, SyntheticOcrFixtures::ktpLabelsOnlyText()."\nKeterangan A"),
                $this->image('ktp-c3', 10, SyntheticOcrFixtures::ktpLabelsOnlyText()."\nKeterangan B"),
            ], paddle: 'no_nik', config: ['services.ocrspace.paddle_max_calls_per_batch' => 2]),
            // Paddle reads a valid NIK on the first photo: the others skip Paddle (nik found).
            '09b-three-ktp-candidates-nik-found-first' => fn (): array => $this->workerCase([
                $this->image('ktp-c1', 8, SyntheticOcrFixtures::ktpLabelsOnlyText()),
                $this->image('ktp-c2', 9, SyntheticOcrFixtures::ktpLabelsOnlyText()."\nKeterangan A"),
                $this->image('ktp-c3', 10, SyntheticOcrFixtures::ktpLabelsOnlyText()."\nKeterangan B"),
            ], paddle: 'ok', config: ['services.ocrspace.paddle_max_calls_per_batch' => 2]),
            '15-paddle-connection-timeout' => fn (): array => $this->workerCase([
                $this->image('ktp-labels', 3, SyntheticOcrFixtures::ktpLabelsOnlyText()),
            ], paddle: 'timeout'),
            '10-media-hard-guard-on' => fn (): array => $this->workerCase([
                $this->image('ktp-a', 1, SyntheticOcrFixtures::ktpColumnWiseText()),
                $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
                $this->image('other-a', 11, 'FOTO RUMAH'),
            ], config: ['services.ocrspace.media_hard_guard' => true, 'services.ocrspace.media_fast_path' => true]),
            '11-run-cap-sync-vs-async' => fn (): array => [
                'sync' => $this->workerCase([$this->image('ktp-a', 1, SyntheticOcrFixtures::ktpLabelsOnlyText())], config: ['services.ocrspace.ocr_async_job' => false]),
                'async' => $this->workerCase([$this->image('ktp-a', 1, SyntheticOcrFixtures::ktpLabelsOnlyText())], config: ['services.ocrspace.ocr_async_job' => true]),
            ],
            '12-worker-english-pack-only' => fn (): array => $this->workerCase([
                $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
            ], languages: "List of available languages (1):\neng"),
            '13-no-language-pack' => fn (): array => $this->workerCase([
                $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
            ], languages: "List of available languages (1):\nosd"),
            '14-huawei-aware-and-migration-classifier' => fn (): array => $this->classifierCase(),
        ];

        $recorded = [];
        foreach ($cases as $name => $run) {
            $this->resetEnvironment();
            $recorded[$name] = $run();
        }

        $this->compareToGolden($this->encode(['cases' => $recorded]));
    }

    // ───────────────────────── case runners ─────────────────────────

    /**
     * One OCR worker batch through the container adapter.
     *
     * @param  list<string>  $bytes  synthetic images in upload order
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function workerCase(array $bytes, string $paddle = 'off', bool $retry = false, array $config = [], ?string $languages = null): array
    {
        config(array_merge([
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.paddle_enabled' => $paddle !== 'off',
        ], $config));
        $this->paddleMode = $paddle;
        $this->tesseractLog = [];
        $this->paddleCalls = 0;
        Cache::flush();
        if ($languages !== null) {
            $this->installedLanguages = $languages;
            $this->installRunner();
        }

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'message_id' => 'FAKE-MSG-'.(++$this->draftSeq),
            'group_jid' => '120363000000000001@g.us',
            'full_name' => '',
            'odp' => 'ODP-TEST-01',
            'rt' => '',
            'rw' => '',
            'tikor' => '-7.9,112.6',
            'daerah' => 'RA',
            'sn' => '',
            'router_id' => 513,
            'olt_id' => 1,
            'package_id' => 452,
            'last_error' => 'Foto diterima, validasi KTP/SN sedang diproses.',
            'meta' => ['waiting_photo' => true, 'ocr_batch_key' => 'FAKE-BATCH-1'],
        ]);

        $paths = [];
        $contexts = [];
        foreach ($bytes as $index => $image) {
            $path = 'psb/inbox/image-'.($index + 1).'.jpg';
            Storage::disk('local')->put($path, $image);
            $paths[] = $path;
            $contexts[] = ['path' => $path, 'message_id' => 'FAKE-IMG-'.($index + 1), 'source' => 'wa_inbound'];
        }

        $thrown = null;
        try {
            app(PsbMediaOcrOrchestrator::class)->processStagedMediaOcr($draft, $paths, $contexts, [
                'mode' => 'inbound',
                'batch_key' => 'FAKE-BATCH-1',
                'ocr_retry' => $retry ? 1 : 0,
            ]);
        } catch (\Throwable $e) {
            $thrown = $e::class;
        }

        $fresh = $draft->fresh();

        return [
            'thrown' => $thrown,
            'tesseract' => $this->tesseractLog,
            'paddle_calls' => $this->paddleCalls,
            'draft' => [
                'status' => $fresh->status,
                'last_error' => $fresh->last_error,
                'full_name' => $fresh->full_name,
                'nik' => $fresh->nik,
                'rt' => $fresh->rt,
                'rw' => $fresh->rw,
                'sn' => $fresh->sn,
                'ktp_photo_path' => $this->normalizeString((string) $fresh->ktp_photo_path),
                'router_photo_path' => $this->normalizeString((string) $fresh->router_photo_path),
                'meta' => $this->scrub($fresh->meta),
            ],
        ];
    }

    /**
     * The migration photo path: classifyWaActivationPhoto on the container HuaweiAware
     * orchestrator, with and without a batch budget.
     *
     * @return array<string, mixed>
     */
    private function classifierCase(): array
    {
        config([
            'services.ocrspace.ocr_async_job' => false,
            'services.ocrspace.paddle_enabled' => false,
        ]);
        $orchestrator = app(PsbOrchestratorService::class);
        $photos = [
            'ktp' => $this->image('ktp-a', 1, SyntheticOcrFixtures::ktpColumnWiseText()),
            'sn' => $this->image('sn-a', 2, 'MODEL ONT S/N: FHTTC0FFEE01 GPON SERIAL'),
            'invalid_media' => 'too short',
        ];

        $out = [];
        foreach ($photos as $label => $bytes) {
            $this->tesseractLog = [];
            $result = $orchestrator->classifyWaActivationPhoto($bytes);
            $out['no_batch'][$label] = ['result' => $this->scrub($result), 'tesseract' => $this->tesseractLog];

            $this->tesseractLog = [];
            $result = $orchestrator->classifyWaActivationPhoto($bytes, OcrBudget::start(600.0));
            $out['with_batch'][$label] = ['result' => $this->scrub($result), 'tesseract' => $this->tesseractLog];
        }

        return $out;
    }

    // ───────────────────────── edges ─────────────────────────

    private function resetEnvironment(): void
    {
        $this->images = [];
        $this->shaNames = [];
        $this->tesseractLog = [];
        $this->paddleCalls = 0;
        $this->paddleMode = 'off';
        $this->installedLanguages = "List of available languages (2):\nind\neng";

        Storage::fake('local');
        Cache::flush();
        config([
            'filesystems.default' => 'local',
            'services.ocrspace.key' => '',
            'services.ocrspace.paddle_url' => 'http://paddle.test/ocr',
            'services.ocrspace.paddle_drain_seconds' => 0,
            'services.ocrspace.ocr_async_job' => true,
            'services.ocrspace.media_hard_guard' => false,
            // Generous budgets: no case may depend on the real clock.
            'services.ocrspace.media_image_budget_seconds' => 600,
            'services.ocrspace.ocr_total_budget_seconds' => 1800,
        ]);
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subDay()->toIso8601String(), 'text', 'psb');

        $this->installRunner();
        // Registered ONCE: Laravel keeps every registered fake closure, so a fake per case
        // would count each Paddle call once per earlier case. The mode is switched per case.
        if (! $this->httpFaked) {
            $this->httpFaked = true;
            Http::fake(function (HttpRequest $request) {
                if (str_contains($request->url(), 'paddle.test')) {
                    if (str_ends_with($request->url(), '/health')) {
                        return Http::response(['busy' => false, 'waiting' => 0]);
                    }
                    $this->paddleCalls++;

                    return match ($this->paddleMode) {
                        'ok' => Http::response(['text' => SyntheticOcrFixtures::ktpPaddleLinesText()]),
                        'no_nik' => Http::response(['text' => SyntheticOcrFixtures::ktpLabelsOnlyText()]),
                        'timeout' => throw new ConnectionException('cURL error 28: Operation timed out'),
                        default => Http::response(['error' => 'engine failed'], 500),
                    };
                }

                return Http::response(['error' => 'unexpected fake'], 404);
            });
        }
    }

    private function installRunner(): void
    {
        TesseractRunner::fakeRunner(function (array $cmd, int $timeout): array {
            if (($cmd[0] ?? '') !== 'tesseract') {
                return ['text' => null, 'timed_out' => false, 'ms' => 1];
            }
            if (in_array('--list-langs', $cmd, true)) {
                return ['text' => $this->installedLanguages, 'timed_out' => false, 'ms' => 1];
            }
            $bytes = is_file((string) ($cmd[1] ?? '')) ? (string) file_get_contents((string) $cmd[1]) : '';
            $image = $this->images[sha1($bytes)] ?? null;
            $languageAt = array_search('-l', $cmd, true);
            $psmAt = array_search('--psm', $cmd, true);
            $this->tesseractLog[] = [
                'image' => $image['name'] ?? 'unknown',
                'language' => $languageAt === false ? null : $cmd[$languageAt + 1],
                'psm' => $psmAt === false ? null : (int) $cmd[$psmAt + 1],
                'timeout' => $timeout,
            ];
            if ($image === null || $image['timed_out']) {
                return ['text' => null, 'timed_out' => $image !== null, 'ms' => 100];
            }

            return ['text' => $image['text'] === '' ? null : $image['text'], 'timed_out' => false, 'ms' => 100];
        });
    }

    /** Synthetic JPEG; the fake Tesseract answers by the sha of its bytes. */
    private function image(string $name, int $seed, string $text, bool $timedOut = false): string
    {
        $img = imagecreatetruecolor(600, 400);
        imagefilledrectangle($img, 0, 0, 599, 399, imagecolorallocate($img, ($seed * 37) % 256, ($seed * 91) % 256, ($seed * 53) % 256));
        imagestring($img, 4, 12, 12, $name.' '.$seed, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $this->images[sha1($bytes)] = ['name' => $name, 'text' => $text, 'timed_out' => $timedOut];
        $this->shaNames[hash('sha256', $bytes)] = $name;

        return $bytes;
    }

    // ───────────────────────── normalising ─────────────────────────

    private function normalizeString(string $value): string
    {
        return (string) preg_replace('#psb/\d{4}/\d{2}/(ktp|sn)_[A-Za-z0-9]{8}\.jpg#', 'psb/<ym>/$1_<rand>.jpg', $value);
    }

    /** Removes times and durations, names random files and image hashes. */
    private function scrub(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->shaNames[$value] ?? $this->normalizeString($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && (str_ends_with($key, '_ms') || $key === 'ms')) {
                continue;
            }
            if (is_string($key) && (str_ends_with($key, '_at') || $key === 'until') && $item !== null) {
                $out[$key] = '<ts>';

                continue;
            }
            $out[$key] = $this->scrub($item);
        }

        return $out;
    }

    private function compareToGolden(string $recorded): void
    {
        $path = __DIR__.'/../Fixtures/'.self::FIXTURE;
        if (getenv('PSB_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, 'No golden for the OCR engine matrix. Record it on unchanged code with PSB_FLOW_GOLDEN_WRITE=1 (owner OK).');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, 'PSB OCR engine behaviour changed');
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}
