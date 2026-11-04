<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Models\PsbDraft;
use App\Models\PsbStarliteEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ZipArchive;

class CustomerMigrationImportPreviewService
{
    public function correctRow(
        CustomerMigrationImportRow $row,
        string $classification,
        ?string $rw,
        string $reason,
        ?int $userId = null
    ): bool {
        if ($row->applied_at !== null) {
            return false;
        }

        $before = [
            'classification' => $row->classification,
            'rw' => $row->rw,
            'match_status' => $row->match_status,
        ];
        $after = [
            'classification' => $classification,
            'rw' => $this->canonicalRwValue($rw ?: $row->rw),
            'match_status' => $this->manualMatchStatus($row, $classification),
        ];
        if ($before === $after
            && trim((string) $row->override_reason) === trim($reason)
            && (int) ($row->corrected_by ?? 0) === (int) ($userId ?? 0)) {
            return true;
        }
        $key = hash('sha256', implode('|', [
            $row->id,
            json_encode($before),
            json_encode($after),
            trim($reason),
            $userId ?: 0,
        ]));
        $meta = is_array($row->meta) ? $row->meta : [];
        $audit = is_array($meta['correction_audit'] ?? null) ? $meta['correction_audit'] : [];
        if (! collect($audit)->contains(fn ($entry) => ($entry['idempotency_key'] ?? null) === $key)) {
            $audit[] = [
                'idempotency_key' => $key,
                'before' => $before,
                'after' => $after,
                'reason' => $reason,
                'actor_id' => $userId,
                'at' => now()->toIso8601String(),
            ];
        }

        $row->classification = $after['classification'];
        $row->classification_source = 'manual_override';
        $row->rw = $after['rw'];
        $row->rw_needs_review = ! $this->isValidRw($row->rw);
        $row->match_status = $after['match_status'];
        $row->override_reason = $reason;
        $row->corrected_by = $userId;
        $row->corrected_at = now();
        $meta['correction_audit'] = array_slice($audit, -20);
        $meta['approval_state'] = 'corrected_pending_apply';
        $row->meta = $meta;
        $row->save();

        return true;
    }

    /**
     * @param  list<int>  $ids
     */
    public function bulkCorrect(array $ids, string $classification, ?string $rw, string $reason, ?int $userId = null): int
    {
        $rows = CustomerMigrationImportRow::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->whereNull('applied_at')
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $count += $this->correctRow($row, $classification, $rw, $reason, $userId) ? 1 : 0;
        }

