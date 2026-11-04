<?php

namespace App\Services\Lifecycle;

/**
 * Outcome of a guard check. verdict: allow | deny (a signal is in_use) |
 * unknown (no in_use, but a signal could not be proven clear). Only 'allow'
 * lets the action proceed (unknown is fail-closed).
 */
final class GuardDecision
{
    public const ALLOW = 'allow';

    public const DENY = 'deny';

    public const UNKNOWN = 'unknown';

    /** @param list<GuardSignal> $signals */
    public function __construct(
        public readonly string $action,
        public readonly array $signals,
        public readonly string $verdict,
    ) {}

    /** @param list<GuardSignal> $signals */
    public static function fromSignals(string $action, array $signals): self
    {
        $verdict = self::ALLOW;
        foreach ($signals as $signal) {
            if ($signal->state === GuardSignal::IN_USE) {
                $verdict = self::DENY;
                break;
            }
            if ($signal->state === GuardSignal::UNKNOWN) {
                $verdict = self::UNKNOWN;
            }
        }

        return new self($action, $signals, $verdict);
    }

    public function allowed(): bool
    {
        return $this->verdict === self::ALLOW;
    }

    /** @return list<GuardSignal> the signals that block (in_use first, then unknown) */
    public function blockingSignals(): array
    {
        $blocking = array_values(array_filter($this->signals, static fn (GuardSignal $s): bool => $s->blocks()));
        usort($blocking, static fn (GuardSignal $a, GuardSignal $b): int => ($a->state === GuardSignal::IN_USE ? 0 : 1) <=> ($b->state === GuardSignal::IN_USE ? 0 : 1));

        return $blocking;
    }

    /** Stable reason codes ("pppoe:pppoe_in_use"); 'allowed' when nothing blocks. @return list<string> */
    public function reasonCodes(): array
    {
        $codes = array_map(static fn (GuardSignal $s): string => $s->name.':'.$s->reason, $this->blockingSignals());
        if ($codes !== []) {
            return $codes;
        }

        $na = $this->notApplicableNames();

        return [$na === [] ? 'allowed' : 'allowed['.implode(',', array_map(static fn (string $n): string => $n.'_na', $na)).']'];
    }

    /** Names of signals that did not apply (nothing was checked for them). @return list<string> */
    public function notApplicableNames(): array
    {
        return array_values(array_map(
            static fn (GuardSignal $s): string => $s->name,
            array_filter($this->signals, static fn (GuardSignal $s): bool => $s->state === GuardSignal::NOT_APPLICABLE),
        ));
    }

    /** Allowed although at least one signal was not applicable (no evidence for it). */
    public function allowedWithNoEvidence(): bool
    {
        return $this->allowed() && $this->notApplicableNames() !== [];
    }

    /** Human readable (Indonesian) joined reasons, empty when allowed. */
    public function message(): string
    {
        return implode(' ', array_map(static fn (GuardSignal $s): string => $s->message, $this->blockingSignals()));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'verdict' => $this->verdict,
            'allowed' => $this->allowed(),
            'reasons' => $this->reasonCodes(),
            'signals' => array_map(static fn (GuardSignal $s): array => $s->toArray(), $this->signals),
        ];
    }
}
