<?php

namespace App\Support;

/**
 * Line-based KTP text parser (v2).
 *
 * The first parser used one regex per field with `\s*` after the label, which
 * runs across a newline. On column-wise OCR output ("Nama" / "Tempat/Tgl Lahir"
 * / ... as a label column, values in a second column) it returned the NEXT
 * LABEL ("TEMPAT") as the customer name. This parser reads line by line:
 *
 * - row-wise: the value is on the label line or on the next line, and the
 *   value is never a label;
 * - column-wise (3+ label-only lines in a row): values are collected from the
 *   ":" lines that follow and matched to fields by SHAPE (birth date, gender,
 *   RT/RW), not by position.
 *
 * Every field may be null. A label word is never returned as a value.
 */
final class KtpParserV2
{
    /** Words that are KTP labels / fixed values and can never be (part of) a name or address. */
    public const LABEL_WORDS = [
        'NAMA', 'NIK', 'ALAMAT', 'TEMPAT', 'TGL', 'LAHIR', 'JENIS', 'KELAMIN', 'GOL', 'DARAH',
        'AGAMA', 'STATUS', 'PERKAWINAN', 'PEKERJAAN', 'KEWARGANEGARAAN', 'BERLAKU', 'HINGGA',
        'KEL', 'DESA', 'KELURAHAN', 'KECAMATAN', 'KEC', 'PROVINSI', 'KABUPATEN', 'KOTA', 'REPUBLIK',
        'LAKI', 'PEREMPUAN', 'WNI', 'WNA', 'ISLAM', 'KRISTEN', 'KATOLIK', 'HINDU', 'BUDDHA', 'BUDHA',
        'KONGHUCU', 'BELUM', 'KAWIN', 'SEUMUR', 'HIDUP', 'RT', 'RW',
    ];

    /** @var array<string,string> label key => regex body (case-insensitive, matched at line start) */
    private const LABELS = [
        'nik' => 'N[I1lL!|]K',
        'nama' => '(?:Nama|Narna|Narma|Nema)',
        'ttl' => '(?:Tempat(?:\s*[\/I1l]?\s*T[gq][li1]\.?\s*Lah[il1]r)?|T[gq][li1]\.?\s*Lah[il1]r|TTL)',
        'gender' => 'Jen[il1]s\s*Ke[li1]\w{2,6}',
        'goldarah' => 'Gol(?:ongan)?\.?\s*Darah',
        'alamat' => '(?:Alamat|Aiamat|Alarnat|A1amat)',
        'rtrw' => 'RT\s*[\/I1l]?\s*RW',
        'keldesa' => '(?:Kel(?:urahan)?(?:\s*[\/I1l]\s*Desa)?|Desa)',
        'kec' => 'Kec(?:amatan)?',
        'agama' => 'Agama',
        'status' => 'Status(?:\s*Perkawinan)?',
        'pekerjaan' => 'Pekerjaan',
        'warga' => 'Kewarga\w*',
        'berlaku' => 'Berlaku(?:\s*Hingga)?',
    ];

    private const HEADER = '/^(?:PROVINSI|KABUPATEN|KOTA|REPUBLIK)\b/iu';

    private const BIRTH_DATE = '/\b\d{1,2}\s*[\-\/.]\s*\d{1,2}\s*[\-\/.]\s*\d{4}\b/';

    /** Digit-like characters OCR confuses inside a digit run. */
    private const DIGITISH = '[0-9OoIl|]';

    /**
     * @return array{nik:?string,nik_valid:bool,name:?string,address:?string,rt:?string,rw:?string,place_of_birth:?string,gender:?string}
     */
    public static function parse(string $raw): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $rows = [];
        foreach ($lines as $i => $line) {
            $rows[$i] = ['raw' => $line, 'key' => null, 'rest' => '', 'header' => false];
            if (preg_match(self::HEADER, $line) === 1) {
                $rows[$i]['header'] = true;

                continue;
            }
            [$key, $rest] = self::labelOf($line);
            $rows[$i]['key'] = $key;
            $rows[$i]['rest'] = $rest;
        }

        // Column-wise runs: 3+ consecutive label-only lines.
        $inRun = [];
        $runs = [];
        $count = count($rows);
        for ($i = 0; $i < $count;) {
            if ($rows[$i]['key'] !== null && $rows[$i]['rest'] === '') {
                $j = $i;
                while ($j + 1 < $count && $rows[$j + 1]['key'] !== null && $rows[$j + 1]['rest'] === '') {
                    $j++;
                }
                if ($j - $i + 1 >= 3) {
                    $runs[] = [$i, $j];
                    for ($k = $i; $k <= $j; $k++) {
                        $inRun[$k] = true;
                    }
                }
                $i = $j + 1;

                continue;
            }
            $i++;
        }

