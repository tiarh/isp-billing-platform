import { cn } from '@/lib/utils';

interface SkeletonCardProps {
    className?: string;
}

export function SkeletonCard({ className }: SkeletonCardProps) {
    return (
        <div className={cn('rounded-xl border border-border p-4 sm:p-5', className)} role="status" aria-label="Memuat kartu">
            <div className="h-3 w-24 rounded bg-muted" />
            <div className="mt-3 h-8 w-32 rounded bg-muted" />
            <div className="mt-2 h-3 w-40 rounded bg-muted" />
        </div>
    );
}
