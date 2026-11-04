<?php

namespace App\Services\CustomerOnu;

/**
 * Result of evaluating one customer for the ONU field sync (olt_id, olt_port_label, onu_serial).
 * Pure value object: no phone/NIK/address/coordinates/password ever lands here.
 */
final class CustomerOnuSyncVerdict
{
    public const SYNC = 'SYNC';

    public const PERLU_DICEK_ADMIN = 'PERLU_DICEK_ADMIN';

    public const SKIP = 'SKIP';

    public const SCOPE_MIGRATION = 'migration';

    public const SCOPE_SERIAL = 'serial';

    public const SUDAH_SESUAI = 'sudah_sesuai';

    /**
     * @param  array{olt_id: int|null, olt_port_label: string|null, onu_serial: string|null}|null  $before
     * @param  array{olt_id: int|null, olt_port_label: string|null, onu_serial: string|null}|null  $after
     * @param  array<string, mixed>  $evidence
     * @param  list<string>  $info
     */
    public function __construct(
        public readonly int $customerId,
        public readonly string $code,
        public readonly string $kind,
        public readonly string $scope,
        public readonly ?string $subScope,
        public readonly string $reason,
        public readonly ?array $before = null,
        public readonly ?array $after = null,
        public readonly array $evidence = [],
        public readonly ?int $migrationId = null,
        public readonly ?string $migrationAutoRunId = null,
        public readonly ?string $snSource = null,
        public readonly array $info = [],
    ) {}

    public function isSync(): bool
    {
        return $this->kind === self::SYNC;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'customer_id' => $this->customerId,
            'code' => $this->code,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'sub_scope' => $this->subScope,
            'reason' => $this->reason,
            'before' => $this->before,
            'after' => $this->after,
            'evidence' => $this->evidence,
            'migration_id' => $this->migrationId,
            'migration_auto_run_id' => $this->migrationAutoRunId,
            'sn_source' => $this->snSource,
            'info' => $this->info,
        ];
    }
}
