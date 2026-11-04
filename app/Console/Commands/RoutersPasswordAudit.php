<?php

namespace App\Console\Commands;

use App\Services\RouterPppoeCredentialService;
use Illuminate\Console\Command;
use RuntimeException;

class RoutersPasswordAudit extends Command
{
    protected $signature = 'routers:password-audit
                            {router? : Legacy single selector; it is still constrained to the four-target allowlist}
                            {--routers= : Comma-separated allowlisted router selectors}
                            {--dry-run : Explicitly confirm this command remains read-only}
                            {--details : Print customer-level masked status}
                            {--json : Print machine-readable summary}
                            {--output= : Write the masked JSON snapshot to a local path}
                            {--timeout=15 : Reserved for compatibility; router timeout is policy-controlled}';

    protected $description = 'Read-only PPPoE password source-of-truth audit; never writes passwords or devices';

    public function handle(RouterPppoeCredentialService $credentials): int
    {
        $requested = $this->selectors();
        $allowlisted = array_values(array_intersect($requested, RouterPppoeCredentialService::ALLOWLIST));
        $requestedOutOfScope = array_values(array_diff($requested, $allowlisted));

        $report = $credentials->audit($allowlisted);
        $report['requested_out_of_scope'] = array_map(
            fn (string $selector): array => [
                'selector' => $selector,
                'status' => 'OUT_OF_SCOPE_PENDING_ACCESS',
            ],
            $requestedOutOfScope
        );

        $this->writeSnapshot($report);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->info('READ_ONLY: no customer/router writes, no jobs, no WA sends.');
            $this->line('Run ID: '.$report['run_id']);
            $this->table(
                ['Selector', 'Router ID', 'Router', 'Status', 'Expected', 'Actual', 'PPPoE', 'Orphan', 'Match', 'Fill', 'Update', 'Conflict', 'Not found'],
                collect($report['targets'])->map(fn (array $target): array => [
                    $target['selector'] ?? '-',
                    $target['router_id'] ?? '-',
                    $target['router_name'] ?? '-',
                    $target['status'] ?? '-',
                    data_get($target, 'secret_count.expected', '-'),
                    data_get($target, 'secret_count.actual', '-'),
                    data_get($target, 'pppoe_secret_count', 0),
                    data_get($target, 'orphan_count', 0),
                    data_get($target, 'counts.match', 0),
                    data_get($target, 'counts.fill_database', 0),
                    data_get($target, 'counts.update_database', 0),
                    data_get($target, 'counts.conflict', 0),
                    data_get($target, 'counts.not_found', 0),
                ])->all()
            );

            if ((bool) $this->option('details')) {
                foreach ($report['targets'] as $target) {
                    if (($target['rows'] ?? []) === []) {
                        continue;
                    }
                    $this->newLine();
                    $this->line('--- '.($target['router_name'] ?? $target['selector']).' ---');
                    $this->table(
                        ['Customer ID', 'Name', 'PPPoE', 'DB state', 'Router state', 'Category', 'Reason'],
                        $target['rows']
                    );
                }
            }
        }

        $blocked = collect($report['targets'])->contains(function (array $target): bool {
            return ($target['status'] ?? '') !== 'READY_FOR_APPROVAL';
        });

        return $blocked ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<string> */
    private function selectors(): array
    {
        $option = trim((string) $this->option('routers'));
        $argument = trim((string) $this->argument('router'));
        $raw = $option !== '' ? $option : ($argument !== '' ? $argument : implode(',', RouterPppoeCredentialService::ALLOWLIST));

        return collect(explode(',', $raw))
            ->map(fn (string $selector): string => mb_strtolower(trim($selector)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $report */
    private function writeSnapshot(array $report): void
    {
        $path = trim((string) $this->option('output'));
        if ($path === '') {
            return;
        }

        $json = $this->encode($report).PHP_EOL;
        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException('Tidak dapat menulis masked snapshot ke path output yang diberikan.');
        }
        $this->info('Masked snapshot written: '.$path);
    }

    /** @param array<string, mixed> $report */
    private function encode(array $report): string
    {
        return json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }
}
