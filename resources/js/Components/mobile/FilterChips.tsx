import { cn } from '@/lib/utils';

export interface FilterChipOption {
    value: string;
    label: string;
    count?: number;
}

interface FilterChipsProps {
    label: string;
    options: FilterChipOption[];
    value: string;
    onChange: (value: string) => void;
}

export function FilterChips({ label, options, value, onChange }: FilterChipsProps) {
    return (
        <div role="group" aria-label={label} className="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]">
            {options.map((option) => {
                const active = option.value === value;
                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-3 text-[12.5px] font-semibold transition-colors',
                            active ? 'bg-primary text-primary-foreground' : 'border bg-card text-foreground',
                        )}
                    >
                        {option.label}
                        {option.count !== undefined && <span className="font-data opacity-80">{option.count}</span>}
                    </button>
                );
            })}
        </div>
    );
}
