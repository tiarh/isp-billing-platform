import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import axios from 'axios';
import { useEffect, useState } from 'react';

type Interval = { low: number; high: number } | null;
type ClassRow = { cause: string; label: string | null; predicted: number; correct: number; correct_family?: number; precision: number | null; actual: number; recall: number | null };
type Confusion = { predicted: string; predicted_label: string | null; actual: string; actual_label: string | null; count: number };
type VersionRow = {
    version: string;
    evaluated: number;
    abstained: number;
    predicted: number;
    correct: number;
    correct_family: number;
    precision: number | null;
    precision_family: number | null;
    coverage: number | null;
    ci: Interval;
    ci_family: Interval;
};
type Calibration = {
    cause: string;
    label: string | null;
    n_ticket: number;
    n_independent: number;
    n_circular: number;
    correct: number;
    precision: number | null;
    wilson_low: number | null;
    state: 'pasti' | 'kemungkinan' | 'belum_tahu';
};
type Outcomes = { independent: number; circular: number; by_class: { cause: string; label: string | null; independent: number; circular: number }[] };
type Disagreement = { ticket_id: number; code: string | null; label: string | null; data: string | null; signature: string };

type Report = {
    week_start: string;
    week_end: string;
    tickets: number;
    closed_auto: number;
    truth_unknown: number;
    truth_not_scored?: number;
    no_prediction: number;
    backfilled?: number;
    bulk_closed?: number;
    abstained: number;
    evaluated: number;
    correct: number;
    classes: ClassRow[];
    confusions: Confusion[];
    by_version?: VersionRow[];
    calibration?: Calibration[];
    outcomes?: Outcomes;
    disagreements?: Disagreement[];
    fill?: { closes: number; human: number; filled: number; fill_rate: number | null; unknown: number; empty: number };
    rolling: { weeks: number; evaluated: number; correct: number; classes: ClassRow[] };
};

const PAGE_SIZE = 8;

const STATE_WORD: Record<string, string> = { pasti: 'Pasti', kemungkinan: 'Kemungkinan', belum_tahu: 'Belum tahu' };

const SIGNATURE_WORD: Record<string, string> = {
    rx_jump: 'redaman membaik',
    serial_swap: 'ONU diganti',
    unisolate: 'isolir dibuka',
    co_recovery: 'satu jalur pulih bersamaan',
};

function pct(v: number | null | undefined): string {
    return v === null || v === undefined ? '-' : `${Math.round(v * 100)}%`;
}

function withRange(count: number, rate: number | null, ci: Interval): string {
    if (rate === null) return `${count}`;
    return `${count} (${pct(rate)}${ci ? `, kisaran ${Math.round(ci.low * 100)}–${pct(ci.high)}` : ''})`;
}

function Pager({ page, lastPage, onPage, testid }: { page: number; lastPage: number; onPage: (p: number) => void; testid: string }) {
    if (lastPage <= 1) return null;
    return (
        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
            <Button type="button" variant="outline" size="sm" disabled={page <= 1} onClick={() => onPage(Math.max(1, page - 1))} data-testid={`${testid}-prev`}>
                Sebelumnya
            </Button>
            <span data-testid={`${testid}-page`}>
                Halaman {page} dari {lastPage}
            </span>
            <Button type="button" variant="outline" size="sm" disabled={page >= lastPage} onClick={() => onPage(Math.min(lastPage, page + 1))} data-testid={`${testid}-next`}>
                Berikutnya
            </Button>
        </div>
    );
}

