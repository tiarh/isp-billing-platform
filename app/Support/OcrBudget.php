<?php

namespace App\Support;

use Closure;

/**
 * Wall-clock time budget for one OCR unit (one image or one batch).
 * The clock is injectable so tests never have to sleep.
 */
final class OcrBudget
{
    private readonly float $startedAt;

    private readonly float $deadline;

    /** @var Closure():float */
    private readonly Closure $clock;

    /** @param (Closure():float)|null $clock Returns the current time in seconds (microtime(true) style). */
    public function __construct(float $seconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->startedAt = ($this->clock)();
        $this->deadline = $this->startedAt + max(0.0, $seconds);
    }

    /** @param (Closure():float)|null $clock */
    public static function start(float $seconds, ?Closure $clock = null): self
    {
        return new self($seconds, $clock);
    }

    /** Unix timestamp (same clock) after which no new work may start. */
    public function deadline(): float
    {
        return $this->deadline;
    }

    public function elapsed(): float
    {
        return max(0.0, ($this->clock)() - $this->startedAt);
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }

    public function expired(): bool
    {
        return ($this->clock)() >= $this->deadline;
    }

    /**
     * Sub-budget for one image: the smaller of the per-image seconds and what is
     * left of this (batch) budget. Shares the same clock.
     */
    public function forImage(float $imageSeconds): self
    {
        return new self(min(max(0.0, $imageSeconds), $this->remaining()), $this->clock);
    }

    /** Whole seconds left, never below $min (for process/HTTP timeouts). */
    public function remainingSeconds(int $min = 1): int
    {
        return max($min, (int) ceil($this->remaining()));
    }
}
