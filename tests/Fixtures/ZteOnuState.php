<?php

namespace Tests\Fixtures;

/**
 * ZTE C300 (OLT RA) live-probe fixtures. `sample()` returns a VERBATIM block of
 * the real captured output (zte_c300_olt_ra_samples.txt); `probe()` builds a
 * liveOnuProbe() result for fakes by templating those real samples.
 */
final class ZteOnuState
{
    /**
     * @return string the raw output captured for $command
     */
    public static function sample(string $command, string $file = 'zte_c300_olt_ra_samples.txt'): string
    {
        $text = (string) file_get_contents(__DIR__.'/'.$file);
        $blocks = preg_split('/^=== (.+?) ===\R/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        for ($i = 0; $i + 1 < count($blocks); $i += 2) {
            if (trim($blocks[$i]) === $command) {
                return $blocks[$i + 1];
            }
        }

        throw new \RuntimeException("sample not found: {$command}");
    }

    /**
     * Real working sample (1/5/5:3, FHTTC0000A01) re-targeted to $ref / $phase /
     * $serial. $phase null = ONU not registered (no ONU interface, port table
     * lists other ONUs only).
     *
     * @return array{ref: string, detail: string, port: string}
     */
    public static function probe(string $ref, ?string $phase, ?string $serial): array
    {
        $port = substr($ref, 0, (int) strpos($ref, ':'));
        $portCommand = "show gpon onu state gpon-olt_{$port}";
        $rows = '';
        $table = "OnuIndex   Admin State  OMCC State  Phase State  Channel    \n"
            ."--------------------------------------------------------------\n";

        if ($phase === null) {
            $detail = "show gpon onu detail-info gpon-onu_{$ref}\n\nshow gpon onu detail-info gpon-onu_{$ref}\n"
                ."                          ^\n%Error 20202: Invalid input detected at '^' marker.Invalid parameter\nSKYNET\n";
        } else {
            $detail = self::sample('show gpon onu detail-info gpon-onu_1/5/5:3');
            $detail = str_replace('gpon-onu_1/5/5:3', 'gpon-onu_'.$ref, $detail);
            $detail = str_replace('Phase state:         working', 'Phase state:         '.$phase, $detail);
            $detail = str_replace('FHTTC0000A01', (string) $serial, $detail);
            $rows = sprintf("%-11s enable       enable      %-12s 1(GPON)\n", $ref, $phase);
        }

        // An unrelated neighbour row, like the real port table.
        $rows .= sprintf("%-11s enable       enable      working      1(GPON)\n", $port.':99');

        $count = $phase === null ? 1 : 2;

        return ['ref' => $ref, 'detail' => $detail, 'port' => $portCommand."\n".$table.$rows."ONU Number: 1/{$count}\n"];
    }
}