        return $count;
    }

    /**
     * @param  list<int>  $ids
     * @return array{applied: int, skipped: int, errors: list<string>}
     */
    public function applyRows(array $ids, ?int $userId = null, string $reason = ''): array
    {
        $out = ['applied' => 0, 'skipped' => 0, 'errors' => []];
        $rows = CustomerMigrationImportRow::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $ids))))
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $blocker = $this->applyBlocker($row);
            if ($blocker !== null) {
                $this->markApplyError($row, $blocker, $userId);
                $out['skipped']++;
                $out['errors'][] = "#{$row->id}: {$blocker}";

                continue;
            }

            try {
                DB::transaction(function () use ($row, $userId, $reason, &$out) {
                    $locked = CustomerMigrationImportRow::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                    $blocker = $this->applyBlocker($locked);
                    if ($blocker !== null) {
                        $this->markApplyError($locked, $blocker, $userId);
                        $out['skipped']++;
                        $out['errors'][] = "#{$locked->id}: {$blocker}";

                        return;
                    }

                    $target = $locked->classification === 'migration'
                        ? $this->applyMigrationRow($locked, $reason)
                        : $this->applyPsbRow($locked, $reason);

                    $locked->status = 'applied';
                    $locked->applied_target_type = $target::class;
                    $locked->applied_target_id = $target->id;
                    $locked->applied_error = null;
                    $locked->applied_by = $userId;
                    $locked->applied_at = now();
                    $meta = is_array($locked->meta) ? $locked->meta : [];
                    $meta['apply_audit'] = [
                        'idempotency_key' => $this->applyKey($locked, $locked->classification),
                        'reason' => $reason,
                        'actor_id' => $userId,
                        'at' => now()->toIso8601String(),
                        'target_type' => $target::class,
                        'target_id' => $target->id,
                        'device_write' => false,
                    ];
                    $locked->meta = $meta;
                    $locked->save();
                    $out['applied']++;
                });
            } catch (\Throwable $e) {
                $this->markApplyError($row, $e->getMessage(), $userId);
                $out['skipped']++;
                $out['errors'][] = "#{$row->id}: ".$e->getMessage();
            }
        }

        return $out;
    }

    /**
     * @return array{batch_key: string, imported: int, skipped: int, errors: list<string>}
     */
    public function importDryRunFromPath(string $path, ?int $userId = null, string $sourceLabel = 'excel'): array
    {
        $out = ['batch_key' => 'mig-'.now('Asia/Jakarta')->format('YmdHis').'-'.Str::random(5), 'imported' => 0, 'skipped' => 0, 'errors' => []];
        if (! is_file($path)) {
            $out['errors'][] = "File not found: {$path}";

            return $out;
        }

        $rows = str_ends_with(strtolower($path), '.csv')
            ? $this->readCsv($path)
            : $this->readXlsx($path);

        if ($rows === []) {
            $out['errors'][] = 'Tidak ada row yang bisa dibaca dari file.';

            return $out;
        }

        $seen = [];
        foreach ($rows as $index => $raw) {
            $normalized = $this->normalizeRow($raw);
            if ($this->isBlank($normalized)) {
                $out['skipped']++;

                continue;
            }

            $row = $this->classifyAndMatch($normalized, $seen);
            $row['batch_key'] = $out['batch_key'];
            $row['source_label'] = $sourceLabel;
            $row['row_number'] = $index + 2;
            $row['raw_data'] = $raw;
            $row['imported_by'] = $userId;
            $row['imported_at'] = now();
            CustomerMigrationImportRow::create($row);
            $out['imported']++;
        }

        return $out;
    }

    /**
     * @return list<array<string, string>>
     */
    protected function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return [];
        }

        $headers = [];
        $rows = [];
        while (($cols = fgetcsv($handle)) !== false) {
            if ($headers === []) {
                $headers = array_map(fn ($h) => $this->fieldKey((string) $h), $cols);

                continue;
            }
            $row = [];
            foreach ($cols as $i => $value) {
                $row[$headers[$i] ?? 'col_'.$i] = trim((string) $value);
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    protected function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $strings = $this->readSharedStrings($zip);
            $sheet = $this->firstSheetPath($zip);
            if (! $sheet) {
                return [];
            }
            $grid = $this->readSheetGrid($zip, $sheet, $strings);
        } finally {
            $zip->close();
        }

        $headerRow = null;
        $headers = [];
        foreach ($grid as $rowNo => $cols) {
            $labels = array_map(fn ($v) => $this->fieldKey((string) $v), $cols);
            $joined = implode(' ', $labels);
            if (str_contains($joined, 'pppoe') || str_contains($joined, 'nama_pelanggan') || str_contains($joined, 'sn_baru')) {
                $headerRow = $rowNo;
                $headers = $labels;
                break;
            }
        }
        if ($headerRow === null) {
            return [];
        }

        $rows = [];
        foreach ($grid as $rowNo => $cols) {
            if ($rowNo <= $headerRow) {
                continue;
            }
            $row = [];
            foreach ($headers as $letter => $key) {
                if ($key !== '') {
                    $row[$key] = trim((string) ($cols[$letter] ?? ''));
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $raw
     * @return array<string, string|null>
     */
    protected function normalizeRow(array $raw): array
    {
        $get = fn (array $keys): string => trim((string) collect($keys)->map(fn ($key) => $raw[$key] ?? null)->first(fn ($v) => trim((string) $v) !== '', ''));
        $snBaru = $this->normalizeSn($get(['sn_baru', 'sn_baru_fiberhome', 'new_sn']));
        $snLama = $this->normalizeSn($get(['sn_lama', 'old_sn', 'sn_lama_zte', 'sn_lama_onu']));
        $sn = $this->normalizeSn($get(['sn', 'serial_number', 'onu_sn']));
        if ($snBaru === null && $this->isFhtt($sn)) {
            $snBaru = $sn;
        } elseif ($snLama === null && $sn !== null) {
            $snLama = $sn;
        }

        return [
            'project_raw' => $get(['project', 'proyek', 'batch', 'source']),
            'area_raw' => $get(['area', 'daerah', 'wilayah', 'lokasi']),
            'sub_area_raw' => $get(['sub_area', 'subarea', 'dusun', 'desa']),
            'rw_raw' => $get(['rw', 'wilayah_pelanggan']),
            'service_raw' => $get(['jenis_layanan', 'service_type', 'layanan', 'jenis']),
            'vendor_raw' => $get(['vendor', 'profile', 'provisioning']),
            'full_name' => $get(['nama_pelanggan', 'nama', 'full_name', 'name']) ?: null,
            'pppoe_user' => $this->normalizePppoe($get(['pppoe', 'pppoe_user', 'username', 'nama_pppoe'])),
            'sn_lama' => $snLama,
            'sn_baru' => $snBaru,
            'old_onu_ref' => $get(['old_onu_ref', 'onu_lama', 'no_onu_lama', 'onu_ref']) ?: null,
            'status_raw' => $get(['status', 'install_status']),
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     * @param  array<string, int>  $seen
     * @return array<string, mixed>
     */
    protected function classifyAndMatch(array $row, array &$seen): array
    {
        $area = $this->canonicalArea($row['area_raw'] ?: $row['project_raw']);
        $project = $this->canonicalProject($row['project_raw'] ?: $row['service_raw'], $area);
        if ($project === 'starlite-randuagung') {
            $area = 'randuagung-starlite';
        }
        $serviceKey = $this->key((string) $row['service_raw']);
        $classification = $project === 'starlite-akuisisi' || str_contains($serviceKey, 'migrasi') || str_contains($serviceKey, 'akuisisi')
            ? 'migration'
            : 'psb_new_candidate';
        $source = 'excel_rule';

        $rw = $this->canonicalRw($row['rw_raw'], $area, $project);
        $notes = [];
        if ($rw['normalized_from']) {
            $notes[] = "RW Excel/KTP {$rw['normalized_from']} dinormalisasi ke {$rw['rw']} untuk domisili PSB Starlite Randuagung.";
        }

        $customer = $this->findCustomer($row);
        if ($row['sn_lama'] === null && $customer?->onu_serial) {
            $row['sn_lama'] = $this->normalizeSn($customer->onu_serial);
        }

        $oltMatch = $row['sn_baru'] ? null : $this->findFhttOltSnapshot($row);
        if ($row['sn_baru'] === null && $oltMatch) {
            $row['sn_baru'] = $this->normalizeSn((string) ($oltMatch->serial_number ?? ''));
            $row['old_onu_ref'] = $row['old_onu_ref'] ?: ($oltMatch->onu_ref ?? null);
        }

        if ($classification === 'migration' && $this->overlapsPsb($row)) {
            $classification = 'psb_new_candidate';
            $project = $area === 'randuagung' ? 'starlite-randuagung' : $area;
            $source = 'system_match';
            $notes[] = 'Overlap PSB WA/history, tidak dihitung sebagai migrasi.';
        }

        $dedupeKey = $this->dedupeKey($row);
        $seen[$dedupeKey] = ($seen[$dedupeKey] ?? 0) + 1;
        $duplicate = $dedupeKey !== 'blank:' && $seen[$dedupeKey] > 1;

        $status = match (true) {
            $duplicate => 'duplicate',
            $classification === 'psb_new_candidate' => 'psb_new_candidate',
            $row['sn_baru'] !== null && $this->isFhtt($row['sn_baru']) => 'migrated',
            $row['pppoe_user'] === null && $row['full_name'] === null => 'needs_review',
            default => 'not_migrated',
        };

        if ($duplicate) {
            $notes[] = 'Duplikat dalam batch import.';
        }
        if ($row['sn_baru'] === null && $classification === 'migration') {
            $notes[] = 'SN baru belum FHTT dari Excel/snapshot OLT.';
        }
        if ($rw['needs_review']) {
            $notes[] = 'RW kosong/ambigu, perlu review.';
        }

        return [
            'classification' => $classification,
            'classification_source' => $source,
            'project' => $project,
            'area' => $area,
            'sub_area' => $row['sub_area_raw'] ?: '-',
            'rw' => $rw['rw'],
            'rw_needs_review' => $rw['needs_review'],
            'full_name' => $row['full_name'],
            'pppoe_user' => $row['pppoe_user'],
            'sn_lama' => $row['sn_lama'],
            'sn_baru' => $row['sn_baru'],
            'sn_baru_source' => $oltMatch ? 'olt_snapshot' : ($row['sn_baru'] ? 'excel' : null),
            'old_onu_ref' => $row['old_onu_ref'],
            'status' => 'staged',
            'match_status' => $status,
            'match_notes' => implode(' ', array_filter($notes)) ?: null,
            'meta' => [
                'service_raw' => $row['service_raw'],
                'project_raw' => $row['project_raw'],
                'area_raw' => $row['area_raw'],
                'rw_raw' => $row['rw_raw'],
                'rw_normalized_from' => $rw['normalized_from'],
                'vendor_raw' => $row['vendor_raw'],
                'customer_id' => $customer?->id,
            ],
        ];
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function findCustomer(array $row): ?Customer
    {
        if (! $row['pppoe_user'] && ! $row['full_name']) {
            return null;
        }

        return Customer::query()
            ->where(function ($q) use ($row) {
                if ($row['pppoe_user']) {
                    $q->orWhereRaw('UPPER(pppoe_user) = ?', [$row['pppoe_user']]);
                }
                if ($row['full_name']) {
                    $q->orWhere('name', 'like', '%'.str_replace(['%', '_'], '', $row['full_name']).'%');
                }
            })
            ->first();
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function findFhttOltSnapshot(array $row): ?object
    {
        if (! Schema::hasTable('olt_onus')) {
            return null;
        }
        if (! $row['pppoe_user'] && ! $row['full_name']) {
            return null;
        }

        return DB::table('olt_onus')
            ->where('serial_number', 'like', 'FHTT%')
            ->where(function ($q) use ($row) {
                if ($row['pppoe_user']) {
                    $q->orWhere('onu_name', 'like', '%'.$row['pppoe_user'].'%');
                }
                if ($row['full_name']) {
                    $q->orWhere('onu_name', 'like', '%'.str_replace(['%', '_'], '', $row['full_name']).'%');
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function overlapsPsb(array $row): bool
    {
        $pppoe = $row['pppoe_user'];
        $name = $row['full_name'];
        $sn = $row['sn_baru'];

        if (Schema::hasTable('psb_drafts')) {
            $hit = PsbDraft::query()
                ->where(function ($q) use ($pppoe, $name, $sn) {
                    if ($pppoe) {
                        $q->orWhereRaw('UPPER(pppoe_user) = ?', [$pppoe]);
                    }
                    if ($sn) {
                        $q->orWhereRaw('UPPER(sn) = ?', [$sn]);
                    }
                    if ($name) {
                        $q->orWhere('full_name', 'like', '%'.str_replace(['%', '_'], '', $name).'%');
                    }
                })
                ->exists();
            if ($hit) {
                return true;
            }
        }

        if (! Schema::hasTable('psb_starlite_entries')) {
            return false;
        }

        return PsbStarliteEntry::query()
            ->where(function ($q) use ($pppoe, $name, $sn) {
                if ($pppoe) {
                    $q->orWhereRaw('UPPER(pppoe_user) = ?', [$pppoe]);
                }
                if ($sn) {
                    $q->orWhereRaw('UPPER(sn) = ?', [$sn]);
                }
                if ($name) {
                    $q->orWhere('full_name', 'like', '%'.str_replace(['%', '_'], '', $name).'%');
                }
            })
            ->where(function ($q) {
                $q->whereNull('service_type')->orWhere('service_type', 'not like', '%Migrasi%');
            })
            ->exists();
    }

    protected function applyBlocker(CustomerMigrationImportRow $row): ?string
    {
        if ($row->applied_at !== null || $row->status === 'applied') {
            return 'Row sudah pernah di-apply.';
        }
        if (! in_array($row->classification, ['migration', 'psb_new_candidate'], true)) {
            return 'Klasifikasi masih review.';
        }
        if ($row->match_status === 'duplicate') {
            return 'Row duplikat, koreksi dulu sebelum apply.';
        }
        if (str_starts_with((string) $row->batch_key, 'excel-master-conflicts:')
            && ($row->classification_source !== 'manual_override' || trim((string) $row->override_reason) === '')) {
            return 'Konflik Excel wajib dikoreksi dan diberi alasan sebelum apply.';
        }
        if ($row->rw_needs_review || ! $this->isValidRw($row->rw)) {
            return 'RW belum valid.';
        }
        if (! $row->full_name && ! $row->pppoe_user) {
            return 'Nama/PPPoE kosong.';
        }

        return null;
    }

    protected function applyMigrationRow(CustomerMigrationImportRow $row, string $reason): CustomerMigration
    {
        $messageId = $this->applyKey($row, 'migration');
        $status = $this->isFhtt($row->sn_baru) ? CustomerMigration::STATUS_SYNCED : CustomerMigration::STATUS_RECEIVED;
        $migration = CustomerMigration::firstOrNew(['message_id' => $messageId]);

        $migration->fill([
            'status' => $status,
            'source' => 'excel_import',
            'raw_text' => 'Applied from migration staging import. No device write executed.',
            'customer_id' => $row->meta['customer_id'] ?? null,
            'pppoe_user' => $row->pppoe_user,
            'full_name' => $row->full_name,
            'rw' => $this->rwNumber($row->rw),
            'daerah' => $row->area,
            'old_onu_ref' => $row->old_onu_ref,
            'old_sn' => $row->sn_lama,
            'new_sn' => $row->sn_baru,
            'last_error' => $status === CustomerMigration::STATUS_SYNCED
                ? null
                : 'Applied dari staging: belum migrasi/FHTT belum cocok.',
            'meta' => [
                'import_row_id' => $row->id,
                'batch_key' => $row->batch_key,
                'project' => $row->project,
                'area' => $row->area,
                'sub_area' => $row->sub_area,
                'rw' => $row->rw,
                'match_status' => $row->match_status,
                'classification_source' => $row->classification_source,
                'override_reason' => $row->override_reason,
                'apply_reason' => $reason,
                'device_write' => false,
            ],
            'synced_at' => $status === CustomerMigration::STATUS_SYNCED ? now() : null,
        ]);
        $migration->save();

        return $migration;
    }

    protected function applyPsbRow(CustomerMigrationImportRow $row, string $reason): PsbStarliteEntry
    {
        $sourceKey = $this->applyKey($row, 'psb');
        $entry = PsbStarliteEntry::firstOrNew(['source_key' => $sourceKey]);
        $entry->fill([
            'installed_on' => now('Asia/Jakarta')->toDateString(),
            'rw' => $row->rw,
            'full_name' => $row->full_name,
            'pppoe_user' => $row->pppoe_user,
            'sn' => $row->sn_baru,
            'service_type' => 'PSB',
            'install_status' => $this->isFhtt($row->sn_baru) ? 'Success' : 'Pending',
            'kendala' => $this->isFhtt($row->sn_baru) ? null : 'Applied dari staging: SN FHTT belum cocok.',
            'source' => 'migration_staging',
            'meta' => [
                'import_row_id' => $row->id,
                'batch_key' => $row->batch_key,
                'project' => $row->project,
                'area' => $row->area,
                'sub_area' => $row->sub_area,
                'match_status' => $row->match_status,
                'classification_source' => $row->classification_source,
                'override_reason' => $row->override_reason,
                'apply_reason' => $reason,
                'device_write' => false,
            ],
        ]);
        $entry->save();

        return $entry;
    }

    protected function markApplyError(CustomerMigrationImportRow $row, string $error, ?int $userId): void
    {
        CustomerMigrationImportRow::query()
            ->whereKey($row->id)
            ->whereNull('applied_at')
            ->update([
                'applied_error' => mb_substr($error, 0, 1000),
                'applied_by' => $userId,
                'updated_at' => now(),
            ]);
    }

    protected function applyKey(CustomerMigrationImportRow $row, string $type): string
    {
        return "staging-import:{$type}:{$row->batch_key}:{$row->id}";
    }

    protected function manualMatchStatus(CustomerMigrationImportRow $row, string $classification): string
    {
        return match ($classification) {
            'psb_new_candidate' => 'psb_new_candidate',
            'needs_review' => 'needs_review',
            default => $this->isFhtt($row->sn_baru) ? 'migrated' : 'not_migrated',
        };
    }

    protected function canonicalRwValue(?string $rw): string
    {
        $value = trim((string) $rw);
        if ($value !== '' && preg_match('/(?:rw\s*)?(\d{1,3})/i', $value, $m)) {
            return 'RW '.((int) $m[1]);
        }

        return '(review)';
    }

    protected function isValidRw(?string $rw): bool
    {
        return (bool) preg_match('/^RW \d{1,3}$/', (string) $rw);
    }

    protected function rwNumber(?string $rw): ?string
    {
        return preg_match('/(\d{1,3})/', (string) $rw, $m) ? str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT) : null;
    }

    protected function canonicalProject(?string $raw, string $area): string
    {
        $key = $this->key((string) $raw);
        if (in_array($key, ['starliteakuisisi', 'akuisisi', 'acquisition', 'migrasi'], true)) {
            return 'starlite-akuisisi';
        }
        if (in_array($key, ['randuagungstarlite', 'starliteranduagung'], true)) {
            return 'starlite-randuagung';
        }

        return in_array($area, ['randuagung', 'randuagung-starlite'], true) ? 'starlite-randuagung' : $area;
    }

    protected function canonicalArea(?string $raw): string
    {
        $key = $this->key((string) $raw);
        if (in_array($key, ['randuagungstarlite', 'starliteranduagung'], true)
            || (str_contains($key, 'randuagung') && str_contains($key, 'starlite'))) {
            return 'randuagung-starlite';
        }
        if ($key === '' || in_array($key, ['ra', 'randuagung', 'starliteakuisisi'], true)) {
            return 'randuagung';
        }
        foreach (['krian', 'gadang', 'bumiayu', 'martopuro', 'mangliawan', 'srigading'] as $area) {
            if (str_contains($key, $area)) {
                return $area;
            }
        }

        return in_array($key, ['c300', 'fiberhome', 'hioso', 'htb'], true) ? 'unknown' : $key;
    }

    /**
     * @return array{rw: string, needs_review: bool, normalized_from: string|null}
     */
    protected function canonicalRw(?string $raw, string $area, string $project): array
    {
        $value = trim((string) $raw);
        if (in_array($area, ['randuagung', 'randuagung-starlite'], true) && $project === 'starlite-randuagung') {
            $normalizedFrom = null;
            if ($value !== '' && preg_match('/(?:rw\s*)?(\d{1,3})/i', $value, $m) && (int) $m[1] !== 4) {
                $normalizedFrom = 'RW '.((int) $m[1]);
            }

            return ['rw' => 'RW 4', 'needs_review' => false, 'normalized_from' => $normalizedFrom];
        }

        if ($value !== '' && preg_match('/(?:rw\s*)?(\d{1,3})/i', $value, $m)) {
            return ['rw' => 'RW '.((int) $m[1]), 'needs_review' => false, 'normalized_from' => null];
        }

        return ['rw' => '(review)', 'needs_review' => true, 'normalized_from' => null];
    }

    protected function fieldKey(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($value)) ?? '', '_');
    }

    protected function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
    }

    protected function normalizeSn(string $value): ?string
    {
        $sn = strtoupper(preg_replace('/\s+/', '', trim($value)) ?? '');

        return $sn !== '' ? $sn : null;
    }

    protected function normalizePppoe(string $value): ?string
    {
        $pppoe = strtoupper(trim($value));

        return $pppoe !== '' ? $pppoe : null;
    }

    protected function isFhtt(?string $sn): bool
    {
        return str_starts_with(strtoupper((string) $sn), 'FHTT');
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function isBlank(array $row): bool
    {
        return ! $row['full_name'] && ! $row['pppoe_user'] && ! $row['sn_lama'] && ! $row['sn_baru'];
    }

    /**
     * @param  array<string, string|null>  $row
     */
    protected function dedupeKey(array $row): string
    {
        return $row['pppoe_user'] ? 'pppoe:'.$row['pppoe_user']
            : ($row['sn_baru'] ? 'sn:'.$row['sn_baru'] : 'blank:');
    }

    /**
     * @return list<string>
     */
    protected function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! $xml) {
            return [];
        }
        preg_match_all('/<si>(.*?)<\/si>/s', $xml, $items);

        return array_map(function ($si) {
            preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $si, $texts);

            return html_entity_decode(implode('', $texts[1] ?? []), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }, $items[1] ?? []);
    }

    protected function firstSheetPath(ZipArchive $zip): ?string
    {
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! $rels || ! preg_match('/Target="([^"]+)"/', $rels, $m)) {
            return null;
        }
        $target = $m[1];
        if (! str_starts_with($target, 'worksheets/')) {
            $target = 'worksheets/'.basename($target);
        }

        return 'xl/'.$target;
    }

    /**
     * @param  list<string>  $strings
     * @return array<int, array<string, string>>
     */
    protected function readSheetGrid(ZipArchive $zip, string $path, array $strings): array
    {
        $xml = $zip->getFromName($path);
        if (! $xml) {
            return [];
        }
        preg_match_all('/<c r="([A-Z]+)(\d+)"([^>]*)>(?:<v>([^<]*)<\/v>)?/', $xml, $cells, PREG_SET_ORDER);
        $grid = [];
        foreach ($cells as $cell) {
            $value = $cell[4] ?? '';
            if (str_contains($cell[3], 't="s"')) {
                $value = $strings[(int) $value] ?? $value;
            }
            $grid[(int) $cell[2]][$cell[1]] = $value;
        }
        ksort($grid);

        return $grid;
    }
}