/** Card "Ketepatan diagnosa" of Monitoring > Deteksi Gangguan: last weekly report, per rules version, per class, outcome labels, calibration and the disagreement list (paged). */
export default function DiagnosisPrecisionCard() {
    const [report, setReport] = useState<Report | null | undefined>(undefined);
    const [page, setPage] = useState(1);
    const [disagreePage, setDisagreePage] = useState(1);

    useEffect(() => {
        const controller = new AbortController();
        axios
            .get(route('api.monitoring.outage-shadow.diagnosis-precision'), { signal: controller.signal })
            .then((res) => setReport(res.data.report ?? null))
            .catch((e) => {
                if (!axios.isCancel(e)) setReport(null);
            });
        return () => controller.abort();
    }, []);

    if (report === undefined) return null;

    if (report === null) {
        return (
            <Card data-testid="diagnosis-precision">
                <CardHeader className="pb-2">
                    <CardTitle className="text-base">Ketepatan diagnosa</CardTitle>
                </CardHeader>
                <CardContent className="text-sm text-muted-foreground">Belum ada laporan. Laporan pertama keluar hari Senin.</CardContent>
            </Card>
        );
    }

    const lastPage = Math.max(1, Math.ceil(report.confusions.length / PAGE_SIZE));
    const rows = report.confusions.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
    const guessed = Math.max(0, report.evaluated - report.abstained);
    const versions = report.by_version ?? [];
    const calibration = report.calibration ?? [];
    const stateOf = (cause: string) => calibration.find((c) => c.cause === cause)?.state ?? null;
    const outcomes = report.outcomes;
    const disagreements = report.disagreements ?? [];
    const disagreeLast = Math.max(1, Math.ceil(disagreements.length / PAGE_SIZE));
    const disagreeRows = disagreements.slice((disagreePage - 1) * PAGE_SIZE, disagreePage * PAGE_SIZE);
    const notRated =
        report.closed_auto + report.truth_unknown + (report.truth_not_scored ?? 0) + (report.backfilled ?? 0) + (report.bulk_closed ?? 0) + report.no_prediction;

    return (
        <Card data-testid="diagnosis-precision">
            <CardHeader className="pb-2">
                <CardTitle className="text-base">Ketepatan diagnosa</CardTitle>
                <p className="text-xs text-muted-foreground">
                    Minggu {report.week_start} s/d {report.week_end}. Tebakan pertama tiket dibandingkan dengan penyebab dari teknisi.
                </p>
            </CardHeader>
            <CardContent className="space-y-4 text-sm">
                <p data-testid="diagnosis-precision-summary">
                    Tiket gangguan tutup: <b>{report.tickets}</b> · dinilai <b>{report.evaluated}</b> · benar <b>{report.correct}</b> dari <b>{guessed}</b> yang ditebak
                </p>
                {report.fill && report.fill.human > 0 && (
                    <p data-testid="diagnosis-precision-fill">
                        Penyebab jelas diisi: <b>{report.fill.filled}</b> dari <b>{report.fill.human}</b> ({pct(report.fill.fill_rate)}) · tidak tahu <b>{report.fill.unknown}</b> · belum diisi <b>{report.fill.empty}</b>
                    </p>
                )}
                <div className="space-y-1">
                    <p className="text-xs text-muted-foreground" data-testid="diagnosis-precision-excluded">
                        Tidak dinilai: ditutup otomatis {report.closed_auto} · teknisi pilih "Tidak tahu" {report.truth_unknown} · bukan gangguan / lainnya {report.truth_not_scored ?? 0} · tebakan telat{' '}
                        {report.backfilled ?? 0} · ditutup massal admin {report.bulk_closed ?? 0} · tanpa tebakan {report.no_prediction} · sistem tidak yakin {report.abstained}
                    </p>
                    <p className="text-xs text-muted-foreground" data-testid="diagnosis-precision-sum">
                        {report.tickets} tiket = {report.evaluated} dinilai + {notRated} tidak dinilai (sistem tidak yakin ikut dalam yang dinilai)
                    </p>
                </div>

                {versions.length > 0 && (
                    <div>
                        <div className="mb-1 font-medium">Per versi aturan</div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[520px] text-left text-sm" data-testid="diagnosis-precision-versions">
                                <thead className="text-xs text-muted-foreground">
                                    <tr>
                                        <th className="py-1 pr-2 font-medium">Versi</th>
                                        <th className="py-1 pr-2 text-right font-medium">Dinilai</th>
                                        <th className="py-1 pr-2 text-right font-medium">Ditebak</th>
                                        <th className="py-1 pr-2 font-medium">Benar persis</th>
                                        <th className="py-1 pr-2 font-medium">Benar satu jenis</th>
                                        <th className="py-1 text-right font-medium">Ditebak dari dinilai</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {versions.map((v) => (
                                        <tr key={v.version} className="border-t">
                                            <td className="py-1 pr-2">{v.version}</td>
                                            <td className="py-1 pr-2 text-right tabular-nums">{v.evaluated}</td>
                                            <td className="py-1 pr-2 text-right tabular-nums">{v.predicted}</td>
                                            <td className="py-1 pr-2 tabular-nums">{withRange(v.correct, v.precision, v.ci)}</td>
                                            <td className="py-1 pr-2 tabular-nums">{withRange(v.correct_family, v.precision_family, v.ci_family)}</td>
                                            <td className="py-1 text-right tabular-nums">{pct(v.coverage)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <p className="mt-1 text-xs text-muted-foreground">Kabel drop, redaman dan ODP dihitung satu jenis, karena tindakannya sama.</p>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[560px] text-left text-sm" data-testid="diagnosis-precision-classes">
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="py-1 pr-2 font-medium">Jenis</th>
                                <th className="py-1 pr-2 text-right font-medium">Ditebak</th>
                                <th className="py-1 pr-2 text-right font-medium">Benar</th>
                                <th className="py-1 pr-2 text-right font-medium">Tepat</th>
                                <th className="py-1 pr-2 text-right font-medium">Sebenarnya</th>
                                <th className="py-1 pr-2 text-right font-medium">Terjaring</th>
                                <th className="py-1 font-medium">Keyakinan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {report.classes.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="py-2 text-muted-foreground">
                                        Belum ada tiket yang dinilai.
                                    </td>
                                </tr>
                            )}
                            {report.classes.map((c) => {
                                const state = stateOf(c.cause);
                                return (
                                    <tr key={c.cause} className="border-t">
                                        <td className="py-1 pr-2">{c.label ?? c.cause}</td>
                                        <td className="py-1 pr-2 text-right tabular-nums">{c.predicted}</td>
                                        <td className="py-1 pr-2 text-right tabular-nums">{c.correct}</td>
                                        <td className="py-1 pr-2 text-right tabular-nums">{pct(c.precision)}</td>
                                        <td className="py-1 pr-2 text-right tabular-nums">{c.actual}</td>
                                        <td className="py-1 pr-2 text-right tabular-nums">{pct(c.recall)}</td>
                                        <td className="py-1" data-testid={`diagnosis-precision-state-${c.cause}`}>
                                            {state ? STATE_WORD[state] : '-'}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {outcomes && (
                    <div data-testid="diagnosis-precision-outcomes">
                        <div className="mb-1 font-medium">Dicek dari data</div>
                        <p className="text-xs text-muted-foreground">
                            Dari data yang tercatat: <b>{outcomes.independent}</b> dipakai untuk menilai · <b>{outcomes.circular}</b> tidak dinilai (sama dengan tebakan: listrik mati lalu nyala).
                        </p>
                        {outcomes.by_class.length > 0 && (
                            <ul className="mt-1 space-y-0.5">
                                {outcomes.by_class.map((o) => (
                                    <li key={o.cause}>
                                        {o.label ?? o.cause}: {o.independent} dipakai menilai{o.circular > 0 ? ` · ${o.circular} tidak dinilai` : ''}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                <div>
                    <div className="mb-1 font-medium">Label beda dengan data</div>
                    {disagreements.length === 0 ? (
                        <p className="text-muted-foreground" data-testid="diagnosis-precision-disagree-empty">
                            Tidak ada.
                        </p>
                    ) : (
                        <>
                            <p className="mb-1 text-xs text-muted-foreground">Untuk dicek tiap minggu. Tidak ada yang diubah otomatis.</p>
                            <ul className="space-y-0.5" data-testid="diagnosis-precision-disagree">
                                {disagreeRows.map((d) => (
                                    <li key={d.ticket_id}>
                                        {d.code ?? `Tiket ${d.ticket_id}`}: dipilih <b>{d.label}</b>, data bilang <b>{d.data}</b> ({SIGNATURE_WORD[d.signature] ?? d.signature})
                                    </li>
                                ))}
                            </ul>
                            <Pager page={disagreePage} lastPage={disagreeLast} onPage={setDisagreePage} testid="diagnosis-precision-disagree" />
                        </>
                    )}
                </div>

                <div>
                    <div className="mb-1 font-medium">Tebakan yang meleset</div>
                    {report.confusions.length === 0 ? (
                        <p className="text-muted-foreground">Tidak ada.</p>
                    ) : (
                        <>
                            <ul className="space-y-0.5" data-testid="diagnosis-precision-confusions">
                                {rows.map((c) => (
                                    <li key={`${c.predicted}>${c.actual}`}>
                                        Ditebak <b>{c.predicted_label ?? c.predicted}</b>, sebenarnya <b>{c.actual_label ?? c.actual}</b>: {c.count} tiket
                                    </li>
                                ))}
                            </ul>
                            <Pager page={page} lastPage={lastPage} onPage={setPage} testid="diagnosis-precision" />
                        </>
                    )}
                </div>

                <p className="text-xs text-muted-foreground" data-testid="diagnosis-precision-rolling">
                    4 minggu terakhir: benar {report.rolling.correct} dari {report.rolling.evaluated} tiket yang dinilai.
                </p>
            </CardContent>
        </Card>
    );
}
