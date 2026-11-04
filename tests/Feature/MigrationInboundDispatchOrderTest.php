<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * The order of the branches of CustomerMigrationService::handleInbound is locked (migration refactor M4, PR A, tests only).
 *
 * A command that carries two flags is answered by the branch that comes first. M4 moves the bodies the branches call,
 * never the branch chain, so this test must pass unchanged before and after the move. It does not care who the receiver
 * of a call is ($this->x() or $this->guidedFlow()->x()), only the order of the names.
 *
 *  1. source order: the flag reads, the dispatch calls and the number of ifs of handleInbound, read from the current source;
 *  2. behaviour: a fake parser sets two flags at once and the real service, through the real job, answers with the first branch.
 */
#[Group('blocking-psb')]
class MigrationInboundDispatchOrderTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    /** The `$parsed['is_*']` reads of handleInbound, in source order. */
    private const FLAG_READS = [
        'is_migrasi', 'is_help', 'is_status', 'is_cek', 'is_batal', 'is_help', 'is_status', 'is_ktp', 'is_phone_update',
        'is_sn_update', 'is_eksekusi', 'is_konfirmasi', 'is_pilih', 'is_cek', 'is_batal', 'is_lanjut', 'is_batal',
        'is_lanjut', 'is_lanjut', 'is_lanjut',
    ];

    /** The calls that name a branch, filtered by this vocabulary, in source order. */
    private const CALL_VOCABULARY = [
        'helpMessage', 'todaySummary', 'attachMigrationKtpFromWa', 'updateMigrationPhoneFromWa', 'findMigrationForCommand',
        'correctMigrationSnFromWa', 'dispatchWaExecution', 'canUseWa', 'confirmGuidedSelectionFromWa', 'selectGuidedCandidateFromWa',
        'candidateSelectionPreviewMessage', 'readVerifiedMigrationPassword', 'statusMessage', 'cancelGuidedSelectionFromWa',
        'guidedPreviewBlockedMessage', 'shouldUseC300UncfgPath', 'createGuidedDraftFromWa', 'guardPartialCancelFromWa',
        'migrationOperationLockKey', 'findOrCreateMigration', 'partialExecutionState', 'resendOverPartial', 'differentSn',
        'prepareReviewDraft', 'applyPayload',
    ];

    private const CALL_ORDER = [
        'helpMessage', 'todaySummary', 'attachMigrationKtpFromWa', 'updateMigrationPhoneFromWa', 'findMigrationForCommand',
        'correctMigrationSnFromWa', 'dispatchWaExecution', 'canUseWa', 'confirmGuidedSelectionFromWa', 'canUseWa',
        'selectGuidedCandidateFromWa', 'findMigrationForCommand', 'candidateSelectionPreviewMessage', 'findMigrationForCommand',
        'readVerifiedMigrationPassword', 'statusMessage', 'canUseWa', 'cancelGuidedSelectionFromWa', 'guidedPreviewBlockedMessage',
        'shouldUseC300UncfgPath', 'createGuidedDraftFromWa', 'findMigrationForCommand', 'guardPartialCancelFromWa',
        'migrationOperationLockKey', 'findOrCreateMigration', 'partialExecutionState', 'resendOverPartial', 'differentSn',
        'correctMigrationSnFromWa', 'prepareReviewDraft', 'applyPayload', 'prepareReviewDraft',
    ];

    private const IF_COUNT = 31;

    private MigrationDispatchOrderFakeParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->parser = new MigrationDispatchOrderFakeParser;
        $this->app->instance(CustomerMigrationMessageParser::class, $this->parser);
        $this->app->forgetInstance(CustomerMigrationService::class);
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    // ───────────────────────── 1. source order ─────────────────────────

    /** The body of handleInbound, cut out with the tokenizer (same technique as the API surface test). */
    private function handleInboundBody(): string
    {
        $tokens = token_get_all((string) file_get_contents(dirname(__DIR__, 2).'/app/Services/CustomerMigrationService.php'));
        $count = count($tokens);
        $start = null;
        for ($i = 0; $i < $count - 2; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (is_array($tokens[$j]) && $tokens[$j][1] === 'handleInbound') {
                $start = $j;
                break;
            }
        }
        $this->assertNotNull($start, 'handleInbound not found');

        $depth = 0;
        $opened = false;
        $body = '';
        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            if ($text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $opened = true;
            } elseif ($text === '}') {
                $depth--;
            }
            if ($opened) {
                $body .= $text;
            }
            if ($opened && $depth === 0) {
                break;
            }
        }

        return $body;
    }

    public function test_the_flag_reads_of_handle_inbound_keep_their_order(): void
    {
        preg_match_all('/\$parsed\[\'(is_\w+)\'\]/', $this->handleInboundBody(), $matches);

        $this->assertSame(self::FLAG_READS, $matches[1]);
    }

    public function test_the_dispatch_calls_of_handle_inbound_keep_their_order(): void
    {
        preg_match_all('/->(\w+)\(/', $this->handleInboundBody(), $matches);
        $calls = array_values(array_filter($matches[1], fn (string $name): bool => in_array($name, self::CALL_VOCABULARY, true)));

        $this->assertSame(self::CALL_ORDER, $calls);
    }

    public function test_handle_inbound_keeps_its_branch_count(): void
    {
        $this->assertSame(self::IF_COUNT, preg_match_all('/\bif \(/', $this->handleInboundBody()));
    }

    // ───────────────────────── 2. flag precedence (behaviour) ─────────────────────────

    /** @return list<string> */
    private function lastTexts(): array
    {
        $last = $this->steps[count($this->steps) - 1] ?? ['events' => []];
        $texts = [];
        foreach ($last['events'] as $event) {
            if (($event['type'] ?? '') === 'wa.send') {
                $texts[] = (string) $event['text'];
            }
        }

        return $texts;
    }

    /**
     * The parser sets exactly these flags (all others false). The sender is a stranger of the guided group, so every
     * branch has its own refusal text and the first branch is the one that answers.
     *
     * @param  list<string>  $flags
     * @param  array<string, mixed>  $extra
     */
    private function sayFlags(string $label, array $flags, int $id, array $extra = []): string
    {
        // The reply sender drops an identical text within 15 seconds: move the clock so every step is answered.
        Carbon::setTestNow(Carbon::now()->addSeconds(20));
        $this->parser->force = array_fill_keys($flags, true) + ['migration_id' => $id, 'candidate_number' => 9, 'phone' => '123', 'sn_update_value' => 'ABC'] + $extra;
        $this->say($label, '#migrasi cek #'.$id, self::STRANGER);
        $this->parser->force = null;

        return implode("\n", $this->lastTexts());
    }

    public function test_the_first_branch_answers_when_two_flags_are_set(): void
    {
        $this->seedWorld();
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE31', 'port' => '1/2/2', 'onu_id' => 3]];
        $id = $this->guidedIntake('draft by TECH, automatic dry-run', 'budi', 'FHTTC0FFEE31');

        // [earlier flag, later flag, what the earlier branch says, what the later branch would say]
        $pairs = [
            ['is_help', 'is_ktp', 'Bantuan Migrasi', 'Foto KTP belum terbaca'],
            ['is_ktp', 'is_phone_update', 'Foto KTP belum terbaca', 'Nomor HP migrasi'],
            ['is_phone_update', 'is_sn_update', 'Nomor HP migrasi', 'Koreksi SN'],
            ['is_sn_update', 'is_eksekusi', 'Koreksi SN', 'Eksekusi migrasi diblokir'],
            ['is_eksekusi', 'is_konfirmasi', 'Eksekusi migrasi diblokir', 'Konfirmasi #'],
            ['is_konfirmasi', 'is_pilih', 'Kandidat harus dikonfirmasi oleh teknisi yang memilihnya', 'Pilihan migrasi tidak valid'],
            ['is_pilih', 'is_cek', 'Pilihan migrasi tidak valid', 'Status: '],
            ['is_cek', 'is_batal', 'Status: ', 'Hanya teknisi yang membuat atau memilih'],
            ['is_batal', 'is_lanjut', 'Hanya teknisi yang membuat atau memilih', 'belum mengubah data'],
        ];
        foreach ($pairs as [$first, $second, $firstSays, $secondSays]) {
            // Each branch alone says its own text (so the pair below can tell them apart).
            $this->assertStringContainsString($firstSays, $this->sayFlags($first.' alone', [$first], $id), $first.' alone');
            $this->assertStringContainsString($secondSays, $this->sayFlags($second.' alone', [$second], $id), $second.' alone');

            $both = $this->sayFlags($first.' + '.$second, [$first, $second], $id);
            $this->assertStringContainsString($firstSays, $both, $first.' + '.$second.': the earlier branch must answer');
            $this->assertStringNotContainsString($secondSays, $both, $first.' + '.$second.': the later branch must not answer');
        }
        $this->probeLocksFree();
    }

    public function test_maintenance_refuses_the_write_commands_and_lets_cek_and_batal_through(): void
    {
        $this->seedWorld();
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE32', 'port' => '1/2/2', 'onu_id' => 3]];
        $id = $this->guidedIntake('draft by TECH, automatic dry-run', 'budi', 'FHTTC0FFEE32');
        Setting::set('migration_maintenance_mode', '1');

        foreach (['is_ktp', 'is_phone_update', 'is_sn_update', 'is_pilih', 'is_konfirmasi'] as $flag) {
            $text = $this->sayFlags('maintenance + '.$flag, [$flag], $id);
            $this->assertStringContainsString('Maintenance migrasi sementara', $text, $flag.' must be refused in maintenance');
        }
        $this->assertStringContainsString('Status: ', $this->sayFlags('maintenance + is_cek', ['is_cek'], $id));
        $batal = $this->sayFlags('maintenance + is_batal', ['is_batal'], $id);
        $this->assertStringNotContainsString('Maintenance migrasi sementara', $batal);
        $this->assertStringContainsString('Hanya teknisi yang membuat atau memilih', $batal);

        Setting::set('migration_maintenance_mode', '0');
        $this->probeLocksFree();
    }
}

/** A parser that can be told which flags to return; everything else is the real parser. */
class MigrationDispatchOrderFakeParser extends CustomerMigrationMessageParser
{
    /** @var array<string, mixed>|null */
    public ?array $force = null;

    /** @return array<string, mixed> */
    public function parse(string $text): array
    {
        $parsed = parent::parse($text);
        if ($this->force === null) {
            return $parsed;
        }
        foreach (array_keys($parsed) as $key) {
            if (str_starts_with((string) $key, 'is_')) {
                $parsed[$key] = false;
            }
        }

        return array_merge($parsed, ['is_migrasi' => true], $this->force);
    }
}
