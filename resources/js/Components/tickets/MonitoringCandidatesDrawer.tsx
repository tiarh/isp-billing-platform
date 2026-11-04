import { Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, ShieldCheck } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Skeleton } from '@/Components/ui/skeleton';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/StatusBadge';
import { cn } from '@/lib/utils';

export interface MonitoringCandidate {
    id: number;
    dedupe_key: string;
    issue: string;
    issue_label: string;
    severity: string;
    customer_name: string | null;
    customer_code: string | null;
    area: string | null;
    odp: string | null;
    pon_port: string | null;
    onu_rx_power_dbm: string | number | null;
    last_seen_at: string | null;
    existing_ticket_id: number | null;
}

export interface MassOutageDraft {
    group_key: string;
    issue: string;
    issue_label: string;
    severity: string;
    count: number;
    odp: string | null;
    pon: string | null;
    last_seen_at: string | null;
    sample_customers: string[];
}

export interface MonitoringSummary {
    total: number;
    by_issue: Record<string, number>;
    massal: number;
    ticketed: number;
    stale: boolean;
}

export interface MonitoringEvidence {
    candidates: MonitoringCandidate[];
    mass_outage_drafts: MassOutageDraft[];
    summary: MonitoringSummary;
    stale: boolean;
    stale_minutes: number;
    filters: {
        monitoring_status: string;
        monitoring_source: string;
        monitoring_issue: string;
        monitoring_severity: string;
        monitoring_area: string;
        monitoring_show_ticketed: boolean;
    };
}

interface Props {
    summary?: MonitoringSummary;
    evidence?: MonitoringEvidence;
    canCreateDraft: boolean;
}

const ISSUE_PON = 'pon_pppoe_mismatch';
const ISSUE_SIGNAL = 'bad_signal_online';

function severityBadge(severity: string) {
    return <StatusBadge label={severity === 'critical' ? 'Critical' : severity === 'warning' ? 'Warning' : 'Info'} tone={severity === 'critical' ? 'down' : severity === 'warning' ? 'degraded' : 'info'} />;
}

