<?php

namespace Tests\Unit;

use App\Services\PsbMessageParser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Characterization golden of the #psb message parser (PSB refactor PR 0, tests only).
 *
 * About 90 inputs go through parse(), isBotTrigger() and isNearMissPsb(): every command
 * form, empty labels (the 809 class of bug), a label without a value, a label on the last
 * line, router/paket lines, CRLF, emoji, extra spaces and lowercase labels. The full result
 * of each input is compared with tests/Fixtures/psb-flow/parser-corpus.golden.json.
 *
 * The golden is written ONLY with env PSB_FLOW_GOLDEN_WRITE=1 (never in CI). A change needs
 * owner OK (plan Q8). Names and numbers are fake.
 */
#[Group('blocking-psb')]
class PsbMessageParserCorpusGoldenTest extends TestCase
{
    /** @return array<string, string> label => input text */
    public static function corpus(): array
    {
        $full = "#psb\nNama: DRAFTE SANTOSO\nODP: ODP-KLAMPOK-3\nRT: 01\nRW: 02\nTikor: -6.745468,111.601127\nDaerah: Klampok\nRouter: RA\nPaket: 200M\nSN: FHTTC0FFEE01\nHP: 081200000021";

        return [
            // full forms
            'full form' => $full,
            'full form CRLF' => str_replace("\n", "\r\n", $full),
            'full form lowercase labels' => strtolower($full),
            'full form UPPERCASE labels' => strtoupper($full),
            'full form extra spaces' => "#psb\n  Nama  :   DRAFTE   SANTOSO  \n ODP :  ODP-KLAMPOK-3 \nRT : 01\nRW : 02\nTikor : -7.8,112.7\nDaerah :  Klampok\nRouter : RA\nPaket : 200M\nSN : FHTTC0FFEE01",
            'full form with emoji' => "#psb 🙏\nNama: USERSI AMINAH 😊\nODP: ODP-KLAMPOK-3\nRT: 01\nRW: 02\nTikor: -7.8,112.7\nDaerah: Klampok\nRouter: RA\nPaket: 200M\nSN: FHTTC0FFEE02",
            'full form non-breaking space and zero-width char' => "#psb\nNama:\u{00A0}USERSI AMINAH\u{200B}\nODP:\u{00A0}ODP-KLAMPOK-3\nRT: 01\nRW: 02\nSN: FHTTC0FFEE02",
            'full form in a code fence' => "```\n#psb\nNama: USERSI AMINAH\nODP: 3\nRT: 1\nRW: 2\nSN: FHTTC0FFEE02\n```",
            'full form with tabs' => "#psb\nNama:\tDRAFTE SANTOSO\t\nODP:\tODP-3\nRT:\t01\nRW:\t02",
            'one line format' => '#psb: USERSI AMINAH RT: 02 RW: 03 ODP: 5 SN: FHTTC0FFEE01 Daerah: Klampok Router: RA Paket: 200M',
            'one line format after hash' => '#psb Nama: DRAFTE SANTOSO ODP: 02 RT: 01 RW: 02 SN: FHTTC0FFEE01',
            'soft name on the line after #psb' => "#psb\nDRAFTE SANTOSO\nODP: 3\nRT: 01\nRW: 02\nSN: FHTTC0FFEE01",
            'soft name after colon' => "#psb: USERSI AMINAH\nODP: 3\nRT: 01\nRW: 02",
            'RT/RW on one line' => "#psb\nNama: USERSI AMINAH\nRT/RW: 03/04\nODP: 3",
            'ODP with a decimal label' => "#psb\nNama: USERSI AMINAH\nODP: 6.5\nRT: 1\nRW: 2",
            'ODP with .0' => "#psb\nNama: USERSI AMINAH\nODP: 7.0\nRT: 1\nRW: 2",
            'ODP bare number without colon' => "#psb\nNama: USERSI AMINAH\nodp 02\nRT: 1\nRW: 2",
            'phone label variants' => "#psb\nNama: USERSI AMINAH\nNo HP: 081200000022\nRT: 1\nRW: 2",
            'serial label' => "#psb\nNama: USERSI AMINAH\nSerial Number: fhttc0ffee03\nRT: 1\nRW: 2",
            'SN found without a label' => "#psb\nNama: USERSI AMINAH\nRT: 1\nRW: 2\nONT ZTEGC0FFEE04",
            'VLAN field in the body' => "#psb\nNama: USERSI AMINAH\nVLAN: 102\nRT: 1\nRW: 2",
            // 809 class: empty labels
            '809 empty Nama then ODP' => "#psb\nNama:\nODP: ODP-KLAMPOK-3\nRT: 01\nRW: 02\nTikor: -7.8,112.7\nDaerah: Klampok\nRouter: RA\nPaket: 200M\nSN: FHTTC0FFEE70",
            '809 empty Nama then ODP CRLF' => "#psb\r\nNama:\r\nODP: ODP-KLAMPOK-3\r\nRT: 01\r\nRW: 02\r\nSN: FHTTC0FFEE70",
            'empty RT does not take RW' => "#psb\nNama: USERSI AMINAH\nRT:\nRW: 02\nODP: 3",
            'label without a value on the last line' => "#psb\nNama: USERSI AMINAH\nODP: 3\nRT: 01\nRW: 02\nHP:",
            'label with spaces as the last line' => "#psb\nNama: USERSI AMINAH\nRT: 01\nRW: 02\nTikor:   ",
            'Nama as the last line without value' => "#psb\nODP: 3\nRT: 01\nRW: 02\nNama:",
            'value on the next line' => "#psb\nNama:\nDRAFTE SANTOSO\nODP: 3\nRT: 01\nRW: 02",
            'next line is another label' => "#psb\nNama:\nODP: 3\nRT: 01\nRW: 02",
            'next line is a command' => "#psb\nNama:\n#psb lanjut",
            'next line value with CRLF' => "#psb\r\nNama:\r\nDRAFTE SANTOSO\r\nODP: 3",
            'empty SN then Router' => "#psb\nNama: USERSI AMINAH\nSN:\nRouter: RA\nPaket: 200M",
            'empty Router and Paket' => "#psb\nNama: USERSI AMINAH\nRouter:\nPaket:\nSN: FHTTC0FFEE05",
            // router / paket lines
            'router and paket lines' => "#psb\nNama: USERSI AMINAH\nRouter: krian\nPaket: 100 MBPS\nSN: FHTTC0FFEE06",
            'mikrotik and profile labels' => "#psb\nNama: USERSI AMINAH\nMikrotik: Gadang\nProfile: 50M\nSN: HWTCC0FFEE07",
            'router with spaces' => "#psb\nNama: USERSI AMINAH\nRouter:  R A \nPaket: STARTLITE",
            'package by id' => "#psb\nNama: USERSI AMINAH\nRouter: ra\nPaket: 12",
            'bandwidth label' => "#psb\nNama: USERSI AMINAH\nBandwidth: 200M\nRouter: ra",
            // ktp policy
            'ktp menyusul with reason and date' => "#psb\nNama: USERSI AMINAH\nKTP: menyusul\nAlasan KTP: KTP belum dicetak\nTenggat KTP: 2026-11-30",
            'ktp csr' => "#psb\nNama: SEKOLAH CONTOH\nKTP: CSR\nAlasan: sekolah",
            // commands
            'cmd #psb lanjut' => '#psb lanjut',
            'cmd #psb lanjut with id' => '#psb lanjut #12',
            'cmd /psb lanjut' => '/psb lanjut',
            'cmd #lanjut' => '#lanjut',
            'cmd #psb batal with id' => '#psb batal #12',
            'cmd #psb cancel' => '#psb cancel #12',
            'cmd #batal' => '#batal',
            'cmd /psb batal' => '/psb batal',
            'cmd #psb sn' => '#psb sn FHTTC0FFEE08',
            'cmd #psb sn with id' => '#psb sn FHTTC0FFEE08 #12',
            'cmd /psb sn' => '/psb sn FHTTC0FFEE08',
            'cmd #psb sn without serial' => '#psb sn',
            'cmd SN line right under #psb' => "#psb\nSN: FHTTC0FFEE09\nNama: USERSI AMINAH",
            'cmd #psb ya' => '#psb ya #12',
            'cmd #psb ok' => '#psb ok',
            'cmd #psb pakai sn uncfg' => '#psb pakai sn uncfg #12',
            'cmd /psb ya' => '/psb ya',
            'cmd #psb router' => '#psb router ra',
            'cmd #psb router with id' => '#psb router krian #12',
            'cmd /psb router' => '/psb router rdg',
            'cmd #psb router colon' => '#psb router: bumiayu',
            'cmd #psb vlan' => '#psb vlan 100',
            'cmd #psb vlan with id' => '#psb vlan 100 #12',
            'cmd /psb vlan' => '/psb vlan 4094',
            'cmd #psb kirim lagi' => '#psb kirim lagi #12',
            'cmd #psb kirimlagi' => '#psb kirimlagi #12',
            'cmd #psb resend' => '#psb resend #12',
            'cmd /psb ulang' => '/psb ulang #12',
            'cmd #psb cek' => '#psb cek',
            'cmd #psb cek with id' => '#psb cek #12',
            'cmd /psb cek' => '/psb cek',
            'cmd #status' => '#status',
            'cmd #psb antri' => '#psb antri',
            'cmd /psb antri' => '/psb antri',
            'cmd done' => 'done',
            'cmd selesai' => 'selesai',
            'cmd done with id' => 'done #12',
            'cmd #psb done' => '#psb done #12',
            'cmd /psb done' => '/psb done',
            'cmd vlan ok' => 'vlan ok',
            'cmd mapping ok' => 'mapping ok',
            'cmd sudah' => 'sudah',
            'cmd #psb ktp with id' => '#psb ktp #12',
            'cmd #psb ktp without hash' => '#psb ktp 12',
            'cmd #psb ktp with trailing text' => '#psb ktp #12 tolong',
            'cmd /psb' => '/psb',
            'cmd /psb status' => '/psb status',
            'cmd #psb rekap' => '#psb rekap',
            'cmd #psb hari ini' => '#psb hari ini',
            'cmd #psb bantu' => '#psb bantu',
            'cmd /psb help' => '/psb help',
            'cmd #help' => '#help',
            'cmd /pppoe' => '/pppoe',
            'cmd /pppoe cari' => '/pppoe cari drafte santoso',
            'cmd #pppoe cari phone' => '#pppoe cari 081200000021',
            'cmd #psb alone' => '#psb',
            'cmd #  psb with a space' => '# psb lanjut #5',
            'cmd draft id by word' => '#psb lanjut draft 77',
            // near miss and chatter
            'near miss psb' => 'psb',
            'near miss psb colon' => 'psb:',
            'near miss psb colon with fields' => "psb: Nama: USERSI AMINAH\nRT: 1",
            'near miss psb with a label' => 'psb nama USERSI AMINAH',
            'not near miss: psb mid sentence' => 'kemarin psb sudah selesai',
            'not near miss: psb plain word' => 'psb itu apa',
            'chatter' => 'selamat pagi semua',
            'empty' => '',
            'whitespace only' => "  \n\t ",
            'number only' => '12345',
            'only an emoji' => '👍',
            'hash alone' => '#',
        ];
    }

    public function test_corpus_has_enough_inputs(): void
    {
        $this->assertGreaterThanOrEqual(50, count(self::corpus()));
    }

    public function test_corpus_matches_golden(): void
    {
        $parser = new PsbMessageParser;
        $rows = [];
        foreach (self::corpus() as $label => $input) {
            $parsed = $parser->parse($input);
            ksort($parsed);
            $rows[] = [
                'label' => $label,
                'input' => $input,
                'bot_trigger' => $parser->isBotTrigger($input),
                'near_miss' => $parser->isNearMissPsb($input),
                'contains_psb' => $parser->containsPsb($input),
                'parsed' => $parsed,
            ];
        }
        $recorded = json_encode(['cases' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = __DIR__.'/../Fixtures/psb-flow/parser-corpus.golden.json';

        if (getenv('PSB_FLOW_GOLDEN_WRITE') === '1') {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, 'No parser golden. Record it with PSB_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, 'PsbMessageParser behaviour changed');
    }
}
