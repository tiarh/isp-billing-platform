<?php

namespace App\Services;

/**
 * Raw host CPU readings for the monitoring admission gate.
 *
 * Kept as a tiny injectable seam so the gate's steal-aware decision can be
 * tested with fake /proc contents. Every reader returns null when the source
 * is unavailable (non-Linux, tests, kernels without PSI), and the gate then
 * falls back to the legacy load-average rule.
 */
class HostCpuProbe
{
    /** @return list<float>|null [load1, load5, load15] */
    public function loadAverage(): ?array
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        return is_array($load) && isset($load[0]) ? array_map('floatval', array_values($load)) : null;
    }

    /** Contents of /proc/stat, or null when unreadable. */
    public function procStat(): ?string
    {
        return $this->read('/proc/stat');
    }

    /**
     * Aggregate `cpu ` line of /proc/stat (user..steal; guest is already inside
     * user), or null when unreadable. VM-wide, same source as sar %steal.
     *
     * @return array{user: int, nice: int, system: int, idle: int, iowait: int, irq: int, softirq: int, steal: int}|null
     */
    public function cpuCounters(): ?array
    {
        $contents = $this->procStat();
        if ($contents === null || preg_match('/^cpu\s+(\d+(?:\s+\d+){3,})/m', $contents, $match) !== 1) {
            return null;
        }

        $fields = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
        $counters = [];
        foreach (['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'] as $index => $name) {
            $counters[$name] = $fields[$index] ?? 0;
        }

        return $counters;
    }

    /** Contents of /proc/pressure/cpu, or null when PSI is unavailable. */
    public function pressureCpu(): ?string
    {
        return $this->read('/proc/pressure/cpu');
    }

    private function read(string $path): ?string
    {
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }
}
