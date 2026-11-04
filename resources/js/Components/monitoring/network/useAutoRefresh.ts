import { useCallback, useEffect, useState } from 'react';

/**
 * Returns a counter that increments every `intervalMs` while the page is
 * visible, and once more as soon as a hidden tab becomes visible again.
 * Widget hooks refetch whenever the counter changes.
 */
export function useAutoRefresh(intervalMs = 60_000, enabled = true): { tick: number; refreshNow: () => void } {
    const [tick, setTick] = useState(0);

    const refreshNow = useCallback(() => setTick((value) => value + 1), []);

    useEffect(() => {
        if (!enabled) {
            return undefined;
        }

        let timer: ReturnType<typeof window.setTimeout> | undefined;
        let cancelled = false;
        let lastTickAt = Date.now();
        const minGapMs = Math.min(30_000, intervalMs);

        const schedule = (delay = intervalMs) => {
            timer = window.setTimeout(run, delay);
        };

        const tickNow = () => {
            lastTickAt = Date.now();
            setTick((value) => value + 1);
        };

        const run = () => {
            if (cancelled) {
                return;
            }
            if (document.visibilityState !== 'hidden') {
                tickNow();
            }
            schedule();
        };

        const onVisibilityChange = () => {
            if (document.visibilityState !== 'visible' || cancelled) {
                return;
            }
            if (timer) {
                window.clearTimeout(timer);
            }
            // Tab flipping must not burn the 60/min throttle: only refetch when
            // the data is at least minGapMs old, else wait out the remainder.
            const sinceLast = Date.now() - lastTickAt;
            if (sinceLast >= minGapMs) {
                tickNow();
                schedule();
            } else {
                schedule(Math.max(minGapMs - sinceLast, 0));
            }
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();

        return () => {
            cancelled = true;
            document.removeEventListener('visibilitychange', onVisibilityChange);
            if (timer) {
                window.clearTimeout(timer);
            }
        };
    }, [intervalMs, enabled]);

    return { tick, refreshNow };
}
