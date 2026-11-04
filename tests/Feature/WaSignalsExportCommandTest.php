<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WaChatSignal;
use App\Services\WaSignalDatasetExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class WaSignalsExportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SALT = 'unit-test-salt-0123456789';

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wa_signals.export_salt' => self::SALT]);
        $this->out = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wa-export-'.uniqid().'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->out);
        parent::tearDown();
    }

    public function test_missing_salt_refuses_to_run_and_writes_nothing(): void
    {
        config(['services.wa_signals.export_salt' => '']);

        $this->artisan('wa-signals:export', ['--output' => $this->out])
            ->expectsOutputToContain('WA_SIGNALS_EXPORT_SALT')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->out);
    }

    public function test_output_is_redacted_hashed_and_has_no_raw_identity_or_sender_name(): void
    {
        $this->seedPeople();
        $this->signal('MSG-RAW-1', 'Slamet sudah di lokasi pelanggan Budiansyah, ONU ZTEG0000A00F pppoe a001x ip 198.18.35.5 hp 081100000123 NIK 3501010110000001 tikor -6.98221, 111.63221');

        $rows = $this->runJsonl();

        $this->assertCount(1, $rows);
        $raw = (string) file_get_contents($this->out);
        foreach (['081100000123', '628120000007', 'ZTEG0000A00F', 'a001x', '198.18.35.5', 'Budiansyah', 'Slamet', '3501010110000001', '-6.98221', 'MSG-RAW-1', '120363-work@g.us', 'Nama Pushname'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "leaked: {$needle}");
        }
        $text = $rows[0]['text'];
        foreach (['[SN]', '[PPPOE]', '[IP]', '[HP]', '[NIK]', '[TIKOR]', '[NAMA]'] as $marker) {
            $this->assertStringContainsString($marker, $text);
        }
        $this->assertArrayNotHasKey('sender_name', $rows[0]);
        $this->assertArrayNotHasKey('sender_jid', $rows[0]);
        $this->assertArrayNotHasKey('message_id', $rows[0]);
    }

    public function test_hashes_are_stable_hmac_and_roles_resolve_from_users(): void
    {
        $this->seedPeople();
        $this->signal('MSG-A', 'cek');
        $this->signal('MSG-B', 'cek lagi', '628120000010@s.whatsapp.net');

        $first = $this->runJsonl();
        $second = $this->runJsonl();

        $this->assertSame($first[0]['message_hash'], $second[0]['message_hash']);
        $this->assertSame(hash_hmac('sha256', 'MSG-A', self::SALT), $first[0]['message_hash']);
        $this->assertSame(hash_hmac('sha256', '120363-work@g.us', self::SALT), $first[0]['group_hash']);
        $this->assertSame(hash_hmac('sha256', '628120000007', self::SALT), $first[0]['sender_hash']);
        $this->assertSame('teknisi', $first[0]['sender_role']);
        $this->assertSame('unknown', $first[1]['sender_role']);

        config(['services.wa_signals.export_salt' => 'another-salt-0123456789']);
        $third = $this->runJsonl();
        $this->assertNotSame($first[0]['message_hash'], $third[0]['message_hash']);
    }

    public function test_only_work_channel_is_exported(): void
    {
        $this->signal('MSG-W', 'work');
        $this->signal('MSG-ACT', 'activation', '628120000007@s.whatsapp.net', 'activation');

        $this->assertCount(1, $this->runJsonl());
    }

    public function test_labels_come_from_progress_join_and_ticket_link(): void
    {
        $ticket = $this->seedTicket();
        $occurred = now()->subHours(1);

        $this->signal('MSG-L1', 'selesai pasang', occurred: $occurred);
        DB::table('technician_progress_reports')->insert([
            'work_date' => now()->toDateString(),
            'message_id' => 'MSG-L1',
            'status' => 'done',
            'actions' => json_encode(['psb']),
            'sites' => json_encode(['Gadang']),
            'meta' => json_encode(['fields' => ['ticket_id' => $ticket->id]]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signal('MSG-L2', 'follow up '.$ticket->code, occurred: $occurred);
        $this->signal('MSG-L3', 'tidak ada tautan', occurred: $occurred);

        $rows = collect($this->runJsonl());
        $l1 = $rows->first(fn ($r) => $r['progress_source'] === 'progress_report');
        $this->assertSame('psb', $l1['progress_action']);
        $this->assertSame('Gadang', $l1['progress_site']);
        $this->assertSame('done', $l1['progress_status']);
        $this->assertSame($ticket->id, $l1['linked_ticket_id']);
        $this->assertSame('explicit', $l1['link_method']);
        $this->assertTrue($l1['became_ticket']);
        $this->assertSame('incident', $l1['ticket_type']);
        $this->assertSame(90, $l1['resolution_minutes']);

        $l2 = $rows->first(fn ($r) => $r['link_method'] === 'code_in_text');
        $this->assertSame($ticket->id, $l2['linked_ticket_id']);

        $l3 = $rows->first(fn ($r) => $r['link_method'] === 'none');
        $this->assertNull($l3['linked_ticket_id']);
        $this->assertFalse($l3['became_ticket']);
        $this->assertNull($l3['resolution_minutes']);
        $this->assertSame('none', $l3['progress_source']);
    }

    public function test_regex_labels_and_since_filter(): void
    {
        $this->signal('MSG-OLD', 'internet down', occurred: now()->subDays(10));
        $this->signal('MSG-NEW', 'internet down', occurred: now()->subHour());

        $this->artisan('wa-signals:export', ['--output' => $this->out, '--since' => now()->subDay()->toDateString()])->assertSuccessful();
        $rows = $this->readJsonl();
        $this->assertCount(1, $rows);
        $this->assertSame('incident', $rows[0]['intent_rule']);
        $this->assertArrayHasKey('domain_rule', $rows[0]);
        $this->assertArrayHasKey('severity_rule', $rows[0]);
    }

    public function test_csv_format_has_header_and_prints_path_and_row_count(): void
    {
        $this->signal('MSG-C1', 'halo, "kutip"');
        $csv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wa-export-'.uniqid().'.csv';

        $this->artisan('wa-signals:export', ['--format' => 'csv', '--output' => $csv])
            ->expectsOutputToContain($csv)
            ->expectsOutputToContain('Rows: 1')
            ->assertSuccessful();

        $fh = fopen($csv, 'rb');
        $header = fgetcsv($fh, null, ',', '"', '\\');
        $row = fgetcsv($fh, null, ',', '"', '\\');
        fclose($fh);
        @unlink($csv);

        $this->assertSame(WaSignalDatasetExporter::FIELDS, $header);
        $this->assertSame(hash_hmac('sha256', 'MSG-C1', self::SALT), $row[array_search('message_hash', $header, true)]);
        $this->assertSame('wa_work_signals.v1', $row[0]);
    }

    public function test_csv_neutralises_formulas_and_site_action_are_redacted(): void
    {
        $this->seedPeople();
        $this->signal('MSG-F1', '=HYPERLINK("x")');
        DB::table('technician_progress_reports')->insert([
            'work_date' => now()->toDateString(),
            'message_id' => 'MSG-F1',
            'actions' => json_encode(['hubungi 081100000123']),
            'sites' => json_encode(['rumah Budiansyah']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $csv = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wa-export-'.uniqid().'.csv';

        $this->artisan('wa-signals:export', ['--format' => 'csv', '--output' => $csv])->assertSuccessful();
        $content = (string) file_get_contents($csv);
        @unlink($csv);

        $this->assertStringContainsString("\"'=HYPERLINK", $content);
        $this->assertStringNotContainsString('081100000123', $content);
        $this->assertStringNotContainsString('Budiansyah', $content);
    }

    public function test_existing_output_requires_force(): void
    {
        $this->signal('MSG-X', 'cek');
        file_put_contents($this->out, 'keep');

        $this->artisan('wa-signals:export', ['--output' => $this->out])->assertFailed();
        $this->assertSame('keep', file_get_contents($this->out));

        $this->artisan('wa-signals:export', ['--output' => $this->out, '--force' => true])->assertSuccessful();
        $this->assertNotSame('keep', file_get_contents($this->out));
    }

    public function test_pppoe_usernames_are_all_hidden_in_compound_tokens(): void
    {
        $this->seedPeople();
        Customer::create([
            'code' => 'A002', 'name' => 'Citra', 'phone' => '0810000009', 'address' => 'Addr',
            'area_id' => Area::first()->id, 'package_id' => Package::first()->id, 'pppoe_user' => 'b002y', 'status' => 'active',
        ]);
        $this->signal('MSG-P1', 'xa001x-a001x a001x.b002y a001x,a001x a001x.');

        $text = $this->runJsonl()[0]['text'];

        $this->assertStringNotContainsString('a001x', $text);
        $this->assertStringNotContainsString('b002y', $text);
        $this->assertStringContainsString('[PPPOE]', $text);
    }

    public function test_relative_output_into_new_public_subfolder_is_refused(): void
    {
        $rel = ltrim(str_replace('\\', '/', substr(public_path('wa-new-dir/out.jsonl'), strlen(base_path()))), '/');
        $cwd = getcwd();
        chdir(base_path());
        try {
            $this->artisan('wa-signals:export', ['--output' => $rel])->assertFailed();
            $this->artisan('wa-signals:export', ['--output' => 'storage/../public/wa-new-dir/out.jsonl'])->assertFailed();
        } finally {
            chdir($cwd);
        }
        $this->assertDirectoryDoesNotExist(public_path('wa-new-dir'));
    }

    public function test_output_under_public_is_refused(): void
    {
        $this->artisan('wa-signals:export', ['--output' => public_path('leak.jsonl')])->assertFailed();
        $this->assertFileDoesNotExist(public_path('leak.jsonl'));
    }

    public function test_export_is_read_only(): void
    {
        $this->seedPeople();
        $this->signal('MSG-RO', 'cek');
        $before = [WaChatSignal::count(), SupportTicket::count(), Customer::count(), User::count()];

        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        $this->artisan('wa-signals:export', ['--output' => $this->out, '--force' => true])->assertSuccessful();

        $this->assertSame([], $writes);
        $this->assertSame($before, [WaChatSignal::count(), SupportTicket::count(), Customer::count(), User::count()]);
    }

    private function seedPeople(): void
    {
        User::factory()->create(['role' => 'teknisi', 'name' => 'Slamet Riyadi', 'phone' => '0812-0000-007']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        Customer::create([
            'code' => 'A001', 'name' => 'Budiansyah Santoso', 'phone' => '0810000008', 'address' => 'Addr',
            'area_id' => $area->id, 'package_id' => $package->id, 'pppoe_user' => 'a001x', 'status' => 'active',
        ]);
    }

    private function seedTicket(): SupportTicket
    {
        $ticket = SupportTicket::create([
            'created_by' => User::factory()->create()->id,
            'gangguan' => 'LOS',
            'subject' => 'LOS',
            'status' => 'resolved',
            'priority' => 'high',
            'type' => 'incident',
        ]);
        DB::table('support_tickets')->where('id', $ticket->id)->update([
            'created_at' => now()->subMinutes(30),
            'resolved_at' => now()->addMinutes(60),
        ]);

        return $ticket->fresh();
    }

    private function signal(string $messageId, string $text, string $sender = '628120000007@s.whatsapp.net', string $channel = 'work', $occurred = null): WaChatSignal
    {
        return WaChatSignal::create([
            'channel' => $channel,
            'domain' => 'ticket',
            'intent' => 'incident',
            'severity' => 'normal',
            'group_jid' => '120363-work@g.us',
            'sender_jid' => $sender,
            'sender_name' => 'Nama Pushname',
            'message_id' => $messageId,
            'summary' => $text,
            'redacted_text' => $text,
            'payload' => ['has_image' => false],
            'occurred_at' => $occurred ?? now(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function runJsonl(): array
    {
        $this->artisan('wa-signals:export', ['--output' => $this->out, '--force' => true])->assertSuccessful();

        return $this->readJsonl();
    }

    /** @return list<array<string,mixed>> */
    private function readJsonl(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->out)));

        return array_values(array_map(fn ($l) => json_decode($l, true, 512, JSON_THROW_ON_ERROR), $lines));
    }
}
