<?php

namespace App\Services;

/**
 * Keeps monitoring work isolated without making a missing worker stall the
 * dashboard cache. The existing `monitoring` lane remains the safe fallback.
 */
class MonitoringQueueContract
{
    public const FALLBACK_QUEUE = 'monitoring';

    /** @var array<string, string> */
    private const LANES = [
        'router' => 'monitoring-router',
        'olt' => 'monitoring-olt',
        'acs' => 'monitoring-acs',
        'ping' => 'monitoring-ping',
        'evaluate' => 'monitoring-evaluate',
    ];

    public function queueFor(string $lane): string
    {
        $queue = self::LANES[$lane] ?? self::FALLBACK_QUEUE;

        if (! $this->decompositionEnabled() || ! in_array($queue, $this->consumers(), true)) {
            return self::FALLBACK_QUEUE;
        }

        return $queue;
    }

    /** @return array<int, string> */
    public function consumers(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $queue): string => trim((string) $queue),
            (array) config('services.monitoring.queue_consumers', []),
        )));
    }

    public function decompositionEnabled(): bool
    {
        return filter_var(
            config('services.monitoring.queue_decomposition_enabled', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $lanes = [];
        foreach (self::LANES as $name => $queue) {
            $lanes[$name] = [
                'queue' => $queue,
                'consumer_active' => in_array($queue, $this->consumers(), true),
                'selected_queue' => $this->queueFor($name),
            ];
        }

        return [
            'enabled' => $this->decompositionEnabled(),
            'fallback_queue' => self::FALLBACK_QUEUE,
            'consumers' => $this->consumers(),
            'lanes' => $lanes,
        ];
    }
}
