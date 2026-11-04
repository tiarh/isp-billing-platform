<?php

namespace Tests\Support\PsbGolden;

/**
 * Ordered event log shared by every golden-test fake (WA sends, device calls,
 * job dispatches). The golden test drains it after each step and scrubs it.
 */
final class PsbGoldenRecorder
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    /** @param array<string, mixed> $data */
    public function add(string $type, array $data = []): void
    {
        $this->events[] = ['type' => $type] + $data;
    }

    /** @return list<array<string, mixed>> */
    public function drain(): array
    {
        $out = $this->events;
        $this->events = [];

        return $out;
    }

    public function count(): int
    {
        return count($this->events);
    }
}