        $fields = ['name' => null, 'address' => null, 'rt' => null, 'rw' => null, 'pob' => null, 'gender' => null];

        // Row-wise.
        foreach ($rows as $i => $row) {
            if ($row['key'] === null || isset($inRun[$i])) {
                continue;
            }
            $value = $row['rest'];
            if ($value === '' && isset($rows[$i + 1]) && $rows[$i + 1]['key'] === null && ! $rows[$i + 1]['header']
                && ! self::isBareNikLine($rows[$i + 1]['raw'])) {
                $value = ltrim($rows[$i + 1]['raw'], " \t:;.");
            }
            if ($value === '') {
                continue;
            }
            self::assignRowValue($fields, $row['key'], $value);
        }

        // Column-wise: values come from the ":" lines after the first label run.
        if ($runs !== []) {
            $values = [];
            for ($i = $runs[0][1] + 1; $i < $count; $i++) {
                $row = $rows[$i];
                if ($row['key'] !== null || $row['header'] || isset($inRun[$i]) || self::isBareNikLine($row['raw'])) {
                    continue;
                }
                if (preg_match('/^(?:[il1|]\s*)?[:;#.]+\s*(.*)$/u', $row['raw'], $m) === 1) {
                    $v = trim($m[1]);
                    if ($v !== '') {
                        $values[] = $v;
                    }
                }
            }
            self::assignColumnValues($fields, $values);
        }

        // RT/RW with an explicit RT ... RW label pair on one line (not in a table).
        if ($fields['rt'] === null && preg_match('/\bRT\b\s*[:\-]?\s*0*(\d{1,3}).{0,12}\bRW\b\s*[:\-]?\s*0*(\d{1,3})/iu', $text, $m) === 1) {
            $fields['rt'] = (string) (int) $m[1];
            $fields['rw'] = (string) (int) $m[2];
        }

        [$nik, $valid] = self::extractNik($lines);

