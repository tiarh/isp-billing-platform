import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { ChevronDown, ChevronRight, RefreshCw, ShieldAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Customer = { id: number; name: string };

type Incident = {
    incident_key: string;
    scope: 'pon' | 'odp';
    scope_label: string;
    priority: 'P1' | 'P2' | 'P3';
    affected_count: number;
    area: string | null;
    olt: string;
    pon: string;
    issues: string[];
    customers: Customer[];
    since: string | null;
    ticket_id: number | null;
};

type Payload = {
    summary: { incidents: number; pon_incidents: number; odp_incidents: number; affected_customers: number; p1: number };
    incidents: Incident[];
};

const toneClass: Record<string, string> = {
    P1: 'border-status-down/40 text-status-down',
    P2: 'border-status-degraded/40 text-status-degraded',
    P3: 'border-border text-muted-foreground',
};

function sinceLabel(value: string | null): string {
    if (!value) return '-';
    const ms = Date.now() - new Date(value).getTime();
    if (!Number.isFinite(ms) || ms < 0) return '-';
    const minutes = Math.floor(ms / 60000);
    if (minutes < 60) return minutes + 'm';
    const hours = Math.floor(minutes / 60);
    return hours < 24 ? hours + 'j' : Math.floor(hours / 24) + 'h';
}

export default function IncidentInbox() {
    const [data, setData] = useState<Payload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [nonce, setNonce] = useState(0);
    const [open, setOpen] = useState<string | null>(null);
    const [filter, setFilter] = useState<'all' | 'pon' | 'odp'>('all');

    useEffect(() => {
        const controller = new AbortController();
        let timer: number | undefined;
        const load = async () => {
            setLoading(true);
            setError(null);
            try {
                const res = await fetch(route('api.monitoring.incidents'), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!res.ok) throw new Error('unavailable');
                setData((await res.json()) as Payload);
            } catch (e) {
                if ((e as Error).name !== 'AbortError') setError('Gagal memuat data gangguan.');
            } finally {
                setLoading(false);
                if (document.visibilityState !== 'hidden') {
                    timer = window.setTimeout(() => setNonce((n) => n + 1), 30000);
                }
            }
        };
        void load();
        return () => {
            controller.abort();
            if (timer) window.clearTimeout(timer);
        };
    }, [nonce]);

    const rows = useMemo(() => {
        const all = data?.incidents ?? [];
        return filter === 'all' ? all : all.filter((row) => row.scope === filter);
    }, [data, filter]);

    const summary = data?.summary;

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <ShieldAlert className="h-5 w-5 text-status-down" />
                    <div>
                        <div className="text-sm font-semibold">Gangguan aktif</div>
                        <div className="text-xs text-muted-foreground">
                            {summary ? summary.incidents + ' insiden · ' + summary.affected_customers + ' pelanggan terdampak' : 'memuat…'}
                        </div>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex rounded-md border text-xs">
                        {(['all', 'pon', 'odp'] as const).map((key) => (
                            <button
                                key={key}
                                type="button"
                                onClick={() => setFilter(key)}
                                className={
                                    'px-3 py-1.5 ' +
                                    (filter === key ? 'bg-muted font-semibold' : 'text-muted-foreground')
                                }
                            >
                                {key === 'all' ? 'Semua' : key === 'pon' ? 'ODC/PON' : 'ODP'}
                            </button>
                        ))}
                    </div>
                    <Button variant="outline" size="sm" onClick={() => setNonce((n) => n + 1)}>
                        <RefreshCw className="mr-2 h-3.5 w-3.5" /> Refresh
                    </Button>
                </div>
            </div>

            {error ? (
                <Card>
                    <CardContent className="py-6 text-sm text-status-down">{error}</CardContent>
                </Card>
            ) : null}

            {!error && !loading && rows.length === 0 ? (
                <Card>
                    <CardContent className="py-8 text-center text-sm text-muted-foreground">
                        Tidak ada gangguan terkelompok saat ini.
                    </CardContent>
                </Card>
            ) : null}

            <div className="space-y-2">
                {rows.map((row) => {
                    const isOpen = open === row.incident_key;
                    return (
                        <Card key={row.incident_key}>
                            <CardContent className="p-0">
                                <button
                                    type="button"
                                    className="flex w-full items-center gap-3 px-4 py-3 text-left"
                                    onClick={() => setOpen(isOpen ? null : row.incident_key)}
                                >
                                    {isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                                    <Badge variant="outline" className={toneClass[row.priority]}>
                                        {row.priority}
                                    </Badge>
                                    <Badge variant="outline">{row.scope === 'pon' ? 'ODC/PON' : 'ODP'}</Badge>
                                    <span className="min-w-0 flex-1 truncate font-medium">{row.scope_label}</span>
                                    {row.area ? <span className="hidden text-xs text-muted-foreground md:inline">{row.area}</span> : null}
                                    <span className="font-mono text-sm font-semibold">{row.affected_count} pelanggan</span>
                                    <span className="hidden text-xs text-muted-foreground sm:inline">{sinceLabel(row.since)}</span>
                                </button>
                                {isOpen ? (
                                    <div className="border-t px-4 py-3">
                                        <div className="mb-2 flex flex-wrap gap-2 text-xs text-muted-foreground">
                                            <span>OLT: <span className="font-mono">{row.olt}</span></span>
                                            <span>PON: <span className="font-mono">{row.pon}</span></span>
                                            <span>Isu: {row.issues.join(', ')}</span>
                                            {row.ticket_id ? <span>Tiket: #{row.ticket_id}</span> : null}
                                        </div>
                                        <div className="max-h-56 overflow-auto rounded-md border">
                                            <table className="w-full text-sm">
                                                <tbody>
                                                    {row.customers.map((c) => (
                                                        <tr key={c.id} className="border-b last:border-b-0">
                                                            <td className="px-3 py-1.5 font-mono text-xs text-muted-foreground">{c.id}</td>
                                                            <td className="px-3 py-1.5">{c.name}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </div>
    );
}
