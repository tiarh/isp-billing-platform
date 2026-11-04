<?php

namespace App\Console\Commands;

use App\Services\Cpe\GenieAcsRca3Config as Rca3Config;
use Illuminate\Console\Command;

/**
 * RCA-3: the GenieACS config change (provision + preset + VP patches) shipped in deploy/genieacs/rca3/.
 *
 *   genieacs:rca3-config                     read-only: GET the live objects, print the diff + the files hash (default)
 *   genieacs:rca3-config --check             same as above
 *   genieacs:rca3-config --apply --confirm --expect-hash=<Hash rencana of --check>
 *                                            write a backup to storage/app/genieacs-backup/<ts>/, then PUT the changes
 *   genieacs:rca3-config --rollback=<ts> --confirm   restore a backup (objects that did not exist are deleted)
 *
 * Needs the owner's OK to --apply. Never scheduled. Apply / rollback without --confirm refuse and send nothing.
 */
class GenieAcsRca3Config extends Command
{
    protected $signature = 'genieacs:rca3-config
                            {--check : Read-only diff against the live GenieACS (default)}
                            {--apply : Apply the change (needs --confirm)}
                            {--rollback= : Restore the backup <YYYYMMDD-HHMMSS> (needs --confirm)}
                            {--confirm : Really write to GenieACS}
                            {--expect-hash= : "Hash rencana" printed by --check; apply refuses if the plan changed since}';

    protected $description = 'RCA-3: cek / terapkan / batalkan perubahan konfigurasi GenieACS (provision, preset, VP)';

    public function handle(Rca3Config $config): int
    {
        $apply = (bool) $this->option('apply');
        $rollback = (string) ($this->option('rollback') ?? '');
        $confirm = (bool) $this->option('confirm');

        if ($apply && $rollback !== '') {
            $this->error('Pilih salah satu: --apply atau --rollback.');

            return self::FAILURE;
        }

        try {
            if ($rollback !== '') {
                if (! $confirm) {
                    $this->error('Rollback ditolak: tambahkan --confirm. Tidak ada yang dikirim.');

                    return self::FAILURE;
                }
                foreach ($config->rollback($rollback) as $line) {
                    $this->line($line);
                }
                $this->info('Rollback selesai.');

                return self::SUCCESS;
            }

            $plan = $config->plan(); // GET only
            $this->line('Hash file repo: '.$config->hash());
            $this->line('Hash rencana: '.$config->planHash($plan));
            $default = $config->liveDefaultPreset();
            $this->line('Preset default (live, untuk dibandingkan): channel='.json_encode($default['channel'] ?? null)
                .' precondition='.json_encode($default['precondition'] ?? null).' events='.json_encode($default['events'] ?? null));
            $writes = 0;
            foreach ($plan as $item) {
                $this->line(sprintf('[%s] %s %s%s', strtoupper($item['action']), $item['kind'], $item['id'], $item['note'] ? ' - '.$item['note'] : ''));
                if ($item['diff'] !== '') {
                    $this->line($item['diff']);
                }
                if (in_array($item['action'], ['create', 'update'], true)) {
                    $writes++;
                }
            }

            if (! $apply) {
                $this->info("Cek selesai (hanya baca): {$writes} perubahan menunggu. Terapkan dengan --apply --confirm setelah OK pemilik.");

                return self::SUCCESS;
            }
            if (! $confirm) {
                $this->error('Apply ditolak: tambahkan --confirm. Tidak ada yang dikirim.');

                return self::FAILURE;
            }
            if ((string) $this->option('expect-hash') === '') {
                $this->error('Apply ditolak: tambahkan --expect-hash=<Hash rencana dari --check>. Tidak ada yang dikirim.');

                return self::FAILURE;
            }
            if ($writes === 0) {
                $this->info('Tidak ada perubahan.');

                return self::SUCCESS;
            }
            $result = $config->apply(null, (string) $this->option('expect-hash'));
            $this->info('Backup: '.$result['backup'].' (salin backup.json ke tempat lain juga)');
            $this->info('Diterapkan: '.implode(', ', $result['applied']));
            if ($result['blocked'] !== []) {
                $this->warn(count($result['blocked']).' VP tidak diubah: '.implode(', ', $result['blocked']).'. Data RX/suhu dari VP ini tetap data lama.');
            }
            $this->line('Batalkan dengan: php artisan genieacs:rca3-config --rollback='.basename($result['backup']).' --confirm');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
