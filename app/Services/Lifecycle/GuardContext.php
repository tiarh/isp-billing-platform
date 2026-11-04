<?php

namespace App\Services\Lifecycle;

/**
 * Who asks and why. Automated callers (jobs, commands, importers) can never
 * use an override: authorizeOverride() refuses them.
 */
final class GuardContext
{
    public function __construct(
        public readonly string $source,
        public readonly bool $automated = true,
        /** When another customer already uses this customer's ONU (takeover), the ONU signal does not apply. */
        public readonly ?int $onuReusedByCustomerId = null,
    ) {}

    public static function automated(string $source, ?int $onuReusedByCustomerId = null): self
    {
        return new self($source, true, $onuReusedByCustomerId);
    }

    public static function manual(string $source, ?int $onuReusedByCustomerId = null): self
    {
        return new self($source, false, $onuReusedByCustomerId);
    }
}
