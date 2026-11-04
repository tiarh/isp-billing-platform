<?php

namespace Tests\Unit;

use App\Services\TechnicianProgressParser;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class TechnicianProgressParserTest extends TestCase
{
    public function test_parses_legacy_technician_report_format(): void
    {
        $text = <<<'TXT'
Progres 23 Juli 2026
Jumlah tim : 1 tim(2 orang)

SKYNET :
- Juli
- Haki

Progress :
- maintance

Site :
- Suko an Yusak

ACTION :
- on site
- Ganti router FH
- Setting ke frekuensi 2.4

Status foto :
- Router
- sn
- SpeedTest

Status :
- close

Kendala :
- Kadang lemot
- Sinyal tidak sampai ke teras rumah

Material :
-

Note :

Terimakasih
TXT;

        $parsed = (new TechnicianProgressParser)->parse($text, Carbon::parse('2026-07-23', 'Asia/Jakarta'));

        $this->assertSame('progress', $parsed['kind']);
        $this->assertSame('2026-07-23', $parsed['work_date']);
        $this->assertSame(['Juli', 'Haki'], $parsed['technicians']);
        $this->assertSame(['maintance'], $parsed['progress_items']);
        $this->assertSame(['Suko an Yusak'], $parsed['sites']);
        $this->assertSame(['on site', 'Ganti router FH', 'Setting ke frekuensi 2.4'], $parsed['actions']);
        $this->assertSame(['Router', 'sn', 'SpeedTest'], $parsed['photo_status']);
        $this->assertSame('close', $parsed['status']);
        $this->assertSame(['Kadang lemot', 'Sinyal tidak sampai ke teras rumah'], $parsed['constraints']);
        $this->assertSame('Terimakasih', $parsed['note']);
        $this->assertSame(1, $parsed['meta']['team_count']);
        $this->assertSame(2, $parsed['meta']['people_count']);
    }

    public function test_parses_team_progress_report_format(): void
    {
        $text = <<<'TXT'
Team 1
WIDI & FIKRI
Site : SUKO
Task : PSB SUKO target 20
Progress : selesai 6 PSB, 2 proses
Status : proses
TXT;

        $parsed = (new TechnicianProgressParser)->parse($text, Carbon::parse('2026-07-23', 'Asia/Jakarta'));

        $this->assertSame('progress', $parsed['kind']);
        $this->assertSame(1, $parsed['team_no']);
        $this->assertSame(['SUKO'], $parsed['sites']);
        $this->assertSame(['PSB SUKO target 20'], $parsed['actions']);
        $this->assertSame(['selesai 6 PSB, 2 proses'], $parsed['progress_items']);
    }

    public function test_parses_manual_task_command(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#task maintenance site:SUKO pelanggan:YUSAK kendala:lemot team:JULI,HAKI',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );

        $this->assertSame('task', $parsed['kind']);
        $this->assertSame('create_manual_task', $parsed['action']);
        $this->assertSame('maintenance', $parsed['task_type']);
        $this->assertSame('SUKO', $parsed['site']);
        $this->assertSame(['JULI', 'HAKI'], $parsed['technicians']);
        $this->assertSame('Maintenance YUSAK', $parsed['task']);
    }

    public function test_parses_area_only_psb_task_as_standby(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#task psb site:GONDANG target:20',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );

        $this->assertSame('task', $parsed['kind']);
        $this->assertSame('psb', $parsed['task_type']);
        $this->assertSame('GONDANG', $parsed['site']);
        $this->assertSame('STANDBY PSB GONDANG TARGET 20/HARI', $parsed['task']);
    }

    public function test_parses_simple_operator_inputs(): void
    {
        $parser = new TechnicianProgressParser;

        $psb = $parser->parse('besok psb suko 20', Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta'));
        $deploy = $parser->parse('deploy kalirejo odp 0/8', Carbon::parse('2026-07-23', 'Asia/Jakarta'));
        $maintenance = $parser->parse('mtc djoko comboran los', Carbon::parse('2026-07-23', 'Asia/Jakarta'));
        $pelurusan = $parser->parse('pelurusan odc capang', Carbon::parse('2026-07-23', 'Asia/Jakarta'));
        $terminasi = $parser->parse('terminasi kalirejo odp 0/8', Carbon::parse('2026-07-23', 'Asia/Jakarta'));
        $send = $parser->parse('krim', Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta'));
        $fix = $parser->parse('fix', Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta'));

        $this->assertTrue($parser->isTrigger('psb suko 20'));
        $this->assertTrue($parser->isTrigger('pelurusan odc capang'));
        $this->assertSame('psb', $psb['task_type']);
        $this->assertSame('SUKO', $psb['site']);
        $this->assertSame('2026-07-24', $psb['work_date']);
        $this->assertSame('STANDBY PSB SUKO TARGET 20/HARI', $psb['task']);
        $this->assertSame('pembangunan', $deploy['task_type']);
        $this->assertSame('KALIREJO', $deploy['site']);
        $this->assertSame('DEPLOY ODP 0/8', $deploy['task']);
        $this->assertSame('maintenance', $maintenance['task_type']);
        $this->assertSame('COMBORAN', $maintenance['site']);
        $this->assertSame('MAINTENANCE DJOKO LOS', $maintenance['task']);
        $this->assertSame('pembangunan', $pelurusan['task_type']);
        $this->assertSame('CAPANG', $pelurusan['site']);
        $this->assertSame('PELURUSAN ODC', $pelurusan['task']);
        $this->assertSame('pembangunan', $terminasi['task_type']);
        $this->assertSame('KALIREJO', $terminasi['site']);
        $this->assertSame('TERMINASI ODP 0/8', $terminasi['task']);
        $this->assertSame('send_schedule', $send['action']);
        $this->assertSame('2026-07-23', $send['work_date']);
        $this->assertSame('approve_schedule', $fix['action']);
        $this->assertSame('2026-07-24', $fix['work_date']);
    }

    public function test_parses_multi_line_simple_tasks_as_batch(): void
    {
        $text = <<<'TXT'
besok psb suko 20
besok deploy odp kalirejo rw 15 0/4
besok deploy suko ODP 0/8
besok pelurusan odc capang
besok repair odp gadang
TXT;

        $parsed = (new TechnicianProgressParser)->parse($text, Carbon::parse('2026-07-23 12:56', 'Asia/Jakarta'));

        $this->assertSame('batch', $parsed['kind']);
        $this->assertSame('batch_input', $parsed['action']);
        $this->assertSame('2026-07-24', $parsed['work_date']);
        $this->assertCount(5, $parsed['items']);
        $this->assertSame('psb', $parsed['items'][0]['task_type']);
        $this->assertSame('PELURUSAN ODC', $parsed['items'][3]['task']);
        $this->assertSame('REPAIR ODP', $parsed['items'][4]['task']);
    }

    public function test_parses_simple_cancel_input_for_next_day(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            'hapus mtc djoko comboran',
            Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta')
        );

        $this->assertSame('task_cancel', $parsed['kind']);
        $this->assertSame('cancel_manual_task', $parsed['action']);
        $this->assertSame('2026-07-24', $parsed['work_date']);
        $this->assertSame('maintenance', $parsed['task_type']);
        $this->assertSame('COMBORAN', $parsed['site']);
        $this->assertContains('DJOKO', $parsed['cancel_query']);
    }

    public function test_manual_task_command_at_night_defaults_to_next_day(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#task pembangunan site:SUKO task:DEPLOY ODP 0/8',
            Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta')
        );

        $this->assertSame('task', $parsed['kind']);
        $this->assertSame('2026-07-24', $parsed['work_date']);
        $this->assertSame('pembangunan', $parsed['task_type']);
    }

    public function test_team_command_sets_roster_for_next_day(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#team 5 UPIN & FAHRI site:SUKO',
            Carbon::parse('2026-07-23 19:30', 'Asia/Jakarta')
        );

        $this->assertSame('team_roster', $parsed['kind']);
        $this->assertSame('set_team_roster', $parsed['action']);
        $this->assertSame('2026-07-24', $parsed['work_date']);
        $this->assertSame(5, $parsed['team_no']);
        $this->assertSame(['UPIN', 'FAHRI'], $parsed['technicians']);
    }

    public function test_parses_human_team_and_task_inputs(): void
    {
        $parser = new TechnicianProgressParser;
        $fallback = Carbon::parse('2026-07-23 10:00', 'Asia/Jakarta');

        $team = $parser->parse('team 5 widi dimas aja', $fallback);
        $teamWord = $parser->parse('team 3 misa 1 orang aja jadi robi tim tiga', $fallback);
        $add = $parser->parse('team 5 nambah baim', $fallback);
        $cancel = $parser->parse('task 3 pelurusan hapus', $fallback);
        $task = $parser->parse('tambah task deploy odp klampok team ridwan aja', $fallback);

        $this->assertSame('team_roster', $team['kind']);
        $this->assertSame(['WIDI', 'DIMAS'], $team['technicians']);
        $this->assertSame(3, $teamWord['team_no']);
        $this->assertSame(['ROBI'], $teamWord['technicians']);
        $this->assertSame('add_team_member', $add['action']);
        $this->assertSame(['BAIM'], $add['technicians']);
        $this->assertSame('task_cancel', $cancel['kind']);
        $this->assertSame(3, $cancel['team_no']);
        $this->assertContains('PELURUSAN', $cancel['cancel_query']);
        $this->assertSame('task', $task['kind']);
        $this->assertSame('KLAMPOK', $task['site']);
        $this->assertSame(['RIDWAN'], $task['technicians']);
    }

    public function test_parses_language_memory_command(): void
    {
        $parser = new TechnicianProgressParser;
        $parsed = $parser->parse(
            'tambah memory: pemvafuab = pembangunan',
            Carbon::parse('2026-07-23 10:00', 'Asia/Jakarta')
        );

        $this->assertTrue($parser->isTrigger('tambah memory: pemvafuab = pembangunan'));
        $this->assertSame('language_memory', $parsed['kind']);
        $this->assertSame('add_language_memory', $parsed['action']);
        $this->assertSame('pemvafuab', $parsed['alias']);
        $this->assertSame('pembangunan', $parsed['meaning']);
    }

    public function test_parses_dismantle_lawang_as_manual_task(): void
    {
        $parser = new TechnicianProgressParser;
        $parsed = $parser->parse(
            'dismantle lawang task',
            Carbon::parse('2026-07-23 10:00', 'Asia/Jakarta')
        );

        $this->assertTrue($parser->isTrigger('dismantle lawang task'));
        $this->assertSame('task', $parsed['kind']);
        $this->assertSame('maintenance', $parsed['task_type']);
        $this->assertSame('LAWANG', $parsed['site']);
        $this->assertSame('DISMANTLE LAWANG', $parsed['task']);

        $human = $parser->parse(
            'nambah task baru dismantle oemutusan lawang team jazuli fatkah',
            Carbon::parse('2026-07-23 10:00', 'Asia/Jakarta')
        );

        $this->assertTrue($parser->isTrigger('nambah task baru dismantle oemutusan lawang team jazuli fatkah'));
        $this->assertSame('task', $human['kind']);
        $this->assertSame('maintenance', $human['task_type']);
        $this->assertSame('LAWANG', $human['site']);
        $this->assertSame('DISMANTLE LAWANG', $human['task']);
        $this->assertSame(['JAZULI', 'FATKAH'], $human['technicians']);
    }

    public function test_parses_short_progress_command(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#progress team 4 deploy odp 0/8 sudah 4/8 kendala -',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );

        $this->assertSame('progress', $parsed['kind']);
        $this->assertSame(4, $parsed['team_no']);
        $this->assertSame(['deploy odp 0/8 sudah 4/8'], $parsed['progress_items']);
        $this->assertSame([], $parsed['constraints']);
        $this->assertSame('proses', $parsed['status']);
    }

    public function test_parses_revision_command_categories(): void
    {
        $parser = new TechnicianProgressParser;

        $team = $parser->parse(
            '#revisi team team 2 ganti jadi WIDI & FIKRI site:SUKO',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );
        $fitur = $parser->parse(
            '#revisi fitur tambah tombol kirim ulang progress',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );

        $this->assertTrue($parser->isTrigger('# revisi task pindah ke team 4'));
        $this->assertSame('revision', $team['kind']);
        $this->assertSame('team', $team['revision_type']);
        $this->assertSame(2, $team['team_no']);
        $this->assertSame(['SUKO'], $team['sites']);
        $this->assertSame('fitur', $fitur['revision_type']);
        $this->assertSame('tambah tombol kirim ulang progress', $fitur['revision_text']);
    }

    public function test_accepts_krim_typo_for_send_report_command(): void
    {
        $parsed = (new TechnicianProgressParser)->parse(
            '#krim report',
            Carbon::parse('2026-07-23', 'Asia/Jakarta')
        );

        $this->assertSame('control', $parsed['kind']);
        $this->assertSame('#kirim', $parsed['command']);
        $this->assertSame('send_schedule', $parsed['action']);
    }
}
