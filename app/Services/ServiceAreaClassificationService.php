<?php

namespace App\Services;

use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use Illuminate\Support\Str;

class ServiceAreaClassificationService
{
    public const META_KEY = 'service_classification';

    private const CUTOFF = '2026-07-24 00:00:00';

    /** @var list<string> */
    private const PHYSICAL_AREAS = [
        'randuagung', 'kalirejo', 'suko', 'gondang', 'krian', 'klampok',
        'mangliawan', 'randutelu', 'capang', 'gadang', 'bumiayu',
        'martopuro', 'srigading', 'karangploso', 'bedali', 'songsong',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forMigration(CustomerMigration $migration): array
    {
        $area = $this->physicalArea($migration->daerah, $migration->area?->name);
        $status = (string) $migration->status;
        $hasIdentity = (bool) ($migration->customer_id || $migration->pppoe_user || $migration->new_sn);
        $review = in_array($status, [CustomerMigration::STATUS_FAILED, CustomerMigration::STATUS_CANCELLED], true)
            || ! $hasIdentity
            || $area['ambiguous'];

        return $this->payload([
            'service_origin' => 'migration_acquisition',
            'project' => 'starlite-akuisisi',
            'customer_area' => $area['value'],
            'operational_area' => null,
            'service_profile' => $this->migrationProfile($migration),
            'evidence' => 'customer_migrations:'.($migration->source ?: 'unknown'),
            'source_priority' => $this->sourcePriority($migration->created_at, (string) $migration->source),
            'confidence' => $review ? 'review' : 'high',
            'reason' => $review ? $this->reviewReason($area, $hasIdentity, $status) : 'confirmed_migration_source',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPsbDraft(PsbDraft $draft): array
    {
        $area = $this->physicalArea($draft->daerah, $draft->area?->name);
        $isRanduagung = $this->isRanduagungSite((string) $draft->site_profile_key)
            || $area['value'] === 'randuagung';
        $review = in_array((string) $draft->status, [PsbDraft::STATUS_CANCELLED], true)
            || ! ($draft->pppoe_user || $draft->sn || $draft->full_name)
            || $area['ambiguous'];

        return $this->payload([
            'service_origin' => 'psb_new',
            'project' => $isRanduagung ? 'starlite-randuagung' : 'psb-new',
            'customer_area' => $area['value'],
            'operational_area' => $isRanduagung ? 'randuagung-starlite' : null,
            'service_profile' => $draft->mikrotik_profile ?: null,
            'evidence' => 'psb_drafts:'.($draft->group_jid === 'manual@ebilling' ? 'manual' : 'wa'),
            'source_priority' => $this->sourcePriority($draft->created_at, 'psb_draft'),
            'confidence' => $review ? 'review' : 'high',
            'reason' => $review ? $this->reviewReason($area, (bool) ($draft->pppoe_user || $draft->sn || $draft->full_name), (string) $draft->status) : 'psb_draft_source',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function forPsbEntry(PsbStarliteEntry $entry): array
    {
        $service = $this->key((string) $entry->service_type);
        $isMigration = str_contains($service, 'migrasi') || str_contains($service, 'akuisisi') || str_contains($service, 'acquisition');
        $area = $this->physicalArea('Randuagung');

        return $this->payload([
            'service_origin' => $isMigration ? 'migration_acquisition' : 'psb_new',
            'project' => $isMigration ? 'starlite-akuisisi' : 'starlite-randuagung',
            'customer_area' => $area['value'],
            'operational_area' => $isMigration ? null : 'randuagung-starlite',
            'service_profile' => null,
            'evidence' => 'psb_starlite_entries:'.($entry->source ?: 'unknown'),
            'source_priority' => 'snapshot',
            'confidence' => $entry->pppoe_user || $entry->sn ? 'high' : 'review',
            'reason' => $entry->pppoe_user || $entry->sn ? 'historical_psb_source' : 'identity_missing',
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function forPsbPayload(array $row): array
    {
        $service = $this->key((string) ($row['service_type'] ?? 'psb'));
        $isMigration = str_contains($service, 'migrasi') || str_contains($service, 'akuisisi') || str_contains($service, 'acquisition');
        $area = $this->physicalArea($row['daerah'] ?? null, $row['area_name'] ?? null, $row['site_key'] ?? $row['site_profile_key'] ?? null);
        $isRanduagung = $this->isRanduagungSite((string) ($row['site_key'] ?? $row['site_profile_key'] ?? ''))
            || $area['value'] === 'randuagung';
        $persisted = data_get($row, self::META_KEY);

        if (is_array($persisted) && isset($persisted['service_origin'], $persisted['project'], $persisted['customer_area'])) {
            return $persisted;
        }

        return $this->payload([
            'service_origin' => $isMigration ? 'migration_acquisition' : 'psb_new',
            'project' => $isMigration ? 'starlite-akuisisi' : ($isRanduagung ? 'starlite-randuagung' : 'psb-new'),
            'customer_area' => $area['value'],
            'operational_area' => ! $isMigration && $isRanduagung ? 'randuagung-starlite' : null,
            'service_profile' => $row['mikrotik_profile'] ?? null,
            'evidence' => 'psb_unified:'.($row['source'] ?? 'unknown'),
            'source_priority' => 'read_model',
            'confidence' => $area['ambiguous'] ? 'review' : 'high',
            'reason' => $area['ambiguous'] ? 'physical_area_ambiguous' : 'psb_read_model_source',
        ]);
    }

    /**
     * @return array{value: string, ambiguous: bool, matches: list<string>}
     */
    public function physicalArea(?string ...$values): array
    {
        foreach ($values as $value) {
            $source = $this->key((string) $value);
            if ($source === '') {
                continue;
            }

            $matches = [];
            foreach (self::PHYSICAL_AREAS as $area) {
                if (str_contains($source, $this->key($area))) {
                    $matches[] = $area;
                }
            }
            $matches = array_values(array_unique($matches));
            if (count($matches) === 1) {
                return ['value' => $matches[0], 'ambiguous' => false, 'matches' => $matches];
            }
            if (count($matches) > 1) {
                return ['value' => '', 'ambiguous' => true, 'matches' => $matches];
            }
        }

        return ['value' => '', 'ambiguous' => true, 'matches' => []];
    }

    public function isRanduagungSite(string $site): bool
    {
        return in_array($this->key($site), ['ra', 'rdg', 'htbrdg', 'rafullolt', 'defaultfullolt'], true);
    }

    /**
     * @param  array<string, mixed>  $classification
     */
    public function fingerprint(array $classification): string
    {
        $stable = collect($classification)->except(['classified_at', 'actor', 'idempotency_key', 'fingerprint'])->all();
        ksort($stable);

        return hash('sha256', json_encode($stable, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $classification
     * @return array<string, mixed>
     */
    public function persistable(array $classification, string $actor): array
    {
        $classification['schema_version'] = 1;
        $classification['actor'] = $actor;
        $classification['classified_at'] = now('Asia/Jakarta')->toIso8601String();
        $classification['fingerprint'] = $this->fingerprint($classification);
        $classification['idempotency_key'] = 'area004b:'.$classification['fingerprint'];

        return $classification;
    }

    /** @return array<string, mixed> */
    private function payload(array $values): array
    {
        return $values + [
            'schema_version' => 1,
        ];
    }

    private function migrationProfile(CustomerMigration $migration): ?string
    {
        $meta = is_array($migration->meta) ? $migration->meta : [];

        foreach (['service_profile', 'target_profile', 'target_package_profile'] as $key) {
            $value = trim((string) data_get($meta, $key));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function sourcePriority(mixed $createdAt, string $source): string
    {
        if ($this->key($source) === 'excelmaster') {
            return 'snapshot';
        }

        return $createdAt && $createdAt->gte(self::CUTOFF) ? 'live_transaction' : 'confirmed_transaction';
    }

    /** @param array{ambiguous: bool, matches: list<string>} $area */
    private function reviewReason(array $area, bool $hasIdentity, string $status): string
    {
        if ($area['ambiguous']) {
            return count($area['matches']) > 1 ? 'physical_area_ambiguous' : 'physical_area_missing';
        }
        if (! $hasIdentity) {
            return 'identity_missing';
        }

        return 'status_'.$status;
    }

    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower(Str::ascii($value))) ?? '';
    }
}
