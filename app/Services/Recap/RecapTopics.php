<?php

namespace App\Services\Recap;

use App\Models\Setting;
use App\Services\Report\ReportGroupRegistry;

/**
 * Router of the "#rekap <topic>" commands that are NOT "#rekap psb" (that one keeps its own
 * parser / job). Pure lookups: no I/O except Setting reads in enabled(). Topics: 'onu', 'migrasi'.
 */
class RecapTopics
{
    public const ONU = 'onu';

    public const MIGRASI = 'migrasi';

    /** topic => command flag */
    public const FLAGS = [
        self::ONU => 'recap_onu_cmd_enabled',
        self::MIGRASI => 'recap_migrasi_cmd_enabled',
    ];

    public const CONTEXT_PSB = 'psb';

    public const CONTEXT_REPORT = 'report';

    /** @return list<string> */
    public static function topics(): array
    {
        return array_keys(self::FLAGS);
    }

    public static function parser(string $topic): ?RecapCommandParser
    {
        return match ($topic) {
            self::ONU => new OnuRecapCommandParser,
            self::MIGRASI => new MigrasiRecapCommandParser,
            default => null,
        };
    }

    /** The topic of a whole-message "#rekap <topic> ..." command, or null. Never throws. */
    public static function matchTopic(string $text): ?string
    {
        try {
            foreach (self::topics() as $topic) {
                $parser = self::parser($topic);
                if ($parser !== null && $parser->matches($text)) {
                    return $topic;
                }
            }
        } catch (\Throwable) {
            // fall through
        }

        return null;
    }

    /** Topic command flag (and, in the Report group, the Report command flag). */
    public static function enabled(string $topic, string $context = self::CONTEXT_PSB): bool
    {
        $flag = self::FLAGS[$topic] ?? null;
        if ($flag === null) {
            return false;
        }
        if ($context === self::CONTEXT_REPORT) {
            return app(ReportGroupRegistry::class)->topicCommandEnabled($topic);
        }

        return in_array(Setting::get($flag, 0), [1, '1', true, 'true', 'on', 'yes'], true);
    }
}
