<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pure comparison of two column snapshots (prod vs app/test schema). Read-only.
 *
 * A snapshot is a list of rows shaped like prod-columns.json entries:
 * ['t' => table, 'c' => column, 'type' => COLUMN_TYPE, 'nul' => 'YES'|'NO',
 *  'def' => COLUMN_DEFAULT|null, 'extra' => EXTRA].
 */
class SchemaDriftAnalyzer
{
    public const KIND_ON_UPDATE = 'on_update';

    public const KIND_NULLABILITY = 'nullability';

    public const KIND_TYPE_FAMILY = 'type_family';

    /**
     * Read the current connection's columns in the snapshot row shape (MySQL only).
     *
     * @return list<array{t:string,c:string,type:string,nul:string,def:?string,extra:string}>
     */
    public static function readCurrentSchema(): array
    {
        $rows = DB::select(
            'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS type, IS_NULLABLE AS nul, '
            .'COLUMN_DEFAULT AS def, EXTRA AS extra '
            .'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            .'ORDER BY TABLE_NAME, ORDINAL_POSITION'
        );

        return array_map(static fn ($r): array => [
            't' => (string) $r->t,
            'c' => (string) $r->c,
            'type' => (string) $r->type,
            'nul' => (string) $r->nul,
            'def' => $r->def === null ? null : (string) $r->def,
            'extra' => (string) $r->extra,
        ], $rows);
    }

