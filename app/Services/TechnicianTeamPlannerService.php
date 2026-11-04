<?php

namespace App\Services;

use App\Models\TechnicianTeam;
use Illuminate\Support\Str;

class TechnicianTeamPlannerService
{
    /**
     * @var array<int, list<string>>
     */
    private array $defaultTeams = [
        1 => ['WIDI', 'FIKRI'],
        2 => ['REHAN', 'VALNO'],
        3 => ['ROBI', 'DIMAS'],
        4 => ['RAFLY', 'FATKAH'],
        5 => ['TYO', 'UPIN'],
        6 => ['DAPPA', 'ROY', 'FAHRI'],
        7 => ['JAZULI', 'BAIM', 'IPIN'],
    ];

    protected function resolveTeams(?string $workDate = null): array
    {
        $workDate ??= now('Asia/Jakarta')->toDateString();

        try {
            $dbTeams = TechnicianTeam::with('members')
                ->whereDate('work_date', $workDate)
                ->get()
                ->mapWithKeys(fn ($t) => [(int) $t->team_no => $t->members->pluck('name')->map(fn ($n) => Str::upper((string) $n))->filter()->all()])
                ->filter(fn ($m) => $m !== [])
                ->all();

            return $dbTeams !== [] ? array_replace($this->defaultTeams, $dbTeams) : $this->defaultTeams;
        } catch (\Throwable $e) {
            return $this->defaultTeams;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    public function plan(array $jobs, ?string $workDate = null): array
    {
        $activeTeams = $this->resolveTeams($workDate);
        $buckets = [];

        foreach ($jobs as $job) {
            $task = $this->taskText($job);
            if ($task === '') {
                continue;
            }

            $site = $this->normalizeSite((string) ($job['site'] ?? ''));
            $type = $this->normalizeTaskType((string) ($job['task_type'] ?? $job['type'] ?? $task));
            $explicitTechnicians = $this->cleanTechnicians($job['technicians'] ?? []);
            $explicitTeamNo = ! empty($job['team_no']) ? (int) $job['team_no'] : null;
            $teamChoices = $explicitTeamNo
                ? [$explicitTeamNo]
                : ($explicitTechnicians !== [] ? [null] : $this->teamChoices($site, $type, $task, $job, $buckets));

            foreach ($teamChoices as $teamNo) {
                $teamKey = $teamNo ?: 'custom:'.md5(implode('|', $explicitTechnicians));
                $key = $teamKey.'|'.$site.'|'.$type;

                if (! isset($buckets[$key])) {
                    $technicians = $explicitTechnicians ?: ($activeTeams[$teamNo] ?? []);
                    $buckets[$key] = [
                        'team_no' => $teamNo,
                        'team_label' => $teamNo ? 'Team '.$teamNo : 'Team '.implode(' & ', $technicians),
                        'technicians' => $technicians,
                        'site' => $site,
                        'task_type' => $type,
                        'tasks' => [],
                        'progress' => $this->defaultProgress($type),
                        'priority' => 'normal',
                        'sources' => [],
                        'jobs' => [],
                    ];
                }

                $buckets[$key]['tasks'][] = $task;
                $buckets[$key]['priority'] = $this->maxPriority(
                    (string) $buckets[$key]['priority'],
                    (string) ($job['priority'] ?? 'normal')
                );
                $buckets[$key]['sources'][] = (string) ($job['source'] ?? 'local');
                $buckets[$key]['jobs'][] = $job;
            }
        }

        $planned = array_map(function (array $bucket): array {
            $tasks = array_values(array_unique(array_filter(array_map('strval', $bucket['tasks']))));

            return [
                'team_no' => $bucket['team_no'] === null ? null : (int) $bucket['team_no'],
                'team_label' => (string) $bucket['team_label'],
                'technicians' => $bucket['technicians'],
                'site' => (string) $bucket['site'],
                'task_type' => (string) $bucket['task_type'],
                'task' => $this->summarizeTasks($bucket['task_type'], $tasks),
                'progress' => (string) $bucket['progress'],
                'priority' => (string) $bucket['priority'],
                'source' => 'auto_planner',
                'source_ref' => $this->sourceRef($bucket),
                'meta' => [
                    'sources' => array_values(array_unique($bucket['sources'])),
                    'jobs' => $bucket['jobs'],
                ],
            ];
        }, array_values($buckets));

        usort($planned, fn (array $a, array $b) => [($a['team_no'] ?? 99), $a['site']] <=> [($b['team_no'] ?? 99), $b['site']]);

        return $planned;
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function taskText(array $job): string
    {
        $customer = trim((string) ($job['customer_name'] ?? $job['name'] ?? ''));
        $task = trim((string) ($job['task'] ?? $job['subject'] ?? $job['description'] ?? ''));
        if ($customer !== '' && ! str_contains(Str::lower($task), Str::lower($customer))) {
            $task = trim($customer.' - '.$task, " -\t\n\r\0\x0B");
        }

        return Str::upper(Str::limit($task, 90, ''));
    }

    /**
     * @return list<string>
     */
    private function cleanTechnicians(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($name) => Str::upper(trim((string) $name)),
            $value
        ))));
    }

    private function normalizeSite(string $site): string
    {
        $site = Str::upper(trim($site));
        $site = preg_replace('/\s+/u', ' ', $site) ?? $site;

        return $site !== '' && $site !== '-' ? $site : 'OPEN TICKET AREA';
    }

    private function normalizeTaskType(string $value): string
    {
        $value = Str::lower($value);

        if (Str::contains($value, ['psb', 'pasang', 'aktivasi'])) {
            return 'psb';
        }
        if (Str::contains($value, ['deploy', 'odp', 'odc', 'pembangunan', 'backbone', 'pelurusan', 'terminasi', 'splicing', 'splice', 'repair'])) {
            return 'pembangunan';
        }
        if (Str::contains($value, ['standby', 'stanby', 'csr', 'survey'])) {
            return 'standby';
        }

        return 'maintenance';
    }

    /**
     * @param  array<string, mixed>  $buckets
     * @param  array<string, mixed>  $job
     * @return list<int>
     */
    private function teamChoices(string $site, string $type, string $task, array $job, array $buckets): array
    {
        if ($this->needsPsbAreaPool($site, $type, $task, $job)) {
            return [1, 2, 3];
        }

        return [$this->chooseTeam($site, $type, $task, $buckets)];
    }

    /**
     * @param  array<string, mixed>  $job
     */
    private function needsPsbAreaPool(string $site, string $type, string $task, array $job): bool
    {
        if ($type !== 'psb' || ! Str::contains($site, ['SUKO', 'GONDANG'])) {
            return false;
        }

        $target = (int) preg_replace('/\D+/', '', (string) ($job['target'] ?? ''));

        return Str::contains($task, 'STANDBY PSB') || $target >= 15 || preg_match('/TARGET\s+(\d+)/u', $task) === 1;
    }

    /**
     * @param  array<string, mixed>  $buckets
     */
    private function chooseTeam(string $site, string $type, string $task, array $buckets): int
    {
        if ($type === 'pembangunan') {
            return $this->chooseBuildTeam($site, $buckets);
        }
        if (Str::contains($site, 'CAPANG') || Str::contains($task, 'CSR')) {
            return 6;
        }
        if ($type === 'maintenance') {
            return 7;
        }
        if (Str::contains($site, 'KALIREJO')) {
            return 4;
        }
        if (Str::contains($site, 'SUKO')) {
            return $this->leastLoadedTeam([1, 2, 3], $buckets);
        }

        return $type === 'psb' ? $this->leastLoadedTeam([1, 2, 3, 6], $buckets) : 7;
    }

    /**
     * @param  array<string, mixed>  $buckets
     */
    private function chooseBuildTeam(string $site, array $buckets): int
    {
        if (Str::contains($site, 'KALIREJO')) {
            return 4;
        }
        if (Str::contains($site, 'SUKO')) {
            return 5;
        }
        if (Str::contains($site, 'CAPANG')) {
            return 6;
        }

        return $this->leastLoadedTeam([7, 5, 6, 4], $buckets);
    }

    /**
     * @param  list<int>  $teams
     * @param  array<string, mixed>  $buckets
     */
    private function leastLoadedTeam(array $teams, array $buckets): int
    {
        $loads = array_fill_keys($teams, 0);
        foreach ($buckets as $bucket) {
            $teamNo = (int) ($bucket['team_no'] ?? 0);
            if (array_key_exists($teamNo, $loads)) {
                $loads[$teamNo] += count($bucket['tasks'] ?? []);
            }
        }

        asort($loads);

        return (int) array_key_first($loads);
    }

    private function defaultProgress(string $type): string
    {
        return match ($type) {
            'psb' => 'PSB',
            'pembangunan' => 'DEPLOY',
            'standby' => 'STANDBY',
            default => 'OPEN TICKET',
        };
    }

    /**
     * @param  list<string>  $tasks
     */
    private function summarizeTasks(string $type, array $tasks): string
    {
        $prefix = match ($type) {
            'psb' => 'PSB',
            'pembangunan' => 'DEPLOY',
            'standby' => 'STANDBY',
            default => 'MAINTENANCE',
        };

        if (count($tasks) === 1) {
            return $this->hasWorkPrefix($type, $tasks[0]) ? $tasks[0] : $prefix.' - '.$tasks[0];
        }

        return $prefix.' '.count($tasks).' JOB: '.implode('; ', array_slice($tasks, 0, 4));
    }

    private function hasWorkPrefix(string $type, string $task): bool
    {
        $prefixes = match ($type) {
            'psb' => ['PSB', 'STANDBY PSB'],
            'pembangunan' => ['DEPLOY', 'PELURUSAN', 'TERMINASI', 'REPAIR'],
            'standby' => ['STANDBY'],
            default => ['MAINTENANCE', 'OPEN TICKET'],
        };

        return Str::startsWith(Str::upper($task), $prefixes);
    }

    private function maxPriority(string $a, string $b): string
    {
        $rank = ['low' => 1, 'normal' => 2, 'high' => 3, 'urgent' => 4];
        $a = Str::lower($a);
        $b = Str::lower($b);

        return ($rank[$b] ?? 2) > ($rank[$a] ?? 2) ? $b : $a;
    }

    /**
     * @param  array<string, mixed>  $bucket
     */
    private function sourceRef(array $bucket): string
    {
        $site = Str::slug((string) ($bucket['site'] ?? 'open'), '-');
        $type = Str::slug((string) ($bucket['task_type'] ?? 'task'), '-');

        return Str::limit('auto:t'.($bucket['team_no'] ?? 'x').':'.$site.':'.$type, 120, '');
    }
}
