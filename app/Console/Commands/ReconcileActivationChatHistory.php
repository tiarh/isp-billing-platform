<?php

namespace App\Console\Commands;

use App\Services\ActivationChatReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ReconcileActivationChatHistory extends Command
{
    protected $signature = 'psb:reconcile-activation-history
                            {--from=2026-07-27 : Tanggal awal WIB}
                            {--to=2026-07-28 : Tanggal akhir WIB}
                            {--max-pages=80 : Batas halaman Evolution per grup}
                            {--format=markdown : markdown atau json}';

    protected $description = 'Audit read-only histori grup aktivasi tanpa reply, replay, atau device write';

    public function handle(ActivationChatReconciliationService $service): int
    {
        try {
            $from = CarbonImmutable::parse((string) $this->option('from'), 'Asia/Jakarta')->startOfDay();
            $to = CarbonImmutable::parse((string) $this->option('to'), 'Asia/Jakarta')->endOfDay();
            if ($to->lessThan($from)) {
                $this->error('--to harus sama atau setelah --from.');

                return self::FAILURE;
            }

            $report = $service->run($from, $to, (int) $this->option('max-pages'));
        } catch (Throwable $e) {
            $this->error('Reconciliation gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        if (strtolower((string) $this->option('format')) === 'json') {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('# Chat Reconciliation Skynet Aktivasi');
        $this->newLine();
        $this->line('- Sumber: Evolution read-only');
        $this->line('- Range WIB: '.$report['range_wib']['from'].' s.d. '.$report['range_wib']['to']);
        $this->line('- Grup: '.$report['group_count'].' allowlist (JID disensor)');
        $this->line('- Record relevan: '.count($report['rows']));
        $this->line('- Truncated: '.($report['truncated'] ? 'ya' : 'tidak'));
        $this->line('- Safety: tidak ada DB write, WA reply, replay job, atau device action');
        $this->newLine();
        $this->line('| Waktu WIB | Ref | Command | Nama | ODP | SN | Klasifikasi | Entity | Aksi |');
        $this->line('|---|---|---|---|---|---|---|---|---|');
        foreach ($report['rows'] as $row) {
            $entity = $row['entity_type']
                ? $row['entity_type'].' #'.$row['entity_id'].' ('.$row['entity_status'].')'
                : '-';
            $columns = [
                $row['occurred_at_wib'] ?: '-',
                $row['message_ref'],
                $row['command'],
                $row['name'] ?: '-',
                $row['odp'] ?: '-',
                $row['sn'] ?: '-',
                $row['classification'],
                $entity,
                $row['operator_action'],
            ];
            $this->line('| '.implode(' | ', array_map(
                fn (mixed $value) => str_replace('|', '/', (string) $value),
                $columns,
            )).' |');
        }

        $this->newLine();
        $this->line('## Ringkasan');
        foreach ($report['summary'] as $classification => $count) {
            $this->line('- '.$classification.': '.$count);
        }

        return self::SUCCESS;
    }
}
