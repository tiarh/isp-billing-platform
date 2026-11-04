import { cn } from '@/lib/utils';

interface SkeletonTableProps {
    rows?: number;
    columns?: number;
    className?: string;
}

export function SkeletonTable({ rows = 5, columns = 4, className }: SkeletonTableProps) {
    return (
        <div className={cn('overflow-hidden rounded-xl border border-border', className)} aria-label="Memuat tabel" role="status">
            <div className="grid gap-4 border-b border-border bg-muted/50 px-4 py-3" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
                {Array.from({ length: columns }, (_, index) => <div key={`head-${index}`} className="h-3 rounded bg-muted-foreground/20" />)}
            </div>
            {Array.from({ length: rows }, (_, row) => (
                <div key={`row-${row}`} className="grid gap-4 border-b border-border px-4 py-4 last:border-b-0" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))` }}>
                    {Array.from({ length: columns }, (_, column) => (
                        <div key={`cell-${row}-${column}`} className={cn('h-4 rounded bg-muted', column === 0 && 'max-w-32')} />
                    ))}
                </div>
            ))}
        </div>
    );
}
