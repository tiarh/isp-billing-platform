import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { formatWibTime } from '@/lib/dateTime';
import { cn } from '@/lib/utils';
import axios from 'axios';
import { Activity, ChevronDown, ChevronUp, LoaderCircle, Stethoscope } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export type DiagnosisEvidenceItem = { layer: string; source: string; at: string | null; text: string; fresh: boolean };

export type DiagnosisPayload = {
    id: number | null;
    state: string;
    trigger: string;
    cause: string | null;
    label: string | null;
    confidence: 'tinggi' | 'sedang' | 'rendah' | null;
    /** "Pasti" | "Kemungkinan" | "Belum tahu" while the calibrated labels setting is on; null = show `confidence`. */
    confidence_word?: 'Pasti' | 'Kemungkinan' | 'Belum tahu' | null;
    action: string;
    evidence: DiagnosisEvidenceItem[];
    secondary: string[];
    probes: Record<string, { status?: string; reason?: string; text?: string; at?: string }>;
    created_at: string | null;
};

export type DiagnosisPanelProps = {
    diagnosis: DiagnosisPayload | null;
    checkNowEnabled: boolean;
    cooldownMinutes: number;
    timeouts: { router: number; olt: number; cpe: number };
};

type ProbeName = 'router' | 'olt' | 'cpe';
type ProbeState = { state: 'running' | 'ok' | 'skipped' | 'failed' | 'queued'; text: string };

const PROBE_LABEL: Record<ProbeName, string> = { router: 'Router', olt: 'OLT', cpe: 'ONU' };
const PROBES: ProbeName[] = ['router', 'olt', 'cpe'];
const EVIDENCE_SHOWN = 5;

const CONFIDENCE_TONE: Record<string, string> = {
    tinggi: 'border-transparent bg-status-up-bg text-status-up',
    sedang: 'border-transparent bg-status-degraded-bg text-status-degraded',
    rendah: 'border-transparent bg-muted text-muted-foreground',
    Pasti: 'border-transparent bg-status-up-bg text-status-up',
    Kemungkinan: 'border-transparent bg-status-degraded-bg text-status-degraded',
    'Belum tahu': 'border-transparent bg-muted text-muted-foreground',
};

function clock(iso: string | null): string {
    if (!iso) return '-';
    return formatWibTime(iso).replace('.', ':');
}

function agoText(iso: string | null, now: number): string {
    if (!iso) return '';
    const minutes = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
    if (minutes < 1) return 'baru saja';
    if (minutes < 60) return `${minutes} menit lalu`;
    const hours = Math.floor(minutes / 60);
    return hours < 24 ? `${hours} jam lalu` : `${Math.floor(hours / 24)} hari lalu`;
}

function probeText(name: ProbeName, status: string | undefined, text: string | undefined): ProbeState {
    const t = text ?? (status === 'ok' ? 'selesai' : 'dilewati');
    if (status === 'ok') return { state: 'ok', text: t };
    if (status === 'queued') return { state: 'queued', text: t };
    if (status === 'skipped') return { state: 'skipped', text: t };
    return { state: 'failed', text: t || (name === 'router' ? 'router tidak terjangkau' : 'tidak terjangkau') };
}

type Props = {
    /** The customer to diagnose; null hides the panel (ticket form before a customer is picked). */
    customerId: number | null;
    ticketId?: number | null;
    /** Server props of the panel (ticket detail). On the create form they are fetched when a customer is picked. */
    initial?: DiagnosisPanelProps | null;
    /** Create form: fetch the DB-only diagnosis of the picked customer. */
    fetchPreview?: boolean;
    /** Create form: the id of the finished "Cek sekarang" check, sent with the ticket. */
    onCheckId?: (id: number | null) => void;
};

