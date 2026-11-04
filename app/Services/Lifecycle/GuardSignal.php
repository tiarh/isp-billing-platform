<?php

namespace App\Services\Lifecycle;

/**
 * One piece of evidence of the lifecycle guard. state:
 *  - in_use         proven (or stale-positive) activity: blocks
 *  - clear          proven inactivity
 *  - unknown        cannot tell: fail-closed (blocks)
 *  - not_applicable the signal does not apply to this subject
 */
final class GuardSignal
{
    public const IN_USE = 'in_use';

    public const CLEAR = 'clear';

    public const UNKNOWN = 'unknown';

    public const NOT_APPLICABLE = 'not_applicable';

    public const PPPOE = 'pppoe';

    public const ONU = 'onu';

    public const TRAFFIC = 'traffic';

    /**
     * @param  array<string, mixed>  $evidence  ids / timestamps / counts only (no names, phones, secrets)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $state,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $evidence = [],
    ) {}

    public static function inUse(string $name, string $reason, string $message, array $evidence = []): self
    {
        return new self($name, self::IN_USE, $reason, $message, $evidence);
    }

    public static function clear(string $name, string $reason, string $message, array $evidence = []): self
    {
        return new self($name, self::CLEAR, $reason, $message, $evidence);
    }

    public static function unknown(string $name, string $reason, string $message, array $evidence = []): self
    {
        return new self($name, self::UNKNOWN, $reason, $message, $evidence);
    }

    public static function notApplicable(string $name, string $reason, string $message = '', array $evidence = []): self
    {
        return new self($name, self::NOT_APPLICABLE, $reason, $message, $evidence);
    }

    public function blocks(): bool
    {
        return $this->state === self::IN_USE || $this->state === self::UNKNOWN;
    }

    /** @return array{name: string, state: string, reason: string, message: string, evidence: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'state' => $this->state,
            'reason' => $this->reason,
            'message' => $this->message,
            'evidence' => $this->evidence,
        ];
    }
}
