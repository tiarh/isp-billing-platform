<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PsbDocumentGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.default' => 'local']);
        Storage::fake('local');
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subMinute()->toIso8601String(), 'text', 'psb');
    }

    public function test_verified_ktp_allows_new_device_action(): void
    {
        Storage::disk('local')->put('psb/ktp.png', $this->png());
        $draft = $this->draft(['ktp_photo_path' => 'psb/ktp.png']);

        $gate = app(PsbDocumentGateService::class)->evaluate($draft);

        $this->assertTrue($gate['allowed']);
        $this->assertSame('verified', $gate['status']);
        $this->assertSame('image/png', $gate['evidence']['mime']);
        $this->assertSame('verified', data_get($draft->fresh()->meta, 'document_gate.status'));
    }

    public function test_sn_photo_cannot_replace_ktp(): void
    {
        Storage::disk('local')->put('psb/sn_test.png', $this->png());
        $draft = $this->draft(['router_photo_path' => 'psb/sn_test.png']);

        $gate = app(PsbDocumentGateService::class)->evaluate($draft);

        $this->assertFalse($gate['allowed']);
        $this->assertSame('missing', $gate['status']);
    }

    public function test_invalid_or_missing_ktp_object_is_blocked(): void
    {
        Storage::disk('local')->put('psb/ktp_bad.jpg', 'not-an-image');

        $invalid = app(PsbDocumentGateService::class)->evaluate($this->draft([
            'ktp_photo_path' => 'psb/ktp_bad.jpg',
        ]));
        $missing = app(PsbDocumentGateService::class)->evaluate($this->draft([
            'ktp_photo_path' => 'psb/missing.jpg',
        ]));

        $this->assertSame('invalid_media', $invalid['status']);
        $this->assertSame('invalid_media', $missing['status']);
        $this->assertFalse($invalid['allowed']);
        $this->assertFalse($missing['allowed']);
    }

    public function test_pending_followup_requires_reason_and_future_due_date(): void
    {
        $allowed = app(PsbDocumentGateService::class)->evaluate($this->draft([
            'meta' => ['document_policy' => [
                'type' => 'pending_followup',
                'reason' => 'Pelanggan di luar kota',
                'due_at' => now()->addDay()->format('Y-m-d'),
            ]],
        ]));
        $blocked = app(PsbDocumentGateService::class)->evaluate($this->draft([
            'meta' => ['document_policy' => [
                'type' => 'pending_followup',
                'reason' => '',
                'due_at' => now()->subDay()->format('Y-m-d'),
            ]],
        ]));

        $this->assertTrue($allowed['allowed']);
        $this->assertSame('pending_followup', $allowed['status']);
        $this->assertFalse($blocked['allowed']);
    }

    public function test_csr_exemption_requires_an_institution_or_csr_profile(): void
    {
        $school = $this->draft(['full_name' => 'MI SUKA MAJU']);
        $school->setRelation('package', new Package(['name' => 'CSR', 'mikrotik_profile' => 'CSR']));
        $school->meta = ['document_policy' => ['type' => 'exempt_csr', 'reason' => 'Sekolah']];
        $school->save();

        $person = $this->draft([
            'full_name' => 'BUDI CONTOH',
            'meta' => ['document_policy' => ['type' => 'exempt_csr']],
        ]);

        $this->assertTrue(app(PsbDocumentGateService::class)->evaluate($school)['allowed']);
        $this->assertFalse(app(PsbDocumentGateService::class)->evaluate($person)['allowed']);
    }

    /** @return array<string, array{0: string}> */
    public static function fasumNames(): array
    {
        $names = [
            'BALAI DESA SUKAMAJU', 'balai   desa sukamaju', 'KANTOR DESA SUKAMAJU', 'Kantor  Desa Sukamaju',
            'POSKAMLING RT 3', 'poskamling rt 3', 'POSYANDU MELATI', 'posyandu melati',
            'PUSKESMAS PEMBANTU', 'puskesmas pembantu', 'GEREJA BETHEL', 'gereja bethel',
            'PURA DALEM', 'pura dalem', 'VIHARA DHARMA', 'vihara dharma', 'KLENTENG TRI DHARMA', 'klenteng tri dharma',
            'LANGGAR AL IKHLAS', 'langgar al ikhlas', 'SURAU NURUL', 'surau nurul', 'TPQ AL AMIN', 'tpq al amin',
        ];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('fasumNames')]
    public function test_fasum_keywords_count_as_csr(string $name): void
    {
        $draft = $this->draft(['full_name' => $name, 'meta' => ['document_policy' => ['type' => 'exempt_csr', 'reason' => 'Fasum']]]);

        $this->assertTrue(app(PsbDocumentGateService::class)->evaluate($draft)['allowed']);
    }

    /** @return array<string, array{0: string}> */
    public static function notFasumNames(): array
    {
        $names = [
            'PURWANTO', 'SURABAYA', 'PURAKA', 'PURA2', 'TPQX', 'ATPQ', 'LANGGARAN', 'GEREJAWAN',
            'BALAI DUSUN', 'KANTOR KEPALA DESA', 'BALAIDESA', 'BUDI CONTOH', 'DESA SUKAMAJU',
            'PURABAYA', 'SEMPURNA', 'BUDI SEMPURNA', 'SURAUNI',
        ];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('notFasumNames')]
    public function test_lookalike_names_are_not_csr(string $name): void
    {
        $draft = $this->draft(['full_name' => $name, 'meta' => ['document_policy' => ['type' => 'exempt_csr', 'reason' => 'Fasum']]]);

        $gate = app(PsbDocumentGateService::class)->evaluate($draft);

        $this->assertFalse($gate['allowed']);
        $this->assertSame('csr_not_eligible', $gate['reason']);
    }

    public function test_parser_requires_exact_draft_id_caption_and_tracks_exceptions(): void
    {
        $parser = new PsbMessageParser;

        $caption = $parser->parse('#psb ktp #123');
        $followup = $parser->parse("#psb\nNama: TEST\nKTP: menyusul\nAlasan KTP: di luar kota\nTenggat KTP: 2026-08-01");

        $this->assertTrue($caption['is_ktp_cmd']);
        $this->assertSame(123, $caption['draft_id']);
        $this->assertSame('pending_followup', $followup['ktp_policy']);
        $this->assertSame('di luar kota', $followup['ktp_reason']);
        $this->assertSame('2026-08-01', $followup['ktp_due_at']);
        $this->assertFalse($parser->parse('#psb ktp')['is_ktp_cmd']);
    }

    public function test_cutoff_preserves_inflight_legacy_draft(): void
    {
        Setting::set('psb_document_gate_enforced_after', now()->addHour()->toIso8601String(), 'text', 'psb');
        $draft = $this->draft(['router_photo_path' => 'psb/sn-old.jpg']);

        $gate = app(PsbDocumentGateService::class)->evaluate($draft);

        $this->assertSame('legacy', $gate['status']);
        $this->assertTrue($gate['allowed']);
    }

    /** @param array<string, mixed> $attributes */
    private function draft(array $attributes = []): PsbDraft
    {
        return PsbDraft::create(array_merge([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'activation@g.us',
            'full_name' => 'TEST CUSTOMER',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=');
    }
}