    /**
     * @param  list<array<string,mixed>>  $prod
     * @param  list<array<string,mixed>>  $app
     * @return array{
     *   tables_only_prod: list<string>,
     *   tables_only_app: list<string>,
     *   columns_only_prod: list<string>,
     *   columns_only_app: list<string>,
     *   differences: list<array{key:string,field:string,prod:?string,app:?string}>,
     *   dangerous: list<array{key:string,kind:string,detail:string}>,
     *   counts: array<string,int>
     * }
     */
    public static function compare(array $prod, array $app): array
    {
        $p = self::index($prod);
        $a = self::index($app);

        $prodTables = array_values(array_unique(array_column($prod, 't')));
        $appTables = array_values(array_unique(array_column($app, 't')));
        sort($prodTables);
        sort($appTables);
        $tablesOnlyProd = array_values(array_diff($prodTables, $appTables));
        $tablesOnlyApp = array_values(array_diff($appTables, $prodTables));
        $common = array_flip(array_intersect($prodTables, $appTables));

        $columnsOnlyProd = [];
        $columnsOnlyApp = [];
        $differences = [];
        $dangerous = [];

        foreach ($p as $key => $pc) {
            if (! isset($common[$pc['t']])) {
                continue;
            }
            if (! isset($a[$key])) {
                $columnsOnlyProd[] = $key;

                continue;
            }
            $ac = $a[$key];

            if ($pc['type'] !== $ac['type']) {
                $differences[] = ['key' => $key, 'field' => 'type', 'prod' => $pc['type'], 'app' => $ac['type']];
                if (self::typeFamily($pc['type']) !== self::typeFamily($ac['type'])) {
                    $dangerous[] = [
                        'key' => $key.'|'.self::KIND_TYPE_FAMILY,
                        'kind' => self::KIND_TYPE_FAMILY,
                        'detail' => "prod {$pc['type']} vs app {$ac['type']}",
                    ];
                }
            }
            if ($pc['nul'] !== $ac['nul']) {
                $differences[] = ['key' => $key, 'field' => 'nullable', 'prod' => $pc['nul'], 'app' => $ac['nul']];
                $dangerous[] = [
                    'key' => $key.'|'.self::KIND_NULLABILITY,
                    'kind' => self::KIND_NULLABILITY,
                    'detail' => "prod nullable={$pc['nul']} vs app nullable={$ac['nul']}",
                ];
            }
            if ($pc['def'] !== $ac['def']) {
                $differences[] = ['key' => $key, 'field' => 'default', 'prod' => $pc['def'], 'app' => $ac['def']];
            }
            if ($pc['extra'] !== $ac['extra']) {
                $differences[] = ['key' => $key, 'field' => 'extra', 'prod' => $pc['extra'], 'app' => $ac['extra']];
                if (self::onUpdate($pc['extra']) !== self::onUpdate($ac['extra'])) {
                    $dangerous[] = [
                        'key' => $key.'|'.self::KIND_ON_UPDATE,
                        'kind' => self::KIND_ON_UPDATE,
                        'detail' => 'prod "'.($pc['extra'] === '' ? '-' : $pc['extra']).'" vs app "'.($ac['extra'] === '' ? '-' : $ac['extra']).'"',
                    ];
                }
            }
        }

        foreach ($a as $key => $ac) {
            if (isset($common[$ac['t']]) && ! isset($p[$key])) {
                $columnsOnlyApp[] = $key;
            }
        }

        return [
            'tables_only_prod' => $tablesOnlyProd,
            'tables_only_app' => $tablesOnlyApp,
            'columns_only_prod' => $columnsOnlyProd,
            'columns_only_app' => $columnsOnlyApp,
            'differences' => $differences,
            'dangerous' => $dangerous,
            'counts' => [
                'tables_only_prod' => count($tablesOnlyProd),
                'tables_only_app' => count($tablesOnlyApp),
                'columns_only_prod' => count($columnsOnlyProd),
                'columns_only_app' => count($columnsOnlyApp),
                'differences' => count($differences),
                'dangerous' => count($dangerous),
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,array{t:string,c:string,type:string,nul:string,def:?string,extra:string}>
     */
    private static function index(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $row = [
                't' => (string) $r['t'],
                'c' => (string) $r['c'],
                'type' => strtolower(trim((string) ($r['type'] ?? ''))),
                'nul' => strtoupper((string) ($r['nul'] ?? 'YES')),
                'def' => self::normaliseDefault($r['def'] ?? null),
                'extra' => self::normaliseExtra((string) ($r['extra'] ?? '')),
            ];
            $out[$row['t'].'.'.$row['c']] = $row;
        }

        return $out;
    }

    private static function normaliseDefault(mixed $def): ?string
    {
        if ($def === null) {
            return null;
        }
        $def = trim((string) $def);
        // MySQL 8 reports CURRENT_TIMESTAMP, MariaDB current_timestamp(); also fsp variants.
        if (preg_match('/^current_timestamp(\(\d*\))?$/i', $def) === 1) {
            return 'CURRENT_TIMESTAMP';
        }

        return $def;
    }

    /** Drop the DEFAULT_GENERATED marker (it only mirrors an expression default) and lowercase. */
    private static function normaliseExtra(string $extra): string
    {
        $extra = strtolower(trim($extra));
        $extra = trim((string) preg_replace('/\bdefault_generated\b/', '', $extra));
        $extra = (string) preg_replace('/current_timestamp(\(\d*\))?/', 'current_timestamp', $extra);

        return trim((string) preg_replace('/\s+/', ' ', $extra));
    }

    private static function onUpdate(string $normalisedExtra): bool
    {
        return str_contains($normalisedExtra, 'on update');
    }

    /** Coarse type family: int vs bigint share a family, timestamp vs datetime do not. */
    public static function typeFamily(string $type): string
    {
        $type = strtolower(trim($type));
        $base = (string) preg_replace('/[\s(].*$/', '', $type);

        return match ($base) {
            'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint' => 'integer',
            'varchar', 'char' => 'char',
            'tinytext', 'text', 'mediumtext', 'longtext' => 'text',
            'tinyblob', 'blob', 'mediumblob', 'longblob', 'binary', 'varbinary' => 'binary',
            'decimal', 'numeric' => 'decimal',
            'float', 'double', 'real' => 'float',
            default => $base,
        };
    }
}
