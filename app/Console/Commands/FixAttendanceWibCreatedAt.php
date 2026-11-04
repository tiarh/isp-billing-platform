<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix: attendances.created_at was stored as WIB wall clock instead
 * of UTC (AttendanceController before this fix). Shifts the affected rows -7h.
 *
 * Selection (hour = HOUR(created_at) as stored, ids <= M0):
 *   R: id >= 155, hour in 0..4 and NOT (24600 <= created_at - updated_at <= 25800 s) -> review, NOT shifted
 *   A: id >= 155 and not R                     -> shift
 *   B: 29 <= id <= 154 and hour in 6..11       -> shift
 *   L: everything else                         -> left alone
 *
 * Dry run is the default. Only created_at is touched (query builder, so
 * updated_at stays). Every change is recorded in attendance_created_at_fixes.
 */
class FixAttendanceWibCreatedAt extends Command
{
    protected $signature = 'attendance:fix-wib-created-at
        {--dry-run : Report only (default; wins over --execute)}
        {--execute : Apply the shift (requires --max-id)}
        {--max-id= : M0, highest attendance id to consider (required with --execute)}
        {--rollback : Restore old created_at from the backup table (dry run unless --execute)}';

    protected $description = 'Shift attendances.created_at rows that were stored as WIB back to UTC (-7h)';

    private const FIRST_WIB_ID = 155;

    private const FIRST_MIXED_ID = 29;

