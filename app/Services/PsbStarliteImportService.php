<?php

namespace App\Services;

use App\Models\PsbStarliteEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Import STARLITE Google Sheet / Excel "Daily Tracker Detail".
 * Does NOT touch MikroTik, OLT, or WA activation.
 */
class PsbStarliteImportService
{
    /**
     * @return array{imported: int, updated: int, skipped: int, sheet: ?string, errors: list<string>}
     */
    public function importFromXlsx(string $path, string $source = 'excel'): array
    {
        $out = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'sheet' => null, 'errors' => []];
        if (! is_file($path)) {
            $out['errors'][] = "File not found: {$path}";

            return $out;
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $out['errors'][] = 'Cannot open xlsx';

            return $out;
        }

        try {
            $strings = $this->readSharedStrings($zip);
            $sheetPath = $this->findSheetPath($zip, ['Daily Tracker Detail', 'Daily Tracker', 'Tracker']);
            if (! $sheetPath) {
                $out['errors'][] = 'Sheet Daily Tracker Detail not found';

                return $out;
            }
            $out['sheet'] = $sheetPath;
            $grid = $this->readSheetGrid($zip, $sheetPath, $strings);
        } finally {
            $zip->close();
        }

        // Find header row
        $headerRow = null;
        $colMap = [];
        foreach ($grid as $r => $cols) {
            $joined = strtolower(implode(' ', array_map('strval', $cols)));
            if (str_contains($joined, 'nama pelanggan') || (str_contains($joined, 'tanggal') && str_contains($joined, 'pppoe'))) {
                $headerRow = $r;
                $colMap = $this->mapHeader($cols);
                break;
            }
        }
        if ($headerRow === null || $colMap === []) {
            $out['errors'][] = 'Header row not found in tracker sheet';

            return $out;
        }

        foreach ($grid as $r => $cols) {
            if ($r <= $headerRow) {
                continue;
            }
            $row = $this->extractRow($cols, $colMap);
            if ($row === null) {
                $out['skipped']++;

                continue;
            }
            $key = $this->sourceKey($row, $source);
            $existing = PsbStarliteEntry::query()->where('source_key', $key)->first();
            $payload = array_merge($row, [
                'source' => $source,
                'source_key' => $key,
            ]);
            if ($existing) {
                $existing->fill($payload);
                $existing->save();
                $out['updated']++;
            } else {
                PsbStarliteEntry::query()->create($payload);
                $out['imported']++;
            }
        }

        Log::info('[PSB Starlite] import done', $out);

