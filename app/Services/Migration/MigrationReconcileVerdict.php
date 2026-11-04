<?php

namespace App\Services\Migration;

/**
 * Result of evaluating ONE open migration row for auto-reconcile. Pure data.
 * Never carries phone / NIK / address / coordinates / password / raw_text.
 */
final class MigrationReconcileVerdict
{
    public const ELIGIBLE = 'eligible';

    public const PERLU_DICEK_ADMIN = 'perlu_dicek_admin';

    public const SKIP = 'skip';

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<array{rule: string, ok: bool, reason: string|null}>  $trace
     */
    public function __construct(
        public readonly int $migrationId,
        public readonly string $kind,
        public readonly string $reason,
        public readonly string $status,
        public readonly ?int $areaId = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly array $evidence = [],
        public readonly ?string $linkProof = null,
        public readonly array $trace = [],
        public readonly ?string $label = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->kind === self::ELIGIBLE;
    }
}