    private const SHIFT_SECONDS = 25200;

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('attendance_created_at_fixes')) {
            $this->error('attendance_created_at_fixes table missing: run migrations first.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute') && ! $this->option('dry-run');

        if ($this->option('rollback')) {
            return $this->rollback($execute);
        }

        $maxIdOpt = $this->option('max-id');
        if ($execute && ($maxIdOpt === null || $maxIdOpt === '' || ! ctype_digit((string) $maxIdOpt) || (int) $maxIdOpt < 1)) {
            $this->error('--execute requires --max-id=<M0> (positive integer).');

            return self::FAILURE;
        }

        $m0 = ($maxIdOpt !== null && $maxIdOpt !== '')
            ? (int) $maxIdOpt
            : (int) DB::table('attendances')->max('id');

        $buckets = $this->classify($m0);
        $done = DB::table('attendance_created_at_fixes')->pluck('attendance_id')->flip()->all();

        $this->report($m0, $buckets, count($done));

        if (! $execute) {
            $this->info('DRY RUN: nothing changed.');

            return self::SUCCESS;
        }

        $toShift = [];
        $skipped = 0;
        foreach (['A', 'B'] as $bucket) {
            foreach ($buckets[$bucket] as $row) {
                if (isset($done[$row['id']])) {
                    $skipped++;

                    continue;
                }
                $toShift[] = $row + ['bucket' => $bucket];
            }
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        DB::transaction(function () use ($toShift, $now) {
            foreach ($toShift as $row) {
                $old = $row['created_at'];
                $new = CarbonImmutable::parse($old, 'UTC')->subSeconds(self::SHIFT_SECONDS)->format('Y-m-d H:i:s');

                DB::table('attendance_created_at_fixes')->insert([
                    'attendance_id' => $row['id'],
                    'bucket' => $row['bucket'],
                    'old_created_at' => $old,
                    'new_created_at' => $new,
                    'applied_at' => $now,
                ]);

                $affected = DB::table('attendances')
                    ->where('id', $row['id'])
                    ->where('created_at', $old)
                    ->update(['created_at' => $new]);

                if ($affected !== 1) {
                    throw new \RuntimeException("attendance {$row['id']}: expected 1 updated row, got {$affected}; transaction rolled back");
                }
            }
        });

        $this->info('EXECUTED: shifted '.count($toShift)." rows by -7h; skipped (already in backup): {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * @return array{A: list<array{id:int,created_at:string}>, B: list<array{id:int,created_at:string}>, R: list<array{id:int,created_at:string}>, L: list<array{id:int,created_at:string}>}
     */
    private function classify(int $m0): array
    {
        $buckets = ['A' => [], 'B' => [], 'R' => [], 'L' => []];

        DB::table('attendances')
            ->where('id', '<=', $m0)
            ->orderBy('id')
            ->select(['id', 'created_at', 'updated_at'])
            ->chunkById(500, function ($rows) use (&$buckets) {
                foreach ($rows as $r) {
                    if ($r->created_at === null) {
                        continue;
                    }
                    $created = (string) $r->created_at;
                    $hour = (int) substr($created, 11, 2);
                    $id = (int) $r->id;

                    if ($id >= self::FIRST_WIB_ID) {
                        // created_at - updated_at of ~7h marks a WIB-stored real-time insert
                        // (a 00:00-04:59 WIB check-in is 17:00-21:59 UTC the day before, so hour <= 4 alone is ambiguous).
                        $diff = $r->updated_at === null
                            ? null
                            : strtotime($created.' UTC') - strtotime((string) $r->updated_at.' UTC');
                        $looksWib = $diff !== null && $diff >= 24600 && $diff <= 25800;
                        $bucket = ($hour <= 4 && ! $looksWib) ? 'R' : 'A';
                    } elseif ($id >= self::FIRST_MIXED_ID && $hour >= 6 && $hour <= 11) {
                        $bucket = 'B';
                    } else {
                        $bucket = 'L';
                    }
                    $buckets[$bucket][] = ['id' => $id, 'created_at' => $created];
                }
            });

        return $buckets;
    }

    /**
     * @param  array<string, list<array{id:int,created_at:string}>>  $buckets
     */
    private function report(int $m0, array $buckets, int $alreadyBackedUp): void
    {
        $this->line("M0 (max id used) = {$m0}");
        $labels = [
            'A' => 'A: id>=155, not R -> SHIFT',
            'B' => 'B: 29<=id<=154, hour 6..11 -> SHIFT',
            'R' => 'R: id>=155, hour 0..4 and created-updated NOT 24600..25800s -> REVIEW (not shifted)',
            'L' => 'L: left alone',
        ];
        foreach ($buckets as $key => $rows) {
            if ($rows === []) {
                $this->line("{$labels[$key]}: count=0");

                continue;
            }
            $ids = array_column($rows, 'id');
            $dates = array_column($rows, 'created_at');
            $this->line(sprintf(
                '%s: count=%d id=%d..%d created_at=%s..%s',
                $labels[$key],
                count($rows),
                min($ids),
                max($ids),
                min($dates),
                max($dates)
            ));
        }
        $this->line('Shift total (A+B): '.(count($buckets['A']) + count($buckets['B'])));
        $this->line("Already in backup table (skipped on execute): {$alreadyBackedUp}");

        foreach ($buckets['R'] as $row) {
            $this->warn("review id={$row['id']} created_at={$row['created_at']}");
        }
    }

    private function rollback(bool $execute): int
    {
        $rows = DB::table('attendance_created_at_fixes')
            ->whereNull('restored_at')
            ->orderBy('attendance_id')
            ->get();

        $this->line('Rollback candidates: '.$rows->count());

        if (! $execute) {
            $this->info('DRY RUN: nothing changed (add --execute to restore).');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');

        DB::transaction(function () use ($rows, $now) {
            foreach ($rows as $fix) {
                $affected = DB::table('attendances')
                    ->where('id', $fix->attendance_id)
                    ->where('created_at', $fix->new_created_at)
                    ->update(['created_at' => $fix->old_created_at]);

                if ($affected !== 1) {
                    throw new \RuntimeException("attendance {$fix->attendance_id}: created_at no longer equals the fixed value; rollback aborted");
                }

                DB::table('attendance_created_at_fixes')
                    ->where('id', $fix->id)
                    ->update(['restored_at' => $now]);
            }
        });

        $this->info('ROLLED BACK '.$rows->count().' rows.');

        return self::SUCCESS;
    }
}