        return $out;
    }

    /**
     * Download Google Sheet as xlsx then import.
     *
     * @return array{imported: int, updated: int, skipped: int, sheet: ?string, errors: list<string>}
     */
    public function importFromGoogleSheet(string $spreadsheetId, string $source = 'sheet'): array
    {
        $url = "https://docs.google.com/spreadsheets/d/{$spreadsheetId}/export?format=xlsx";
        $tmp = storage_path('app/psb/starlite-import-'.date('YmdHis').'.xlsx');
        if (! is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0755, true);
        }
        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 90, 'follow_location' => 1, 'header' => "User-Agent: skynet-ebilling\r\n"],
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $bin = @file_get_contents($url, false, $ctx);
            if ($bin === false || strlen($bin) < 100) {
                return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'sheet' => null, 'errors' => ['Download sheet failed']];
            }
            file_put_contents($tmp, $bin);
        } catch (\Throwable $e) {
            return ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'sheet' => null, 'errors' => [$e->getMessage()]];
        }

        return $this->importFromXlsx($tmp, $source);
    }

    /**
     * @return list<string>
     */
    protected function readSharedStrings(ZipArchive $zip): array
    {
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        $strings = [];
        if (! $ssXml) {
            return $strings;
        }
        if (preg_match_all('/<si>(.*?)<\/si>/s', $ssXml, $sis)) {
            foreach ($sis[1] as $si) {
                if (preg_match_all('/<t[^>]*>([^<]*)<\/t>/', $si, $ts)) {
                    $strings[] = html_entity_decode(implode('', $ts[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                } else {
                    $strings[] = '';
                }
            }
        }

        return $strings;
    }

    protected function findSheetPath(ZipArchive $zip, array $wantNames): ?string
    {
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! $wb || ! $rels) {
            return null;
        }
        preg_match_all('/Id="(rId\d+)"[^>]*Target="([^"]+)"/', $rels, $rm, PREG_SET_ORDER);
        $map = [];
        foreach ($rm as $r) {
            $map[$r[1]] = $r[2];
        }
        preg_match_all('/<sheet[^>]*name="([^"]+)"[^>]*r:id="([^"]+)"/', $wb, $m, PREG_SET_ORDER);
        $wantLower = array_map('strtolower', $wantNames);
        foreach ($m as $sheet) {
            $name = html_entity_decode($sheet[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $rid = $sheet[2];
            if (! in_array(strtolower($name), $wantLower, true) && ! str_contains(strtolower($name), 'daily tracker')) {
                continue;
            }
            $target = $map[$rid] ?? null;
            if (! $target) {
                continue;
            }
            if (! str_starts_with($target, 'worksheets/')) {
                $target = 'worksheets/'.basename($target);
            }

            return 'xl/'.$target;
        }

        return null;
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
        foreach ($cells as $c) {
            $col = $c[1];
            $row = (int) $c[2];
            $attrs = $c[3];
            $v = $c[4] ?? '';
            if (str_contains($attrs, 't="s"')) {
                $v = $strings[(int) $v] ?? $v;
            }
            $grid[$row][$col] = $v;
        }
        ksort($grid);

        return $grid;
    }

    /**
     * @param  array<string, string>  $cols
     * @return array<string, string> field => column letter
     */
    protected function mapHeader(array $cols): array
    {
        $map = [];
        foreach ($cols as $letter => $label) {
            $l = strtolower(trim((string) $label));
            $l = preg_replace('/\s+/', ' ', $l) ?? $l;
            if (str_contains($l, 'tanggal')) {
                $map['date'] = $letter;
            } elseif (str_contains($l, 'wilayah') || $l === 'rw' || str_starts_with($l, 'rw ')) {
                $map['rw'] = $letter;
            } elseif (str_contains($l, 'nama pelanggan') || ($l === 'nama' && ! str_contains($l, 'pppoe'))) {
                $map['name'] = $letter;
            } elseif (str_contains($l, 'pppoe') || str_contains($l, 'nama pppoe')) {
                $map['pppoe'] = $letter;
            } elseif ($l === 'sn' || str_contains($l, 'serial number') || str_contains($l, 'no sn') || str_contains($l, 'onu sn') || preg_match('/\bsn\b/', $l)) {
                // only pure SN headers — avoid matching random "serial" in other labels wrongly
                if (! str_contains($l, 'koordinat') && ! str_contains($l, 'tikor')) {
                    $map['sn'] = $letter;
                }
            } elseif (str_contains($l, 'jenis layanan') || str_contains($l, 'psb / migrasi') || $l === 'jenis') {
                $map['service'] = $letter;
            } elseif (str_contains($l, 'status')) {
                $map['status'] = $letter;
            } elseif (str_contains($l, 'koordinat') || str_contains($l, 'titik') || str_contains($l, 'tikor') || str_contains($l, 'lat')) {
                $map['tikor'] = $letter;
            } elseif (str_contains($l, 'odp') || preg_match('/\bport\b/', $l) || str_contains($l, 'odp/port')) {
                $map['odp'] = $letter;
            } elseif (str_contains($l, 'kendala')) {
                $map['kendala'] = $letter;
            } elseif (str_contains($l, 'pic') || str_contains($l, 'teknisi')) {
                $map['pic'] = $letter;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $cols
     * @param  array<string, string>  $colMap
     * @return array<string, mixed>|null
     */
    protected function extractRow(array $cols, array $colMap): ?array
    {
        $get = function (string $key) use ($cols, $colMap): string {
            $letter = $colMap[$key] ?? null;
            if (! $letter) {
                return '';
            }

            return trim((string) ($cols[$letter] ?? ''));
        };

        $name = $get('name');
        $pppoe = $get('pppoe');
        $sn = strtoupper(preg_replace('/\s+/', '', $get('sn')) ?? $get('sn'));
        $tikor = $get('tikor');
        $odp = $get('odp');
        $dateRaw = $get('date');
        if ($name === '' && $pppoe === '' && $sn === '') {
            return null;
        }

        // Excel often puts nama only in PPPoE username (NAMA_RT..). Fill full_name for dashboard.
        if ($name === '' && $pppoe !== '') {
            $name = $this->nameFromPppoe($pppoe);
        }

        // Heal swapped SN <-> koordinat (kolom Excel kadang geser)
        $looksTikor = static function (string $v): bool {
            return (bool) preg_match('/-?\d{1,3}\.\d+\s*[,;]\s*-?\d{1,3}\.\d+/', $v)
                || (bool) preg_match('/\d+\.\d+.+\d+\.\d+/', $v);
        };
        $looksSn = static function (string $v): bool {
            $v = strtoupper(preg_replace('/\s+/', '', $v) ?? '');

            return $v !== '' && strlen($v) >= 8 && (bool) preg_match('/^(FHTT|ZTEG|HWTC|CMDC|ALCL|[A-Z0-9]{10,})$/', $v)
                && ! preg_match('/-?\d+\.\d+,\s*-?\d+\.\d+/', $v);
        };
        if ($sn !== '' && $looksTikor($sn) && ! $looksSn($sn)) {
            if ($tikor === '' || $looksSn($tikor)) {
                $tmp = $tikor;
                $tikor = $sn;
                $sn = $looksSn($tmp) ? strtoupper(preg_replace('/\s+/', '', $tmp) ?? $tmp) : '';
            } else {
                $tikor = $sn;
                $sn = '';
            }
        }
        if ($tikor !== '' && $looksSn($tikor) && ! $looksTikor($tikor) && ($sn === '' || $looksTikor($sn))) {
            $tmp = $sn;
            $sn = strtoupper(preg_replace('/\s+/', '', $tikor) ?? $tikor);
            $tikor = $looksTikor($tmp) ? $tmp : '';
        }

        // ODP numeric "2.0" → "2"
        if ($odp !== '' && (is_numeric($odp) || preg_match('/^\d+\.0$/', $odp))) {
            $odp = (string) (int) round((float) $odp);
        }

        $installed = $this->parseExcelDate($dateRaw);
        $rw = $this->normalizeRw($get('rw'));

        return [
            'installed_on' => $installed,
            'rw' => $rw,
            'full_name' => $name !== '' ? $name : null,
            'pppoe_user' => $pppoe !== '' ? strtoupper(trim($pppoe)) : null,
            'sn' => $sn !== '' ? $sn : null,
            'service_type' => $get('service') ?: 'PSB',
            'install_status' => $get('status') ?: null,
            'tikor' => $tikor !== '' ? $tikor : null,
            'odp' => $odp !== '' ? $odp : null,
            'kendala' => $get('kendala') ?: null,
            'pic' => $get('pic') ?: null,
            'meta' => ['date_raw' => $dateRaw],
        ];
    }

    protected function parseExcelDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Excel serial number
        if (is_numeric($raw)) {
            $serial = (float) $raw;
            // Excel epoch 1899-12-30
            $unix = (int) round(($serial - 25569) * 86400);

            return Carbon::createFromTimestampUTC($unix)->timezone('Asia/Jakarta')->toDateString();
        }
        try {
            return Carbon::parse($raw)->timezone('Asia/Jakarta')->toDateString();
        } catch (\Throwable) {
            if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $raw, $m)) {
                return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            }
        }

        return null;
    }

    /** Derive display name from PPPoE like BUDIDEMO_RT2_RW4_L8 → Budidemo */
    protected function nameFromPppoe(string $pppoe): string
    {
        $base = preg_split('/[_\s]+/', trim($pppoe))[0] ?? '';
        $base = preg_replace('/\d+$/', '', (string) $base) ?? $base; // drop trailing digits (AMININ2)
        $base = trim((string) $base);
        if ($base === '') {
            return trim($pppoe);
        }
        // ALL-CAPS / no lowercase → single token title case (do NOT split every letter)
        if (! preg_match('/[a-z]/', $base)) {
            return mb_convert_case(mb_strtolower($base), MB_CASE_TITLE, 'UTF-8');
        }
        // camelCase only: FooBar → Foo Bar
        $spaced = preg_replace('/(?<=[a-z])([A-Z])/', ' $1', $base) ?? $base;

        return mb_convert_case(mb_strtolower($spaced), MB_CASE_TITLE, 'UTF-8');
    }

    protected function normalizeRw(string $rw): ?string
    {
        $rw = trim($rw);
        if ($rw === '') {
            return null;
        }
        if (preg_match('/(\d{1,3})/', $rw, $m)) {
            return str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return $rw;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function sourceKey(array $row, string $source): string
    {
        $sn = strtoupper((string) ($row['sn'] ?? ''));
        $pppoe = strtoupper((string) ($row['pppoe_user'] ?? ''));
        $date = (string) ($row['installed_on'] ?? '');
        $name = Str::slug((string) ($row['full_name'] ?? ''), '_');
        if ($sn !== '') {
            return substr($source.':sn:'.$sn, 0, 180);
        }
        if ($pppoe !== '') {
            return substr($source.':pppoe:'.$pppoe.':'.$date, 0, 180);
        }

        return substr($source.':name:'.$name.':'.$date.':'.md5(json_encode($row)), 0, 180);
    }
}
