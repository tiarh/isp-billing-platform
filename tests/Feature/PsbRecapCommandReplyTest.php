<?php

namespace Tests\Feature;

use App\Jobs\ProcessPsbRecapCommandJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\PsbAreaRecapService;
use App\Services\PsbRecapCommandParser;
use App\Services\PsbWaMessages;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use ZipArchive;

#[Group('blocking-psb')]
class PsbRecapCommandReplyTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = '120363-activation@g.us';

    private const DOC_FAILED = 'File Excel gagal dikirim, coba ketik ulang command-nya.';

    private Area $klampok;

    private Package $package;

    private int $seq = 0;

    /** @var list<array{0: string, 1: string}> */
    private array $texts = [];

    /** @var list<array<string, mixed>> */
    private array $documents = [];

    /** @var list<string> */
    private array $tempCopies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:30', 'Asia/Jakarta'));
        File::deleteDirectory(storage_path('app/psb/recap'));

        Setting::set('psb_recap_cmd_enabled', '1');
        $this->klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        Area::create(['name' => 'SKYNET-BIRU', 'code' => 'biru']);
        $this->package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/psb/recap'));
        foreach ($this->tempCopies as $copy) {
            @unlink($copy);
        }
        $this->travelBack();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function customer(string $joinDate, array $over = []): Customer
    {
        $this->seq++;

        return Customer::create(array_merge([
            'code' => 'RP-'.$this->seq,
            'name' => 'Pelanggan '.$this->seq,
            'address' => 'Dusun Klampok RT 03 RW 05',
            'package_id' => $this->package->id,
            'area_id' => $this->klampok->id,
            'pppoe_user' => 'user'.$this->seq,
            'status' => 'active',
            'join_date' => $joinDate,
        ], $over));
    }

    private function seedCustomers(int $count, array $over = []): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->customer('2026-10-'.str_pad((string) (1 + $i % 14), 2, '0', STR_PAD_LEFT), $over);
        }
    }

    /**
     * @param  array{success: bool, error?: string}|\Throwable  $documentResult
     */
    private function fakeWhatsapp(array|\Throwable $documentResult = ['success' => true, 'message_id' => 'DOC1'], bool $textOk = true): EvolutionApiService
    {
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) use ($textOk) {
            $this->texts[] = [$jid, $text];

            return $textOk ? ['success' => true] : ['success' => false, 'error' => 'down'];
        });
        $evo->shouldReceive('sendDocument')->andReturnUsing(function (string $jid, string $path, string $caption = '', ?string $fileName = null) use ($documentResult) {
            // The job deletes the file right after sending: keep a copy for inspection.
            $copy = null;
            if (is_file($path)) {
                $copy = tempnam(sys_get_temp_dir(), 'rekapcopy');
                copy($path, $copy);
                $this->tempCopies[] = $copy;
            }
            $this->documents[] = ['jid' => $jid, 'path' => $path, 'copy' => $copy, 'caption' => $caption, 'fileName' => $fileName, 'exists' => is_file($path)];
            if ($documentResult instanceof \Throwable) {
                throw $documentResult;
            }

            return $documentResult;
        });

        return $evo;
    }

    private function runJob(string $text, ?EvolutionApiService $evo = null, string $group = self::GROUP, string $sender = '6281111111111@s.whatsapp.net', ?string $messageId = null, int $attempt = 1): void
    {
        $job = new ProcessPsbRecapCommandJob([
            'group_jid' => $group,
            'sender_jid' => $sender,
            'message_id' => $messageId ?? 'MSG-'.(++$this->seq),
            'text' => $text,
        ]);
        if ($attempt > 1) {
            $queueJob = \Mockery::mock(QueueJob::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $job->setJob($queueJob);
        }
        $job->handle(
            app(PsbRecapCommandParser::class),
            app(PsbAreaRecapService::class),
            app(PsbWaMessages::class),
            $evo ?? $this->fakeWhatsapp(),
        );
    }

    private function lastText(): string
    {
        return $this->texts[array_key_last($this->texts)][1];
    }

    // --- text format -------------------------------------------------------

    public function test_small_result_lists_names_and_follows_the_layout(): void
    {
        $this->seedCustomers(3);
        $this->customer('2026-10-04', ['status' => 'terminated']);

        $this->runJob('#rekap psb klampok 1-14 okt');

        $text = $this->texts[0][1];
        $this->assertSame(self::GROUP, $this->texts[0][0]);
        $this->assertStringContainsString('*REKAP PSB - KLAMPOK*', $text);
        $this->assertStringContainsString('Periode: 01/10/2026 - 14/10/2026 (14 hari)', $text);
        $this->assertStringContainsString('Dibuat: 14/10/2026 21:30 WIB', $text);
        $this->assertStringContainsString('*Total: 4 pelanggan*', $text);
        $this->assertStringContainsString('Aktif 3 | Belum aktif 0 | Isolir 0 | Putus 1', $text);
        $this->assertStringContainsString('Sumber: WA 0 | Non-WA 4', $text);
        $this->assertStringContainsString('*Per tanggal*', $text);
        $this->assertStringContainsString('*Per paket*', $text);
        $this->assertStringContainsString('- 10 Mbps: 4', $text);
        $this->assertStringContainsString('*Per RW*', $text);
        $this->assertStringContainsString('- RW 05: 4', $text);
        $this->assertStringContainsString('*Daftar nama*', $text);
        $this->assertStringContainsString('Pelanggan 1', $text);
        $this->assertStringNotContainsString('Daftar nama lengkap ada di file Excel', $text);
        $this->assertLessThan(3500, mb_strlen($text));
    }

    public function test_exactly_30_names_are_listed_but_31_switches_to_numbers_only(): void
    {
        $this->seedCustomers(30);
        $this->runJob('#rekap psb klampok 1-14 okt');
        $this->assertStringContainsString('*Daftar nama*', $this->texts[0][1]);
        $this->assertStringNotContainsString('Daftar nama lengkap ada di file Excel', $this->texts[0][1]);

        $this->customer('2026-10-14', ['name' => 'Nama Ke Tiga Puluh Satu']);
        $this->texts = [];
        Cache::flush();
        $this->runJob('#rekap psb klampok 1-14 okt');

        $text = $this->texts[0][1];
        $this->assertStringContainsString('*Total: 31 pelanggan*', $text);
        $this->assertStringNotContainsString('*Daftar nama*', $text);
        $this->assertStringNotContainsString('Nama Ke Tiga Puluh Satu', $text);
        $this->assertStringContainsString('Daftar nama lengkap ada di file Excel', $text);
        $this->assertStringContainsString('*Per RW*', $text);
    }

    public function test_the_list_limit_follows_the_setting(): void
    {
        Setting::set('psb_recap_cmd_list_limit', '2');
        $this->seedCustomers(3);

        $this->runJob('#rekap psb klampok 1-14 okt');

        $this->assertStringContainsString('Daftar nama lengkap ada di file Excel', $this->texts[0][1]);
        $this->assertStringNotContainsString('Pelanggan 1', $this->texts[0][1]);
    }

    public function test_semua_mode_shows_numbers_per_area_and_never_names(): void
    {
        $this->seedCustomers(3);
        $this->customer('2026-10-05', ['area_id' => Area::query()->where('code', 'biru')->value('id'), 'name' => 'Orang Biru']);

        $this->runJob('#rekap psb semua 1-14 okt');

        $text = $this->texts[0][1];
        $this->assertStringContainsString('*REKAP PSB - SEMUA AREA*', $text);
        $this->assertStringContainsString('*Per area*', $text);
        $this->assertStringContainsString('- KLAMPOK: 3', $text);
        $this->assertStringContainsString('- BIRU: 1', $text);
        $this->assertStringContainsString('*Per tanggal*', $text);
        $this->assertStringNotContainsString('*Daftar nama*', $text);
        $this->assertStringNotContainsString('Orang Biru', $text);
        $this->assertStringNotContainsString('Pelanggan 1', $text);
        $this->assertStringContainsString('Daftar nama lengkap ada di file Excel', $text);
    }

    public function test_an_empty_result_sends_text_only_and_no_excel(): void
    {
        $this->runJob('#rekap psb klampok 1-14 okt');

        $this->assertStringContainsString('Tidak ada PSB tercatat', $this->texts[0][1]);
        $this->assertCount(1, $this->texts);
        $this->assertSame([], $this->documents);
    }

    public function test_text_stays_under_3500_chars_for_500_customers_in_the_worst_case_shape(): void
    {
        // Worst case: 92 dates, many packages with long names, many RW, many areas.
        $byDate = [];
        $day = CarbonImmutable::parse('2026-07-15');
        for ($i = 0; $i < 92; $i++) {
            $byDate[$day->addDays($i)->toDateString()] = 5 + $i % 3;
        }
        $byPackage = [];
        $byRw = [];
        $byArea = [];
        for ($i = 0; $i < 60; $i++) {
            $byPackage['PAKET INTERNET RUMAHAN SANGAT PANJANG NOMOR '.$i] = 60 - $i;
            $byRw['RW '.str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = 60 - $i;
            $byArea['AREA DENGAN NAMA YANG SANGAT PANJANG SEKALI '.$i] = 60 - $i;
        }
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['join_date' => '2026-10-01', 'name' => 'NAMA PELANGGAN PANJANG SEKALI '.$i, 'package' => 'PAKET', 'rw' => '01', 'customer_status' => 'aktif'];
        }
        $base = [
            'topic' => 'psb', 'area_id' => null, 'area_label' => 'AREA DENGAN NAMA YANG SANGAT PANJANG',
            'from' => '2026-07-15', 'to' => '2026-10-14', 'days' => 92,
            'generated_at' => CarbonImmutable::parse('2026-10-14 21:30', 'Asia/Jakarta'),
            'total' => 500, 'excluded_migration' => 3,
            'by_status' => ['aktif' => 400, 'belum aktif' => 30, 'isolir' => 50, 'putus' => 20],
            'by_source' => ['WA' => 450, 'Non-WA' => 50],
            'by_date' => $byDate, 'by_package' => $byPackage, 'by_rw' => $byRw, 'by_area' => $byArea,
            'rows' => $rows,
        ];

        $messages = app(PsbWaMessages::class);
        foreach ([false, true] as $all) {
            $text = $messages->areaRecap($base + ['all' => $all], 30);
            $this->assertLessThan(3500, mb_strlen($text), $all ? 'semua mode' : 'single area');
            $this->assertStringNotContainsString('NAMA PELANGGAN PANJANG', $text);
        }
    }

    public function test_the_text_never_contains_phone_nik_or_password(): void
    {
        $this->customer('2026-10-02', [
            'phone' => '081200000044',
            'nik' => '3573012345678901',
            'pppoe_password' => 'canary-pw',
            'pppoe_user' => 'pppoe_user_visible_only_in_excel',
        ]);

        $this->runJob('#rekap psb klampok 1-14 okt');

        foreach (['081200000044', '3573012345678901', 'canary-pw', '6281200000044'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->texts[0][1]);
        }
    }

    // --- Excel -------------------------------------------------------------

    public function test_excel_is_sent_with_the_text_and_has_ringkasan_and_data_without_secrets(): void
    {
        $this->seedCustomers(2);
        $this->customer('2026-10-03', [
            'name' => 'Budi Rahasia',
            'phone' => '081200000044',
            'nik' => '3573012345678901',
            'pppoe_password' => 'canary-pw',
            'pppoe_user' => 'budi_pppoe',
        ]);
        PsbDraft::create(['status' => 'applied', 'message_id' => 'xl-1', 'customer_id' => Customer::query()->where('name', 'Budi Rahasia')->value('id'), 'rt' => '03', 'rw' => '05']);

        $this->runJob('#rekap psb klampok 1-14 okt');

        $this->assertCount(1, $this->texts);
        $this->assertCount(1, $this->documents);
        $document = $this->documents[0];
        $this->assertSame(self::GROUP, $document['jid']);
        $this->assertTrue($document['exists']);
        $this->assertStringStartsWith(storage_path('app/psb/recap').DIRECTORY_SEPARATOR, $document['path']);
        $this->assertStringEndsWith('.xlsx', $document['fileName']);
        $this->assertStringContainsString('Rekap PSB KLAMPOK 01/10/2026 - 14/10/2026', $document['caption']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($document['copy']) === true);
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $summary = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $data = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $all = $workbook.$summary.$data.(string) $zip->getFromName('xl/styles.xml');
        $zip->close();

        $this->assertStringContainsString('name="Ringkasan"', $workbook);
        $this->assertStringContainsString('name="Data"', $workbook);
        $this->assertStringContainsString('<f>COUNT(Data!A2:A4)</f>', $summary);
        $this->assertStringContainsString('<f>COUNTIF(Data!J2:J4,&quot;=aktif&quot;)</f>', str_replace('"', '&quot;', $summary));
        $this->assertStringContainsString('Per paket', $summary);
        $this->assertStringContainsString('Per tanggal pasang', $summary);
        $this->assertStringContainsString('Per RW', $summary);
        $this->assertStringNotContainsString('Per area', $summary);

        foreach (['No', 'Tanggal pasang', 'Nama', 'Area', 'RT', 'RW', 'ODP', 'Paket', 'User PPPoE', 'Status pelanggan', 'Status ONU', 'Online', 'Sumber (WA/Non-WA)', 'ID pelanggan', 'ID draft WA', 'Status draft', 'Catatan'] as $header) {
            $this->assertStringContainsString('<t xml:space="preserve">'.$header.'</t>', $data, $header);
        }
        $this->assertStringContainsString('Budi Rahasia', $data);
        $this->assertStringContainsString('budi_pppoe', $data);
        $this->assertSame(4, substr_count($data, '<row '), 'header + 3 customers');

        foreach (['081200000044', '3573012345678901', 'canary-pw'] as $secret) {
            $this->assertStringNotContainsString($secret, $all);
        }
    }

    public function test_semua_excel_has_the_per_area_summary_and_an_area_column(): void
    {
        $this->seedCustomers(2);
        $this->customer('2026-10-05', ['area_id' => Area::query()->where('code', 'biru')->value('id')]);

        $this->runJob('#rekap psb semua 1-14 okt');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($this->documents[0]['copy']) === true);
        $summary = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $data = (string) $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();

        $this->assertStringContainsString('Per area', $summary);
        $this->assertStringContainsString('<f>COUNTIF(Data!D2:D4,', $summary);
        $this->assertStringContainsString('BIRU', $data);
        $this->assertStringContainsString('KLAMPOK', $data);
    }

    public function test_a_failed_document_send_still_delivers_the_text_then_the_fallback_notice(): void
    {
        $this->seedCustomers(2);

        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(['success' => false, 'error' => 'Connection Closed']));

        $this->assertCount(2, $this->texts);
        $this->assertStringContainsString('*Total: 2 pelanggan*', $this->texts[0][1]);
        $this->assertSame(self::DOC_FAILED, $this->texts[1][1]);
    }

    public function test_a_document_exception_is_handled_like_a_failed_send(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(new \RuntimeException('boom')));

        $this->assertCount(2, $this->texts);
        $this->assertSame(self::DOC_FAILED, $this->texts[1][1]);
    }

    public function test_when_the_text_cannot_be_sent_the_job_fails_and_no_document_is_attempted(): void
    {
        $this->seedCustomers(1);

        $this->expectException(\RuntimeException::class);
        try {
            $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(textOk: false));
        } finally {
            $this->assertSame([], $this->documents);
        }
    }

    public function test_old_excel_files_are_pruned_after_7_days_and_recent_ones_kept(): void
    {
        $dir = storage_path('app/psb/recap');
        File::ensureDirectoryExists($dir);
        $old = $dir.DIRECTORY_SEPARATOR.'old.xlsx';
        $recent = $dir.DIRECTORY_SEPARATOR.'recent.xlsx';
        $other = $dir.DIRECTORY_SEPARATOR.'keep.txt';
        foreach ([$old, $recent, $other] as $file) {
            File::put($file, 'x');
        }
        touch($old, now()->subDays(8)->getTimestamp());
        touch($other, now()->subDays(30)->getTimestamp());
        touch($recent, now()->subDays(2)->getTimestamp());

        $this->runJob('#rekap psb');

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        $this->assertFileExists($other);
    }

    // --- error replies -----------------------------------------------------

    public function test_usage_and_error_replies(): void
    {
        $this->runJob('#rekap psb');
        $this->assertStringContainsString('Format: *#rekap psb <daerah|semua> [tanggal]*', $this->lastText());

        Cache::flush();
        $this->runJob('#rekap psb klampok 31/02');
        $this->assertStringContainsString('Tanggal tidak valid', $this->lastText());

        Cache::flush();
        $this->runJob('#rekap psb klampok 1 jul - 14 okt');
        $this->assertStringContainsString('terlalu panjang (maksimal 92 hari', $this->lastText());

        Cache::flush();
        $this->runJob('#rekap psb tidakadadaerah');
        $this->assertStringContainsString('tidak ditemukan', $this->lastText());

        $this->assertSame([], $this->documents);
    }

    // --- rate limit / flag -------------------------------------------------

    public function test_a_second_request_in_the_same_group_within_20_seconds_is_rate_limited(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt');
        $this->runJob('#rekap psb klampok 1-14 okt');

        $this->assertSame(1, count($this->documents), 'second request must not build/send another Excel');
        $this->assertSame('Rekap baru saja diminta di grup ini. Tunggu sekitar 20 detik lalu ketik lagi.', $this->lastText());

        // Another group is not affected.
        $this->runJob('#rekap psb klampok 1-14 okt', null, '120363-other@g.us');
        $this->assertSame(2, count($this->documents));
    }

    public function test_a_sender_is_limited_to_10_recaps_per_hour(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->runJob('#rekap psb', null, "group-{$i}@g.us", 'sender-a@s.whatsapp.net');
            $this->assertStringContainsString('Format:', $this->lastText(), "request {$i}");
        }

        $this->runJob('#rekap psb', null, 'group-11@g.us', 'sender-a@s.whatsapp.net');
        $this->assertStringContainsString('maksimal 10 per jam', $this->lastText());

        // A different sender is unaffected.
        $this->runJob('#rekap psb', null, 'group-12@g.us', 'sender-b@s.whatsapp.net');
        $this->assertStringContainsString('Format:', $this->lastText());

        // An hour later the counter is fresh.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 23:00', 'Asia/Jakarta'));
        $this->runJob('#rekap psb', null, 'group-13@g.us', 'sender-a@s.whatsapp.net');
        $this->assertStringContainsString('Format:', $this->lastText());
    }

    public function test_blocked_attempts_get_one_notice_per_window_then_silence(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt');   // recap (text)
        $this->runJob('#rekap psb klampok 1-14 okt');   // blocked: notice
        $this->runJob('#rekap psb klampok 1-14 okt');   // blocked: silent
        $this->runJob('#rekap psb klampok 1-14 okt');   // blocked: silent

        $this->assertCount(2, $this->texts, 'recap + a single rate-limit notice');
        $this->assertSame(1, count($this->documents));

        // Next window: the notice is available again.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:31', 'Asia/Jakarta'));
        $this->runJob('#rekap psb klampok 1-14 okt');   // recap again
        $this->runJob('#rekap psb klampok 1-14 okt');   // blocked: notice again
        $this->assertCount(4, $this->texts);
    }

    public function test_a_replay_of_the_same_message_id_is_silently_skipped(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'REPLAY-1');
        $this->assertCount(1, $this->texts);
        $this->assertCount(1, $this->documents);

        // Webhook batch replayed later (cooldown over): no text, no "tunggu", no Excel.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:32', 'Asia/Jakarta'));
        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'REPLAY-1');
        // ...and also inside the cooldown window.
        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'REPLAY-1');

        $this->assertCount(1, $this->texts);
        $this->assertCount(1, $this->documents);
    }

    public function test_a_replay_of_a_rate_limited_message_stays_silent(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt');
        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'BLOCKED-1');
        $texts = count($this->texts);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:40', 'Asia/Jakarta'));
        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'BLOCKED-1');

        $this->assertCount($texts, $this->texts);
        $this->assertCount(1, $this->documents);
    }

    public function test_a_retry_after_the_text_went_out_only_retries_the_excel_part(): void
    {
        $this->seedCustomers(2);

        // Attempt 1: the recap text is delivered, the Excel fails, and the failure
        // notice cannot be sent either, so the job throws and is retried.
        $calls = 0;
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldReceive('forChannel')->with('ops')->andReturnSelf();
        $evo->shouldReceive('sendToGroup')->andReturnUsing(function (string $jid, string $text) use (&$calls) {
            $calls++;
            $this->texts[] = [$jid, $text];

            return $calls === 1 ? ['success' => true] : ['success' => false, 'error' => 'timeout'];
        });
        $evo->shouldReceive('sendDocument')->andReturn(['success' => false, 'error' => 'Connection Closed']);

        try {
            $this->runJob('#rekap psb klampok 1-14 okt', $evo, self::GROUP, 'a@s.whatsapp.net', 'RETRY-1');
            $this->fail('attempt 1 must throw when the notice cannot be sent');
        } catch (\RuntimeException) {
            // expected: queue would retry
        }
        $this->assertCount(2, $this->texts, 'recap text + the failed notice attempt');
        $this->assertStringContainsString('*Total: 2 pelanggan*', $this->texts[0][1]);

        // Attempt 2: everything works. The recap text must NOT be sent again.
        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(), self::GROUP, 'a@s.whatsapp.net', 'RETRY-1', 2);

        $this->assertCount(2, $this->texts, 'no second copy of the recap text');
        $this->assertCount(1, $this->documents, 'the Excel is retried and delivered');

        // A further retry/replay after success is a no-op.
        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(), self::GROUP, 'a@s.whatsapp.net', 'RETRY-1', 2);
        $this->assertCount(1, $this->documents);
        $this->assertCount(2, $this->texts);
    }

    public function test_a_retry_never_resends_a_document_that_already_went_out(): void
    {
        $this->seedCustomers(1);
        Cache::put('psb-recap:text:DOC-1', 1, now()->addDay());
        Cache::put('psb-recap:doc:DOC-1', 1, now()->addDay());

        $this->runJob('#rekap psb klampok 1-14 okt', null, self::GROUP, 'a@s.whatsapp.net', 'DOC-1', 2);

        $this->assertSame([], $this->texts);
        $this->assertSame([], $this->documents);
    }

    public function test_the_excel_file_is_deleted_from_disk_after_the_send_success_or_failure(): void
    {
        $this->seedCustomers(2);

        $this->runJob('#rekap psb klampok 1-14 okt');
        $this->assertCount(1, $this->documents);
        $this->assertTrue($this->documents[0]['exists'], 'file existed while sending');
        $this->assertFileDoesNotExist($this->documents[0]['path']);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:35', 'Asia/Jakarta'));
        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(['success' => false, 'error' => 'Connection Closed']));
        $this->assertFileDoesNotExist($this->documents[1]['path']);

        $this->travelTo(CarbonImmutable::parse('2026-10-14 21:36', 'Asia/Jakarta'));
        $this->runJob('#rekap psb klampok 1-14 okt', $this->fakeWhatsapp(new \RuntimeException('boom')));
        $this->assertFileDoesNotExist($this->documents[2]['path']);

        $left = is_dir(storage_path('app/psb/recap')) ? glob(storage_path('app/psb/recap').DIRECTORY_SEPARATOR.'*.xlsx') : [];
        $this->assertSame([], $left);
    }

    public function test_excel_file_names_are_unique_per_request(): void
    {
        $this->seedCustomers(1);

        $this->runJob('#rekap psb klampok 1-14 okt');
        $this->runJob('#rekap psb klampok 1-14 okt', null, 'other-group@g.us');

        $this->assertNotSame($this->documents[0]['fileName'], $this->documents[1]['fileName']);
    }

    public function test_help_advertises_the_recap_command_only_while_the_flag_is_on(): void
    {
        $preRecapHelp = implode("\n", [
            '*Bantuan PSB WA*',
            'Format:',
            '#psb',
            'Nama: ...',
            'ODP: ODP-AREA-L3.1',
            'RT: 03',
            'RW: 04',
            'Tikor: -7.x,112.x',
            'Daerah: ...',
            'Router: RA/KRIAN/...',
            'Paket: STARTLITE/CSR/...',
            'SN: FHTTxxxxxxxx',
            'HP: 08xxxx',
            'KTP wajib. Kirim susulan: *#psb ktp #ID*',
            'Cek: *#psb cek #ID* | Retry: *#psb lanjut #ID*',
            'Cari PPPoE: *#pppoe cari <nama/user/hp>*',
            'Rekap: */psb*',
        ]);

        Setting::set('psb_recap_cmd_enabled', '0');
        $this->assertSame($preRecapHelp, app(PsbWaMessages::class)->help());

        Setting::set('psb_recap_cmd_enabled', '1');
        $on = app(PsbWaMessages::class)->help();
        $this->assertSame($preRecapHelp."\nRekap per daerah: *#rekap psb klampok 1-14 okt*", $on);
    }

    public function test_flag_turned_off_before_the_job_runs_means_silence(): void
    {
        $this->seedCustomers(1);
        Setting::set('psb_recap_cmd_enabled', '0');

        $this->runJob('#rekap psb klampok 1-14 okt');

        $this->assertSame([], $this->texts);
        $this->assertSame([], $this->documents);
    }

    public function test_the_job_ignores_text_that_is_not_a_recap_command(): void
    {
        $this->runJob('#psb bantu');
        $this->runJob('selamat pagi');

        $this->assertSame([], $this->texts);
    }
}