export function MonitoringCandidatesDrawer({ summary, evidence, canCreateDraft }: Props) {
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [issue, setIssue] = useState(ISSUE_PON);
    const [areaInput, setAreaInput] = useState('');
    const [area, setArea] = useState('');
    const [showTicketed, setShowTicketed] = useState(false);
    const requestId = useRef(0);
    const cancelPrevious = useRef<(() => void) | null>(null);

    // Lazy load: partial reload of the optional evidence prop only; ticket table state and URL stay untouched.
    // Only the latest request may clear the loading state; an older in-flight request is cancelled.
    const load = useCallback((next: { issue: string; area: string; showTicketed: boolean }) => {
        cancelPrevious.current?.();
        const id = ++requestId.current;
        setLoading(true);
        router.reload({
            only: ['monitoringEvidence'],
            data: {
                monitoring_issue: next.issue,
                monitoring_area: next.area || undefined,
                monitoring_show_ticketed: next.showTicketed ? '1' : undefined,
            },
            preserveUrl: true,
            onCancelToken: (token) => { cancelPrevious.current = () => token.cancel(); },
            onFinish: () => {
                if (id === requestId.current) {
                    setLoading(false);
                }
            },
        });
    }, []);

    useEffect(() => {
        if (open) {
            load({ issue, area, showTicketed });
        }
    }, [open, issue, area, showTicketed, load]);

    const applyArea = () => {
        if (areaInput.trim() !== area) {
            setArea(areaInput.trim());
        }
    };

    if (!summary) {
        return null;
    }

    const live = evidence?.summary ?? summary;
    const showChip = live.total > 0 || live.stale;
    const warn = live.massal > 0 || (live.by_issue?.[ISSUE_PON] ?? 0) > 0 || live.stale;
    const chipLabel = live.total > 0
        ? `${live.total} kandidat${live.massal > 0 ? ` · ${live.massal} massal` : ''}`
        : 'Monitoring terlambat';

    const countFor = (key: string) => live.by_issue?.[key] ?? 0;
    const stale = live.stale;
    const staleMinutes = evidence?.stale_minutes;
    const massal = evidence?.mass_outage_drafts ?? [];
    // Rows of another tab must not show under this tab's label while the new tab loads.
    const rowsReady = evidence?.filters?.monitoring_issue === issue;
    const candidates = rowsReady ? evidence?.candidates ?? [] : [];

    return (
        <>
            {showChip && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn('h-9 gap-2', warn && 'border-status-degraded/40 bg-status-degraded-bg text-status-degraded hover:bg-status-degraded-bg')}
                    onClick={() => setOpen(true)}
                    aria-label="Buka kandidat gangguan monitoring"
                >
                    {warn ? <AlertTriangle aria-hidden /> : <ShieldCheck aria-hidden />}
                    {chipLabel}
                </Button>
            )}

            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent onOpenAutoFocus={(e) => e.preventDefault()}>
                    <SheetHeader>
                        <SheetTitle>Kandidat gangguan monitoring</SheetTitle>
                        <SheetDescription>Dari snapshot konektivitas. Pelanggan isolir tidak dihitung. Buat draft untuk review sebelum jadi tiket.</SheetDescription>
                    </SheetHeader>

                    {stale && (
                        <div role="alert" className="flex items-center gap-2 rounded-md bg-status-degraded-bg px-3 py-2 text-sm text-status-degraded">
                            <AlertTriangle className="size-4 shrink-0" aria-hidden />
                            Data monitoring terlambat{staleMinutes ? ` (lebih dari ${staleMinutes} menit)` : ''}. Kandidat lama diabaikan.
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            aria-label="Filter area"
                            className="h-9 w-40"
                            placeholder="Area"
                            value={areaInput}
                            onChange={(e) => setAreaInput(e.target.value)}
                            onBlur={applyArea}
                            onKeyDown={(e) => { if (e.key === 'Enter') applyArea(); }}
                        />
                        <label className="flex items-center gap-2 text-sm text-muted-foreground">
                            <input type="checkbox" className="size-4 accent-primary" checked={showTicketed} onChange={(e) => setShowTicketed(e.target.checked)} />
                            Tampilkan yang sudah bertiket{live.ticketed > 0 ? ` (${live.ticketed})` : ''}
                        </label>
                    </div>

                    {massal.length > 0 && (
                        <section aria-label="Gangguan massal" className="space-y-2">
                            <h3 className="text-sm font-semibold">Massal ({massal.length})</h3>
                            <ul className="divide-y rounded-md border">
                                {massal.map((g) => (
                                    <li key={g.group_key} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">{g.issue_label}</p>
                                            <p className="truncate text-xs text-muted-foreground">ODP {g.odp || '-'} · PON {g.pon || '-'} · {g.last_seen_at || '-'}</p>
                                        </div>
                                        <StatusBadge label={`${g.count} pelanggan`} tone={g.severity === 'critical' ? 'down' : 'degraded'} showIcon={false} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    <Tabs value={issue} onValueChange={setIssue} className="flex min-h-0 flex-1 flex-col gap-3">
                        <TabsList className="w-full justify-start">
                            <TabsTrigger value={ISSUE_PON}>PON offline ({countFor(ISSUE_PON)})</TabsTrigger>
                            <TabsTrigger value={ISSUE_SIGNAL}>Redaman buruk ({countFor(ISSUE_SIGNAL)})</TabsTrigger>
                        </TabsList>

                        <div className="min-h-0 flex-1 overflow-y-auto rounded-md border">
                            {loading && !rowsReady ? (
                                <div className="space-y-2 p-3">
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-full" />
                                    <Skeleton className="h-8 w-full" />
                                </div>
                            ) : candidates.length === 0 ? (
                                <EmptyState title="Tidak ada kandidat" description="Belum ada kandidat aktif untuk filter ini." className="min-h-40 border-0" />
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Pelanggan</TableHead>
                                            <TableHead>Lokasi</TableHead>
                                            <TableHead className="text-right">RX</TableHead>
                                            <TableHead className="hidden sm:table-cell">Terakhir</TableHead>
                                            <TableHead className="text-right">Aksi</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className={cn(loading && 'opacity-60')}>
                                        {candidates.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        {severityBadge(c.severity)}
                                                    </div>
                                                    <p className="mt-1 text-sm font-medium">{c.customer_name || '-'}</p>
                                                    <p className="font-mono text-xs text-muted-foreground">{c.customer_code || '-'}</p>
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">{[c.area, c.odp, c.pon_port].filter(Boolean).join(' · ') || '-'}</TableCell>
                                                <TableCell className="whitespace-nowrap text-right text-xs">{c.onu_rx_power_dbm ?? '-'}</TableCell>
                                                <TableCell className="hidden whitespace-nowrap text-xs text-muted-foreground sm:table-cell">{c.last_seen_at || '-'}</TableCell>
                                                <TableCell className="text-right">
                                                    {c.existing_ticket_id ? (
                                                        <Button size="sm" variant="outline" asChild><Link href={route('tickets.show', c.existing_ticket_id)}>Tiket #{c.existing_ticket_id}</Link></Button>
                                                    ) : canCreateDraft ? (
                                                        <Button size="sm" variant="outline" onClick={() => router.post(route('tickets.monitoring-candidates.draft', c.id))}>Buat draft</Button>
                                                    ) : null}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </div>
                    </Tabs>
                </SheetContent>
            </Sheet>
        </>
    );
}
