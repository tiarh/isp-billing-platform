import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CloudOff, Settings2 } from 'lucide-react';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Skeleton } from '@/Components/ui/skeleton';
import { cn } from '@/lib/utils';
import { formatClock } from './format';
import type { WidgetEnvelope, WidgetError, WidgetState } from './types';

const ERROR_TEXT: Record<Exclude<WidgetError, null>, string> = {
    forbidden: 'Akses ke data Grafana ditolak untuk akun ini.',
    throttled: 'Terlalu banyak permintaan; menampilkan data terakhir.',
    server: 'Gagal memuat pembaruan; menampilkan data terakhir.',
    network: 'Koneksi terputus; menampilkan data terakhir.',
};

interface WidgetFrameProps<T> {
    title: string;
    description?: string;
    state: WidgetState<T>;
    children: (data: T, envelope: WidgetEnvelope<T>) => ReactNode;
    /** Widgets backed by DB rows (summary, routers) still render when Grafana is down. */
    renderOnDegraded?: boolean;
    /** The tab shows one shared setup banner, so this frame stays quiet for setup_required. */
    suppressSetup?: boolean;
    /** A page-level "Grafana down" banner is shown, so an unavailable frame collapses to one line. */
    compactUnavailable?: boolean;
    /** Link target for the setup CTA; null hides it (not a global admin). */
    settingsHref?: string | null;
    actions?: ReactNode;
    className?: string;
    /** Skeleton height, so the layout does not jump. */
    skeletonClassName?: string;
    /** Render without the Card chrome (e.g. KPI strip). */
    bare?: boolean;
    /** Overrides the 403 copy for widgets that are not Grafana-backed. */
    forbiddenText?: string;
}

export function WidgetFrame<T>({
    title,
    description,
    state,
    children,
    renderOnDegraded = false,
    suppressSetup = false,
    compactUnavailable = false,
    settingsHref = null,
    actions,
    className,
    skeletonClassName = 'h-56',
    bare = false,
    forbiddenText,
}: WidgetFrameProps<T>) {
    const { envelope, lastGood, loading, error } = state;
    const errorText = (value: Exclude<WidgetError, null>): string => (value === 'forbidden' && forbiddenText ? forbiddenText : ERROR_TEXT[value]);

    let body: ReactNode;
    let badge: ReactNode = null;

    if (!envelope) {
        body = error ? (
            <EmptyState icon={CloudOff} title="Data belum bisa dimuat" description={errorText(error)} />
        ) : (
            <Skeleton className={cn('w-full', skeletonClassName)} aria-hidden />
        );
    } else if (envelope.status === 'setup_required' && !renderOnDegraded) {
        body = suppressSetup ? null : (
            <EmptyState
                icon={Settings2}
                title="Belum dikonfigurasi"
                description={envelope.reason ?? 'Lengkapi pengaturan Monitoring agar panel ini terisi.'}
                action={
                    settingsHref ? (
                        <Button asChild size="sm" variant="outline">
                            <Link href={settingsHref}>Buka Pengaturan</Link>
                        </Button>
                    ) : undefined
                }
            />
        );
    } else if (envelope.status === 'unavailable' && !renderOnDegraded) {
        if (lastGood) {
            badge = <StatusBadge tone="degraded" label={`Data terakhir ${formatClock(state.lastSuccessAt)}`} title={envelope.reason ?? undefined} />;
            body = children(lastGood.data, lastGood);
        } else if (compactUnavailable) {
            return (
                <p role="status" className="rounded-lg border border-dashed border-border px-4 py-3 text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">{title}</span> tidak tersedia selama Grafana tidak dapat dijangkau.
                </p>
            );
        } else {
            body = (
                <EmptyState
                    icon={CloudOff}
                    title="Grafana tidak dapat dijangkau"
                    description={envelope.reason ?? 'Belum ada data yang berhasil dimuat. Coba lagi sebentar.'}
                />
            );
        }
    } else {
        if (envelope.status === 'stale') {
            badge = <StatusBadge tone="degraded" label="Data tertunda" title={envelope.reason ?? undefined} />;
        }
        body = children(envelope.data, envelope);
    }

    if (body === null) {
        return null;
    }

    const errorNote =
        error && envelope ? (
            <p role="status" className="mb-3 text-xs text-status-degraded">
                {errorText(error)}
            </p>
        ) : null;

    const content = (
        <div aria-busy={loading} className={cn('transition-opacity', loading && envelope ? 'opacity-70' : 'opacity-100')}>
            {errorNote}
            {bare && badge && <div className="mb-3 flex justify-end">{badge}</div>}
            {body}
        </div>
    );

    if (bare) {
        return <section aria-label={title} className={className}>{content}</section>;
    }

    return (
        <Card className={className}>
            <CardHeader className="flex-row items-start justify-between gap-3 space-y-0 p-5 pb-3">
                <div className="min-w-0 space-y-1">
                    <CardTitle>{title}</CardTitle>
                    {description && <p className="text-xs text-muted-foreground">{description}</p>}
                </div>
                <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
                    {badge}
                    {actions}
                </div>
            </CardHeader>
            <CardContent className="p-5 pt-2">{content}</CardContent>
        </Card>
    );
}
