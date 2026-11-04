import { useCallback, useState } from 'react';
import { cn } from '@/lib/utils';
import { MONITORING_RANGES, type MonitoringRange } from './types';

const STORAGE_KEY = 'monitoring:range';
const DEFAULT_RANGE: MonitoringRange = '1h';

function isRange(value: unknown): value is MonitoringRange {
    return MONITORING_RANGES.some((range) => range.value === value);
}

/** Range persisted per browser; falls back to 1h when storage is unavailable. */
export function useStoredRange(): [MonitoringRange, (range: MonitoringRange) => void] {
    const [range, setRange] = useState<MonitoringRange>(() => {
        try {
            const saved = window.localStorage.getItem(STORAGE_KEY);

            return isRange(saved) ? saved : DEFAULT_RANGE;
        } catch {
            return DEFAULT_RANGE;
        }
    });

    const update = useCallback((next: MonitoringRange) => {
        setRange(next);
        try {
            window.localStorage.setItem(STORAGE_KEY, next);
        } catch {
            /* storage unavailable: the choice just lasts for this page view */
        }
    }, []);

    return [range, update];
}

interface RangeSelectorProps {
    value: MonitoringRange;
    onChange: (range: MonitoringRange) => void;
    className?: string;
}

export function RangeSelector({ value, onChange, className }: RangeSelectorProps) {
    return (
        <div
            role="radiogroup"
            aria-label="Rentang waktu"
            className={cn('inline-flex items-center gap-0.5 rounded-[var(--radius-control)] border border-border bg-card p-0.5', className)}
        >
            {MONITORING_RANGES.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        aria-label={option.hint}
                        title={option.hint}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'h-8 min-w-10 rounded-[calc(var(--radius-control)-2px)] px-2.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            active ? 'bg-primary-soft text-primary-soft-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}