export default function DiagnosisPanel({ customerId, ticketId = null, initial = null, fetchPreview = false, onCheckId }: Props) {
    const [props, setProps] = useState<DiagnosisPanelProps | null>(initial);
    const [diagnosis, setDiagnosis] = useState<DiagnosisPayload | null>(initial?.diagnosis ?? null);
    const [open, setOpen] = useState(true);
    const [showAll, setShowAll] = useState(false);
    const [running, setRunning] = useState(false);
    const [probes, setProbes] = useState<Partial<Record<ProbeName, ProbeState>>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const controllers = useRef<AbortController[]>([]);

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30000);
        return () => clearInterval(t);
    }, []);

    // Create form: a DB-only diagnosis right after a customer is picked.
    useEffect(() => {
        if (!fetchPreview || customerId === null) return;
        const controller = new AbortController();
        setLoading(true);
        setProbes({});
        setMessage(null);
        setShowAll(false);
        onCheckId?.(null);
        axios
            .get(route('tickets.diagnose.customer', customerId), { signal: controller.signal })
            .then((res) => {
                setProps({ diagnosis: res.data.diagnosis, checkNowEnabled: res.data.checkNowEnabled, cooldownMinutes: res.data.cooldownMinutes, timeouts: res.data.timeouts });
                setDiagnosis(res.data.diagnosis);
            })
            .catch((e) => {
                if (!axios.isCancel(e)) setDiagnosis(null);
            })
            .finally(() => setLoading(false));
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [customerId, fetchPreview]);

    useEffect(() => () => controllers.current.forEach((c) => c.abort()), []);

    const runCheck = useCallback(async () => {
        if (customerId === null || running) return;
        setRunning(true);
        setMessage(null);
        setProbes({ router: { state: 'running', text: 'mengecek…' }, olt: { state: 'running', text: 'mengecek…' }, cpe: { state: 'running', text: 'mengecek…' } });
        let check: number;
        let timeouts = props?.timeouts ?? { router: 6, olt: 15, cpe: 12 };
        try {
            const res = await axios.post(route('tickets.diagnose.start'), { customer_id: customerId, ticket_id: ticketId });
            check = res.data.check;
            timeouts = res.data.timeouts ?? timeouts;
        } catch (e) {
            const data = axios.isAxiosError(e) ? (e.response?.data as { message?: string } | undefined) : undefined;
            setMessage(data?.message ?? 'Cek belum bisa dijalankan.');
            setProbes({});
            setRunning(false);
            return;
        }
        onCheckId?.(check);

        await Promise.all(
            PROBES.map(async (name) => {
                const controller = new AbortController();
                controllers.current.push(controller);
                const timer = setTimeout(() => controller.abort(), (timeouts[name] + 2) * 1000);
                try {
                    const res = await axios.post(route('tickets.diagnose.probe', { diagnosis: check, probe: name }), {}, { signal: controller.signal });
                    setProbes((p) => ({ ...p, [name]: probeText(name, res.data.status, res.data.text) }));
                } catch (e) {
                    const aborted = axios.isCancel(e);
                    setProbes((p) => ({ ...p, [name]: { state: 'failed', text: aborted ? 'terlalu lama, dilewati' : 'tidak terjangkau' } }));
                } finally {
                    clearTimeout(timer);
                }
            }),
        );

        try {
            const res = await axios.post(route('tickets.diagnose.finish', { diagnosis: check }));
            if (res.data.diagnosis) {
                setDiagnosis(res.data.diagnosis);
                setShowAll(false);
            }
            if (res.data.failed) setMessage('Hasil cek belum bisa dihitung.');
        } catch {
            setMessage('Hasil cek belum bisa dihitung.');
        } finally {
            setRunning(false);
        }
    }, [customerId, ticketId, props, running, onCheckId]);

    if (customerId === null || (!initial && !props)) return null;

    const checkNow = props?.checkNowEnabled ?? false;
    const evidence = diagnosis?.evidence ?? [];
    const visibleEvidence = showAll ? evidence : evidence.slice(0, EVIDENCE_SHOWN);

    return (
        <Card data-testid="diagnosis-panel">
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Stethoscope className="h-4 w-4 text-muted-foreground" aria-hidden />
                        Diagnosa otomatis
                    </CardTitle>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 px-2"
                        data-testid="diagnosis-toggle"
                        aria-expanded={open}
                        aria-label={open ? 'Tutup diagnosa' : 'Buka diagnosa'}
                        onClick={() => setOpen((v) => !v)}
                    >
                        {open ? <ChevronUp className="h-4 w-4" aria-hidden /> : <ChevronDown className="h-4 w-4" aria-hidden />}
                    </Button>
                </div>
                {!open && diagnosis && (
                    <p className="text-sm" data-testid="diagnosis-summary">
                        <span className="font-medium">{diagnosis.label}</span> · {diagnosis.confidence_word ?? `keyakinan ${diagnosis.confidence}`}
                    </p>
                )}
            </CardHeader>
            {open && (
                <CardContent className="space-y-3 text-sm">
                    {loading && (
                        <p className="flex items-center gap-2 text-muted-foreground">
                            <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden /> Memuat…
                        </p>
                    )}
                    {!loading && !diagnosis && <p className="text-muted-foreground" data-testid="diagnosis-empty">Belum ada diagnosa untuk pelanggan ini.</p>}
                    {diagnosis && (
                        <>
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <div>
                                    <span className="text-muted-foreground">Dugaan: </span>
                                    <span className="font-semibold" data-testid="diagnosis-cause">{diagnosis.label}</span>
                                </div>
                                {diagnosis.confidence && (
                                    <span className={cn('rounded-full border px-2.5 py-0.5 text-xs font-semibold', CONFIDENCE_TONE[diagnosis.confidence_word ?? diagnosis.confidence])} data-testid="diagnosis-confidence">
                                        Keyakinan: {diagnosis.confidence_word ?? diagnosis.confidence}
                                    </span>
                                )}
                            </div>
                            <div>
                                <span className="text-muted-foreground">Saran: </span>
                                <span className="font-medium" data-testid="diagnosis-action">{diagnosis.action}</span>
                            </div>
                            {diagnosis.secondary.length > 0 && (
                                <div className="text-xs text-muted-foreground">Bisa juga: {diagnosis.secondary.join(', ')}</div>
                            )}
                            {evidence.length > 0 && (
                                <div>
                                    <div className="mb-1 text-muted-foreground">Bukti</div>
                                    <ul className="list-disc space-y-0.5 pl-5" data-testid="diagnosis-evidence">
                                        {visibleEvidence.map((e, i) => (
                                            <li key={`${i}-${e.text}`} className={cn(!e.fresh && 'text-muted-foreground')}>
                                                {e.text}
                                            </li>
                                        ))}
                                    </ul>
                                    {evidence.length > EVIDENCE_SHOWN && (
                                        <Button type="button" variant="link" size="sm" className="h-auto p-0 text-xs" data-testid="diagnosis-evidence-more" onClick={() => setShowAll((v) => !v)}>
                                            {showAll ? 'sembunyikan' : `lihat semua (${evidence.length})`}
                                        </Button>
                                    )}
                                </div>
                            )}
                            <p className="text-xs text-muted-foreground" data-testid="diagnosis-time">
                                Dicek {clock(diagnosis.created_at)} ({agoText(diagnosis.created_at, now)})
                            </p>
                        </>
                    )}

                    {Object.keys(probes).length > 0 && (
                        <ul className="space-y-1 rounded-md border bg-muted/30 p-2" data-testid="diagnosis-probes" aria-live="polite">
                            {PROBES.map((name) => {
                                const p = probes[name];
                                if (!p) return null;
                                return (
                                    <li key={name} className="flex items-center gap-2" data-testid={`diagnosis-probe-${name}`}>
                                        <span className="w-14 font-medium">{PROBE_LABEL[name]}</span>
                                        {p.state === 'running' ? (
                                            <span className="flex items-center gap-1 text-muted-foreground">
                                                <LoaderCircle className="h-3.5 w-3.5 animate-spin" aria-hidden /> {p.text}
                                            </span>
                                        ) : (
                                            <span className={cn(p.state === 'ok' ? 'text-status-up' : p.state === 'failed' ? 'text-status-down' : 'text-muted-foreground')}>{p.text}</span>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}

                    {message && (
                        <p className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2 text-status-degraded" role="status" data-testid="diagnosis-message">
                            {message}
                        </p>
                    )}

                    {checkNow && (
                        <Button type="button" variant="outline" size="sm" onClick={() => void runCheck()} disabled={running} data-testid="diagnosis-check-now" className="gap-2">
                            {running ? <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden /> : <Activity className="h-4 w-4" aria-hidden />}
                            {running ? 'Mengecek…' : 'Cek sekarang'}
                        </Button>
                    )}
                </CardContent>
            )}
        </Card>
    );
}
