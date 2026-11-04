import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface PollOptions {
    /** Polling only runs while true (e.g. "a sync is queued/running"). Default true. */
    enabled?: boolean;
    /** Stop after this many ms of continuous polling; `stopped` becomes true. */
    maxMs?: number;
    /** Multiply the interval after every tick (1 = fixed). Default 1. */
    backoff?: number;
    /** Upper bound for the interval when backing off. Default = intervalMs. */
    maxIntervalMs?: number;
    /** Run one tick right away when polling (re)starts. Default false. */
    immediate?: boolean;
}

export interface PollState {
    /** True when maxMs elapsed; call restart() (e.g. from a button) to resume. */
    stopped: boolean;
    restart: () => void;
}

/**
 * Shared polling hook (audit R1/R6, PR-UX4).
 *
 * - Sequential: the next tick is scheduled only after the previous `tick`
 *   settles, so slow requests never stack up.
 * - Pauses while the tab is hidden and runs once as soon as it is visible
 *   again (same pattern as Monitoring/Index).
 * - Optional backoff (e.g. 3 s → 15 s) and a hard stop (`maxMs`) so a status
 *   that is stuck on "running" can no longer poll forever.
 */
export function usePollWhileVisible(
    tick: () => unknown | Promise<unknown>,
    intervalMs: number,
    { enabled = true, maxMs, backoff = 1, maxIntervalMs, immediate = false }: PollOptions = {},
): PollState {
    const tickRef = useRef(tick);
    tickRef.current = tick;

    const [stopped, setStopped] = useState(false);
    const [generation, setGeneration] = useState(0);

    const restart = useCallback(() => {
        setStopped(false);
        setGeneration((value) => value + 1);
    }, []);

    useEffect(() => {
        if (!enabled) {
            setStopped(false);
            return;
        }

        let cancelled = false;
        let timer: number | undefined;
        let inFlight = false;
        let currentInterval = intervalMs;
        const ceiling = Math.max(intervalMs, maxIntervalMs ?? intervalMs);
        const startedAt = Date.now();

        const expired = () => maxMs !== undefined && Date.now() - startedAt >= maxMs;

        const schedule = () => {
            if (cancelled) return;
            if (timer !== undefined) window.clearTimeout(timer);
            timer = window.setTimeout(run, currentInterval);
        };

        const run = async () => {
            if (cancelled) return;
            if (expired()) {
                setStopped(true);
                return;
            }
            if (document.visibilityState === 'hidden' || inFlight) {
                schedule();
                return;
            }

            inFlight = true;
            try {
                await tickRef.current();
            } catch {
                // A failed tick must not kill the loop; the next tick retries.
            } finally {
                inFlight = false;
            }

            currentInterval = Math.min(ceiling, Math.round(currentInterval * Math.max(1, backoff)));
            schedule();
        };

        const onVisibilityChange = () => {
            if (document.visibilityState === 'visible' && !cancelled && !inFlight) {
                if (timer !== undefined) window.clearTimeout(timer);
                void run();
            }
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        // A manual restart() refreshes right away instead of waiting a full interval.
        if (immediate || generation > 0) {
            void run();
        } else {
            schedule();
        }

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', onVisibilityChange);
            if (timer !== undefined) window.clearTimeout(timer);
        };
    }, [enabled, intervalMs, maxMs, backoff, maxIntervalMs, immediate, generation]);

    return { stopped, restart };
}

/** Inertia partial reload as a promise, for use as a `usePollWhileVisible` tick. */
export function reloadOnly(only: string[]): Promise<void> {
    return new Promise((resolve) => {
        router.reload({ only, onFinish: () => resolve() });
    });
}
