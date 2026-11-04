<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class FixAttendanceWibCreatedAtCommandTest extends TestCase
{
    use RefreshDatabase;

    private const UPDATED = '2026-10-13 05:00:00';

    /** id => created_at (as stored) */
    private const SEED = [
        10 => '2026-08-01 01:00:00',   // L: id < 29
        30 => '2026-08-02 02:00:00',   // L: 29..154 but hour 2
        31 => '2026-08-02 08:00:00',   // B
        100 => '2026-08-03 12:00:00',  // L: hour 12
        154 => '2026-08-04 11:30:00',  // B
        155 => '2026-08-05 01:00:00',  // R
        156 => '2026-08-05 09:00:00',  // A
        200 => '2026-08-06 22:00:00',  // A
        201 => '2026-08-06 04:59:59',  // R
        202 => '2026-08-07 05:00:00',  // A (hour 5)
        210 => '2026-08-09 03:30:00',  // A: hour 3 but created-updated = 25200s (WIB real-time insert)
        211 => '2026-08-09 01:00:00',  // R: hour 1, created == updated (UTC-stored)
        300 => '2026-08-08 09:00:00',  // beyond M0=250
    ];

    /** id => updated_at override */
    private const UPDATED_OVERRIDE = [
        210 => '2026-08-08 20:30:00',
        211 => '2026-08-09 01:00:00',
    ];

    private function seedRows(): void
    {
        foreach (self::SEED as $id => $createdAt) {
            DB::table('attendances')->insert([
                'id' => $id,
                'sender_name' => "T{$id}",
                'message_id' => "M{$id}",
                'created_at' => $createdAt,
                'updated_at' => self::UPDATED_OVERRIDE[$id] ?? self::UPDATED,
            ]);
        }
    }

    private function created(int $id): string
    {
        return (string) DB::table('attendances')->where('id', $id)->value('created_at');
    }

    public function test_dry_run_is_default_and_reports_buckets(): void
    {
        $this->seedRows();

        $this->assertSame(0, Artisan::call('attendance:fix-wib-created-at', ['--max-id' => 250]));
        $out = Artisan::output();

        $this->assertStringContainsString('M0 (max id used) = 250', $out);
        $this->assertStringContainsString('SHIFT: count=4 id=156..210 created_at=2026-08-05 09:00:00..2026-08-09 03:30:00', $out);
        $this->assertStringContainsString('SHIFT: count=2 id=31..154', $out);
        $this->assertStringContainsString('REVIEW (not shifted): count=3 id=155..211', $out);
        $this->assertStringContainsString('review id=211', $out);
        $this->assertStringNotContainsString('review id=210', $out);
        $this->assertStringContainsString('left alone: count=3 id=10..100', $out);
        $this->assertStringContainsString('review id=155', $out);
        $this->assertStringContainsString('DRY RUN', $out);

        foreach (self::SEED as $id => $createdAt) {
            $this->assertSame($createdAt, $this->created($id));
        }
        $this->assertSame(0, DB::table('attendance_created_at_fixes')->count());
    }

    public function test_dry_run_wins_over_execute(): void
    {
        $this->seedRows();

        Artisan::call('attendance:fix-wib-created-at', ['--execute' => true, '--dry-run' => true, '--max-id' => 250]);

        $this->assertSame(self::SEED[156], $this->created(156));
        $this->assertSame(0, DB::table('attendance_created_at_fixes')->count());
    }

    public function test_execute_requires_max_id(): void
    {
        $this->seedRows();

        $this->assertSame(1, Artisan::call('attendance:fix-wib-created-at', ['--execute' => true]));

        $this->assertSame(self::SEED[156], $this->created(156));
        $this->assertSame(0, DB::table('attendance_created_at_fixes')->count());
    }

    public function test_execute_shifts_only_a_and_b_keeps_updated_at_and_is_idempotent(): void
    {
        $this->seedRows();

        $this->assertSame(0, Artisan::call('attendance:fix-wib-created-at', ['--execute' => true, '--max-id' => 250]));

        $this->assertSame('2026-08-02 01:00:00', $this->created(31));
        $this->assertSame('2026-08-04 04:30:00', $this->created(154));
        $this->assertSame('2026-08-05 02:00:00', $this->created(156));
        $this->assertSame('2026-08-06 15:00:00', $this->created(200));

        // Untouched: L, R and rows beyond M0.
        foreach ([10, 30, 100, 155, 201, 211, 300] as $id) {
            $this->assertSame(self::SEED[$id], $this->created($id), "id {$id} must not move");
        }
        // Hour-5 row: 05:00 - 7h crosses to the previous day.
        $this->assertSame('2026-08-06 22:00:00', $this->created(202));
        // Hour-3 row with a 7h diff is a WIB-stored insert: shifted.
        $this->assertSame('2026-08-08 20:30:00', $this->created(210));

        // updated_at untouched everywhere.
        $this->assertSame(0, DB::table('attendances')->whereNotIn('id', array_keys(self::UPDATED_OVERRIDE))->where('updated_at', '!=', self::UPDATED)->count());
        $this->assertSame('2026-08-08 20:30:00', (string) DB::table('attendances')->where('id', 210)->value('updated_at'));

        $this->assertSame(6, DB::table('attendance_created_at_fixes')->count());
        $fix = DB::table('attendance_created_at_fixes')->where('attendance_id', 156)->first();
        $this->assertSame('A', $fix->bucket);
        $this->assertSame('2026-08-05 09:00:00', $fix->old_created_at);
        $this->assertSame('2026-08-05 02:00:00', $fix->new_created_at);

        // Second run changes nothing.
        Artisan::call('attendance:fix-wib-created-at', ['--execute' => true, '--max-id' => 250]);
        $this->assertSame('2026-08-05 02:00:00', $this->created(156));
        $this->assertSame(6, DB::table('attendance_created_at_fixes')->count());
    }

    public function test_rollback_restores_old_values(): void
    {
        $this->seedRows();
        Artisan::call('attendance:fix-wib-created-at', ['--execute' => true, '--max-id' => 250]);

        // Rollback is a dry run unless --execute.
        Artisan::call('attendance:fix-wib-created-at', ['--rollback' => true]);
        $this->assertSame('2026-08-05 02:00:00', $this->created(156));

        $this->assertSame(0, Artisan::call('attendance:fix-wib-created-at', ['--rollback' => true, '--execute' => true]));

        foreach (self::SEED as $id => $createdAt) {
            $this->assertSame($createdAt, $this->created($id));
        }
        $this->assertSame(0, DB::table('attendances')->whereNotIn('id', array_keys(self::UPDATED_OVERRIDE))->where('updated_at', '!=', self::UPDATED)->count());
    }

    public function test_execute_skips_ids_already_in_backup_table(): void
    {
        $this->seedRows();
        // A backup row exists for 31 while the data never moved: skipped, not duplicated.
        DB::table('attendance_created_at_fixes')->insert([
            'attendance_id' => 31,
            'bucket' => 'B',
            'old_created_at' => '2026-08-02 08:00:00',
            'new_created_at' => '2026-08-02 01:00:00',
            'applied_at' => '2026-10-13 05:00:00',
        ]);

        Artisan::call('attendance:fix-wib-created-at', ['--execute' => true, '--max-id' => 250]);

        $this->assertSame(self::SEED[31], $this->created(31));
        $this->assertSame(5, DB::table('attendance_created_at_fixes')->where('attendance_id', '!=', 31)->count());
    }
}