        return [
            'nik' => $nik,
            'nik_valid' => $valid,
            'name' => $fields['name'],
            'address' => $fields['address'],
            'rt' => $fields['rt'],
            'rw' => $fields['rw'],
            'place_of_birth' => $fields['pob'],
            'gender' => $fields['gender'],
        ];
    }

    /** @return array{0:?string,1:string} label key + text after the label */
    private static function labelOf(string $line): array
    {
        foreach (self::LABELS as $key => $body) {
            if (preg_match('/^(?:'.$body.')\b[ \t]*[:;.\-]?[ \t]*(.*)$/iu', $line, $m) === 1) {
                return [$key, trim($m[1])];
            }
        }

        return [null, ''];
    }

    private static function isBareNikLine(string $line): bool
    {
        return preg_match('/^[:;.\s]*'.self::DIGITISH.'{16}[\s.]*$/u', $line) === 1
            && preg_match_all('/\d/', $line) >= 8;
    }

    /** @param array<string,?string> $fields */
    private static function assignRowValue(array &$fields, string $key, string $value): void
    {
        switch ($key) {
            case 'nama':
                $fields['name'] ??= self::cleanName($value);
                break;
            case 'ttl':
                $fields['pob'] ??= self::cleanPlaceOfBirth($value);
                break;
            case 'gender':
                $fields['gender'] ??= self::cleanGender($value);
                break;
            case 'alamat':
                $fields['address'] ??= self::cleanAddress(
                    preg_replace('/\s+(?:RT\s*\/?\s*RW|RTRW|Kel|Desa|Kec|Kota|Kab|Provinsi)\b.*$/iu', '', $value) ?? $value
                );
                break;
            case 'rtrw':
                if ($fields['rt'] === null && preg_match('/^0*(\d{1,3})\s*[\/\-]\s*0*(\d{1,3})\b/', $value, $m) === 1) {
                    $fields['rt'] = (string) (int) $m[1];
                    $fields['rw'] = (string) (int) $m[2];
                }
                break;
        }
    }

    /**
     * @param  array<string,?string>  $fields
     * @param  list<string>  $values
     */
    private static function assignColumnValues(array &$fields, array $values): void
    {
        if ($values === []) {
            return;
        }

        $birth = null;
        $genderIdx = null;
        $rtIdx = null;
        foreach ($values as $i => $v) {
            if ($birth === null && preg_match(self::BIRTH_DATE, $v) === 1) {
                $birth = $i;
            }
            if ($genderIdx === null && self::cleanGender($v) !== null) {
                $genderIdx = $i;
            }
            if ($rtIdx === null && preg_match('/^0*(\d{1,3})\s*[\/\-]\s*0*(\d{1,3})$/', $v) === 1) {
                $rtIdx = $i;
            }
        }

        if ($birth !== null) {
            $fields['pob'] ??= self::cleanPlaceOfBirth($values[$birth]);
            if ($birth > 0) {
                $fields['name'] ??= self::cleanName($values[$birth - 1]);
            }
        } elseif (! self::looksLikeShape($values[0])) {
            $fields['name'] ??= self::cleanName($values[0]);
        }

        if ($genderIdx !== null) {
            $fields['gender'] ??= self::cleanGender($values[$genderIdx]);
        }

        if ($rtIdx !== null && $fields['rt'] === null) {
            preg_match('/^0*(\d{1,3})\s*[\/\-]\s*0*(\d{1,3})$/', $values[$rtIdx], $m);
            $fields['rt'] = (string) (int) $m[1];
            $fields['rw'] = (string) (int) $m[2];
        }

        $from = $genderIdx !== null ? $genderIdx + 1 : ($birth !== null ? $birth + 1 : null);
        if ($from !== null && $fields['address'] === null) {
            $to = $rtIdx !== null && $rtIdx > $from ? $rtIdx - 1 : $from;
            $parts = [];
            for ($i = $from; $i <= $to && isset($values[$i]); $i++) {
                $part = trim($values[$i]);
                // A lone blood group ("O", "-") belongs to "Gol. Darah", not the address.
                if (mb_strlen($part) <= 2 || self::looksLikeShape($part)) {
                    continue;
                }
                $parts[] = $part;
            }
            if ($parts !== []) {
                $fields['address'] = self::cleanAddress(implode(' ', $parts));
            }
        }
    }

    private static function looksLikeShape(string $v): bool
    {
        return preg_match(self::BIRTH_DATE, $v) === 1
            || self::cleanGender($v) !== null
            || preg_match('/^0*\d{1,3}\s*[\/\-]\s*0*\d{1,3}$/', $v) === 1;
    }

    /** Name from a raw value, or null when it is not a plausible person name. */
    public static function cleanName(string $raw): ?string
    {
        $name = preg_replace('/\s+(?:TEMPAT|TGL|JENIS|ALAMAT|GOL|STATUS|AGAMA|PEKERJAAN|NIK)\b.*$/u', '', trim($raw)) ?? '';
        $name = preg_replace('/\s+/', ' ', $name) ?? '';
        $name = trim($name, " \t-'|_,:;");
        if ($name === '' || preg_match('/^[A-Z][A-Z .\'\-]*$/', $name) !== 1) {
            return null;
        }

        return self::wordsLookLikeValue($name, 3, 60, 6) ? $name : null;
    }

    private static function cleanAddress(string $raw): ?string
    {
        $addr = preg_replace('/\s+/', ' ', trim($raw)) ?? '';
        $addr = trim($addr, " \t-'|_,:;");
        if ($addr === '' || preg_match('/^[A-Z0-9][A-Z0-9 .\/,\-\']{2,119}$/', $addr) !== 1) {
            return null;
        }

        return self::wordsLookLikeValue($addr, 3, 120, 25) ? $addr : null;
    }

    private static function cleanPlaceOfBirth(string $raw): ?string
    {
        $v = preg_replace('/\s+(?:Jenis|Gol|Agama|Status|Pekerjaan|Kewarganegaraan|Berlaku)\b.*$/iu', '', trim($raw)) ?? '';
        $v = trim($v, " \t-'|_,:;");
        if ($v === '' || preg_match('/^[A-Z][A-Z0-9 .\/,\-]{1,60}$/', $v) !== 1) {
            return null;
        }

        return self::wordsLookLikeValue($v, 2, 70, 10) ? $v : null;
    }

    private static function cleanGender(string $raw): ?string
    {
        if (preg_match('/^(LAKI[\s\-]*LAKI|LAKI|PEREMPUAN)\b/iu', trim($raw), $m) !== 1) {
            return null;
        }

        return strtoupper((string) preg_replace('/\s+/', '-', $m[1])) ?: null;
    }

    /** Length/word-count limits plus: first word not a label word, under half of the words are label words. */
    private static function wordsLookLikeValue(string $value, int $minLetters, int $maxLength, int $maxWords): bool
    {
        $letters = preg_match_all('/[A-Z]/', $value);
        if ($letters < $minLetters || strlen($value) > $maxLength) {
            return false;
        }
        $words = array_values(array_filter(preg_split('/[\s\/,]+/', $value) ?: [], static fn (string $w): bool => $w !== ''));
        if ($words === [] || count($words) > $maxWords) {
            return false;
        }
        $labelCount = 0;
        foreach ($words as $w) {
            if (self::isLabelWord($w)) {
                $labelCount++;
            }
        }
        if (self::isLabelWord($words[0])) {
            return false;
        }

        // "NURUL ISLAM" is a real name: a label word may be up to half the words.
        // The first-word rule above still rejects pure labels ("ISLAM", "TEMPAT TGL").
        return $labelCount * 2 <= count($words);
    }

    public static function isLabelWord(string $word): bool
    {
        $w = strtoupper(preg_replace('/[^A-Za-z]/', '', $word) ?? '');

        return $w !== '' && in_array($w, self::LABEL_WORDS, true);
    }

    /**
     * @param  list<string>  $lines
     * @return array{0:?string,1:bool} NIK (best candidate, may be invalid) and whether it passes isValidNik()
     */
    private static function extractNik(array $lines): array
    {
        $candidates = [];
        $d = self::DIGITISH;

        // 1. value after the NIK label (same line).
        foreach ($lines as $line) {
            if (preg_match('/^N[I1lL!|]K\b[ \t]*[:;.\-]?[ \t]*(.*)$/iu', $line, $m) === 1) {
                $run = self::digitRun($m[1]);
                if ($run !== null) {
                    $candidates[] = $run;
                }
            }
        }
        // 2. a standalone 16-digit line (column-wise output).
        foreach ($lines as $line) {
            if (self::isBareNikLine($line)) {
                $candidates[] = self::flatten(preg_replace('/[^0-9OoIl|]/', '', $line) ?? '');
            }
        }
        // 3. any long digit run in the text.
        foreach ($lines as $line) {
            if (preg_match_all('/(?<![A-Za-z0-9])'.$d.'(?:[ .\-]?'.$d.'){15,19}(?![A-Za-z0-9])/u', $line, $mm) > 0) {
                foreach ($mm[0] as $chunk) {
                    $run = self::digitRun($chunk);
                    if ($run !== null) {
                        $candidates[] = $run;
                    }
                }
            }
        }

        if ($candidates === []) {
            return [null, false];
        }
        foreach ($candidates as $cand) {
            if (self::isValidNik($cand)) {
                return [$cand, true];
            }
        }

        return [$candidates[0], false];
    }

    /** First 16 digits of the first digit-like run (needs 8+ real digits, so label words never qualify), or null. */
    private static function digitRun(string $s): ?string
    {
        $d = self::DIGITISH;
        if (preg_match('/'.$d.'(?:[ .\-]?'.$d.'){13,}/u', $s, $m) !== 1) {
            return null;
        }
        if (preg_match_all('/\d/', $m[0]) < 8) {
            return null;
        }
        $digits = self::flatten(preg_replace('/[^0-9OoIl|]/', '', $m[0]) ?? '');

        return strlen($digits) >= 16 ? substr($digits, 0, 16) : null;
    }

    /** O/o -> 0 and I/l/| -> 1, only applied to a string already reduced to digit-like characters. */
    private static function flatten(string $s): string
    {
        return str_replace(['O', 'o', 'I', 'l', '|'], ['0', '0', '1', '1', '1'], $s);
    }

    public static function flattenDigits(string $s): string
    {
        return self::flatten($s);
    }

    /** Province code, real birth date (female day + 40) and serial number are all plausible. */
    public static function isValidNik(string $nik): bool
    {
        if (preg_match('/^\d{16}$/', $nik) !== 1 || preg_match('/^(\d)\1{15}$/', $nik) === 1) {
            return false;
        }
        $province = (int) substr($nik, 0, 2);
        $okProvince = ($province >= 11 && $province <= 19) || $province === 21
            || ($province >= 31 && $province <= 36) || ($province >= 51 && $province <= 53)
            || ($province >= 61 && $province <= 65) || ($province >= 71 && $province <= 76)
            || $province === 81 || $province === 82 || ($province >= 91 && $province <= 97);
        if (! $okProvince) {
            return false;
        }

        $day = (int) substr($nik, 6, 2);
        $month = (int) substr($nik, 8, 2);
        if ($day > 40) {
            $day -= 40;
        }
        if ($month < 1 || $month > 12 || $day < 1) {
            return false;
        }
        $maxDay = [1 => 31, 2 => 29, 3 => 31, 4 => 30, 5 => 31, 6 => 30, 7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31][$month];
        if ($day > $maxDay) {
            return false;
        }

        return substr($nik, 12, 4) !== '0000';
    }
}
