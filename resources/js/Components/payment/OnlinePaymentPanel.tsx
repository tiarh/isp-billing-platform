import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import qrcode from 'qrcode-generator';
import { Button } from '@/Components/ui/button';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import { formatIdr } from '@/lib/format';
import { formatWibDateTime } from '@/lib/dateTime';
import { AlertCircle, CheckCircle2, Clock, Copy, ExternalLink, Loader2, Smartphone } from 'lucide-react';

export interface OnlineTransaction {
    order_id: string;
    provider: string;
    status: string;
    kind: 'va' | 'qr' | 'redirect' | null;
    method: string;
    method_group: string;
    method_name: string | null;
    amount_invoice: number;
    fee: number;
    amount_charged: number;
    payment_url: string | null;
    va_number: string | null;
    qr_string: string | null;
    expires_at: string | null;
}

export interface OnlineConfig {
    provider: string;
    label: string;
    remaining: number;
    fee_bearer: 'customer' | 'merchant';
    open_transaction: OnlineTransaction | null;
    // Other still-payable codes of this invoice (after "Ganti metode"); absent when the server does not send it.
    other_open_transactions?: OnlineTransaction[];
}

// A code is still payable while pending/created and not past its expiry.
function isActiveCode(t: OnlineTransaction): boolean {
    if (t.status !== 'pending' && t.status !== 'created') return false;
    if (!t.expires_at) return true;
    const ms = Date.parse(t.expires_at);
    return Number.isNaN(ms) || ms > Date.now();
}

function mergeCodes(list: Array<OnlineTransaction | null | undefined>): OnlineTransaction[] {
    const seen = new Set<string>();
    const out: OnlineTransaction[] = [];
    list.forEach((t) => {
        if (!t || seen.has(t.order_id)) return;
        seen.add(t.order_id);
        out.push(t);
    });
    return out;
}

interface MethodOption {
    code: string;
    name: string;
    group: string;
    fee: number;
    total: number;
    image_url?: string | null;
}

const GROUP_LABELS: Record<string, string> = {
    va: 'Virtual Account (transfer bank)',
    qris: 'QRIS (semua e-wallet / m-banking)',
    ewallet: 'E-Wallet',
    retail: 'Minimarket',
};

const GROUP_ORDER = ['qris', 'va', 'ewallet', 'retail'];

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function qrDataUrl(value: string): string | null {
    try {
        const qr = qrcode(0, 'M');
        qr.addData(value);
        qr.make();
        return qr.createDataURL(6, 2);
    } catch {
        return null;
    }
}

function expiryText(iso: string | null): string {
    if (!iso) return '';
    return formatWibDateTime(iso);
}

function methodInitials(name: string): string {
    const words = name.replace(/[^A-Za-z0-9 ]+/g, ' ').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '';
    if (words.length === 1) return words[0].slice(0, 3).toUpperCase();
    return words.slice(0, 3).map((w) => w[0]).join('').toUpperCase();
}

// Provider logo with a local fallback: if the image is missing or blocked
// (CSP, hotlink protection, network) show the method initials instead of a blank box.
function MethodIcon({ name, imageUrl }: { name: string; imageUrl: string | null }) {
    const [failed, setFailed] = useState(false);
    if (imageUrl && !failed) {
        return (
            <img
                src={imageUrl}
                alt=""
                loading="lazy"
                referrerPolicy="no-referrer"
                onError={() => setFailed(true)}
                className="h-7 w-auto max-w-full object-contain"
            />
        );
    }
    const initials = methodInitials(name);
    if (!initials) return <Smartphone className="h-7 w-7 text-primary" />;
    return (
        <span
            aria-hidden="true"
            className="flex h-7 min-w-7 items-center justify-center rounded-md bg-primary-soft px-1.5 text-[11px] font-bold text-primary"
        >
            {initials}
        </span>
    );
}

export type UnavailableReason = 'load_empty' | 'load_error' | 'start_failed';

// Only system failures may surface manual transfer after a failed start. Not void / not_payable / busy / invalid_method.
export const FALLBACK_START_REASONS = ['unavailable', 'provider_error', 'amount_too_small'];

// Decides whether a failed "start payment" call is a system failure. A response without a
// reason counts only for server errors (>= 500) or a network / unreadable response (status 0).
export function isFallbackStartFailure(status: number, reason?: string | null): boolean {
    if (reason) return FALLBACK_START_REASONS.includes(reason);
    return status === 0 || status >= 500;
}

interface Props {
    invoiceUuid: string;
    online: OnlineConfig;
    fromIsolir?: boolean;
    returning?: boolean;
    onUnavailable?: (reason: UnavailableReason) => void;
    manualFallback?: boolean;
    // Tells the page whether a pending/created VA/QR is currently shown (the fallback must then be hidden).
    onTxnActive?: (active: boolean) => void;
}

export default function OnlinePaymentPanel({ invoiceUuid, online, fromIsolir = false, returning = false, onUnavailable, manualFallback = false, onTxnActive }: Props) {
    const [methods, setMethods] = useState<MethodOption[] | null>(null);
    const [loadError, setLoadError] = useState('');
    const [selected, setSelected] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState('');
    const [txn, setTxn] = useState<OnlineTransaction | null>(online.open_transaction);
    const [paid, setPaid] = useState(false);
    // All codes of this invoice that may still be paid (shown one + codes hidden by "Ganti metode").
    const [codes, setCodes] = useState<OnlineTransaction[]>(() => mergeCodes([online.open_transaction, ...(online.other_open_transactions ?? [])]));
    const [confirmSwitch, setConfirmSwitch] = useState(false);
    const keepCodeRef = useRef<HTMLButtonElement>(null);
    // Re-evaluate expiry every 30 s so an expired code leaves the banner without a reload.
    const [, setTick] = useState(0);
    useEffect(() => {
        const id = window.setInterval(() => setTick((n) => n + 1), 30_000);
        return () => window.clearInterval(id);
    }, []);
    // Keyboard focus follows the confirmation (the trigger button is replaced by it).
    useEffect(() => {
        if (confirmSwitch) keepCodeRef.current?.focus();
    }, [confirmSwitch]);

    useEffect(() => {
        let cancelled = false;
        fetch(route('public.invoice.online.methods', invoiceUuid), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (res) => {
                const body = await res.json().catch(() => ({}));
                if (cancelled) return;
                if (!res.ok || !body.ok) {
                    setLoadError(body.message || 'Metode pembayaran belum dapat dimuat.');
                    return;
                }
                setMethods(body.methods ?? []);
                if (Array.isArray(body.open_transactions)) {
                    setCodes((prev) => mergeCodes([...prev, ...(body.open_transactions as OnlineTransaction[])]));
                }
            })
            .catch(() => !cancelled && setLoadError('Metode pembayaran belum dapat dimuat.'));
        return () => {
            cancelled = true;
        };
    }, [invoiceUuid]);

    // Manual transfer fallback: report a load failure / empty list once, never while a pending
    // VA/QR is shown (double payment) and never when the customer just returned from paying.
    const onUnavailableRef = useRef(onUnavailable);
    onUnavailableRef.current = onUnavailable;
    const reportedRef = useRef(false);
    const loadOutcome: UnavailableReason | null = loadError ? 'load_error' : methods !== null && methods.length === 0 ? 'load_empty' : null;
    const txnActive = !!txn && (txn.status === 'pending' || txn.status === 'created');
    // Codes hidden by "Ganti metode" can still be paid: they count as active too (no fallback next to them).
    const activeCodes = codes.filter(isActiveCode);
    const hasActiveCode = txnActive || activeCodes.length > 0;
    const onTxnActiveRef = useRef(onTxnActive);
    onTxnActiveRef.current = onTxnActive;
    useEffect(() => {
        onTxnActiveRef.current?.(hasActiveCode);
    }, [hasActiveCode]);
    useEffect(() => {
        if (!loadOutcome || hasActiveCode || returning || reportedRef.current) return;
        reportedRef.current = true;
        onUnavailableRef.current?.(loadOutcome);
    }, [loadOutcome, hasActiveCode, returning]);

    const pollOrder = txn?.order_id ?? activeCodes[0]?.order_id ?? null;
    const shouldPoll = !paid && (pollOrder !== null || returning);

    const poll = useCallback(async () => {
        try {
            const url = route('public.invoice.online.status', invoiceUuid) + (pollOrder ? `?order=${encodeURIComponent(pollOrder)}` : '');
            const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok) return;
            const body = await res.json();
            if (body.paid) {
                setPaid(true);
                window.setTimeout(() => window.location.reload(), 2500);
            } else if (body.transaction) {
                const t = body.transaction as { order_id?: string; status: string };
                if (txn && t.status !== txn.status) setTxn({ ...txn, status: t.status });
                if (pollOrder) {
                    setCodes((prev) => prev.map((c) => (c.order_id === pollOrder && c.status !== t.status ? { ...c, status: t.status } : c)));
                }
            }
        } catch {
            // transient: next tick retries
        }
    }, [invoiceUuid, pollOrder, txn]);

    useEffect(() => {
        if (!shouldPoll) return;
        const id = window.setInterval(poll, 5000);
        return () => window.clearInterval(id);
    }, [shouldPoll, poll]);

    const grouped = useMemo(() => {
        const groups: Record<string, MethodOption[]> = {};
        (methods ?? []).forEach((m) => {
            (groups[m.group] ??= []).push(m);
        });
        return GROUP_ORDER.filter((g) => groups[g]?.length).map((g) => [g, groups[g]] as const);
    }, [methods]);

    const selectedOption = methods?.find((m) => m.code === selected) ?? null;

    const start = async () => {
        if (!selected || starting) return;
        setStarting(true);
        setError('');
        let status = 0;
        let reason: string | null = null;
        try {
            const res = await fetch(route('public.invoice.online.start', invoiceUuid), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ method: selected, from_isolir: fromIsolir }),
            });
            status = res.status;
            const body = await res.json().catch(() => null);
            reason = typeof body?.reason === 'string' ? body.reason : null;
            if (body === null && res.ok) status = 0; // unreadable success response
            if (!res.ok || !body?.ok) {
                throw new Error(body?.message || 'Pembayaran belum dapat dimulai.');
            }
            const next: OnlineTransaction = body.transaction;
            setTxn(next);
            setCodes((prev) => mergeCodes([next, ...prev.filter((c) => c.order_id !== next.order_id)]));
            if (next.kind === 'redirect' && next.payment_url) {
                window.location.href = next.payment_url;
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Pembayaran belum dapat dimulai.');
            // Not when the customer just returned from the provider: they may already have paid.
            if (!returning && isFallbackStartFailure(status, reason)) onUnavailableRef.current?.('start_failed');
        } finally {
            setStarting(false);
        }
    };

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            // clipboard blocked: the number is selectable
        }
    };

    if (paid) {
        return (
            <Alert className="border-status-up/30 bg-status-up-bg text-status-up" data-testid="online-paid">
                <CheckCircle2 className="h-5 w-5 text-status-up" />
                <AlertTitle className="font-semibold">Pembayaran diterima</AlertTitle>
                <AlertDescription>Terima kasih. Halaman akan dimuat ulang…</AlertDescription>
            </Alert>
        );
    }

    if (txn && (txn.status === 'pending' || txn.status === 'created')) {
        const qr = txn.kind === 'qr' && txn.qr_string ? qrDataUrl(txn.qr_string) : null;
        return (
            <div className="space-y-4" data-testid="online-instructions">
                <div className="rounded-lg border p-4 space-y-3">
                    <div className="flex items-center justify-between text-sm">
                        <span className="font-medium">{txn.method_name ?? txn.method}</span>
                        {txn.expires_at && (
                            <span className="flex items-center gap-1 text-muted-foreground">
                                <Clock className="h-4 w-4" /> Bayar sebelum {expiryText(txn.expires_at)}
                            </span>
                        )}
                    </div>

                    {txn.kind === 'va' && txn.va_number && (
                        <div className="space-y-1">
                            <p className="text-xs text-muted-foreground">
                                {txn.method_group === 'retail' ? 'Kode pembayaran (tunjukkan ke kasir)' : 'Nomor Virtual Account'}
                            </p>
                            <div className="flex items-center gap-2">
                                <span className="font-mono text-2xl font-bold tracking-wider select-all">{txn.va_number}</span>
                                <Button size="sm" variant="ghost" onClick={() => copy(txn.va_number!)} aria-label="Salin nomor">
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    )}

                    {txn.kind === 'qr' && (
                        <div className="flex flex-col items-center gap-2">
                            {qr ? (
                                <img src={qr} alt="QRIS" className="h-56 w-56 rounded bg-white dark:bg-white p-2" />
                            ) : (
                                <p className="text-sm text-muted-foreground">QR tidak dapat ditampilkan.</p>
                            )}
                            <p className="text-xs text-muted-foreground text-center">Scan dengan aplikasi e-wallet atau m-banking apa pun.</p>
                            {txn.payment_url && (
                                <a href={txn.payment_url} className="text-xs text-primary underline" target="_blank" rel="noreferrer">
                                    Buka halaman QRIS
                                </a>
                            )}
                        </div>
                    )}

                    {txn.kind === 'redirect' && txn.payment_url && (
                        <Button asChild className="w-full gap-2">
                            <a href={txn.payment_url}>
                                <ExternalLink className="h-4 w-4" /> Lanjutkan ke {txn.method_name ?? 'pembayaran'}
                            </a>
                        </Button>
                    )}

                    <div className="space-y-1 border-t pt-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Tagihan</span>
                            <span className="font-data">{formatIdr(txn.amount_invoice)}</span>
                        </div>
                        {txn.fee > 0 && (
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Biaya layanan</span>
                                <span className="font-data">{formatIdr(txn.fee)}</span>
                            </div>
                        )}
                        <div className="flex justify-between font-semibold">
                            <span>Total bayar (harus sama persis)</span>
                            <span className="font-data">{formatIdr(txn.amount_charged)}</span>
                        </div>
                    </div>
                </div>

                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Loader2 className="h-3 w-3 animate-spin" /> Menunggu pembayaran. Status diperbarui otomatis, jangan bayar dua kali.
                </p>
                {!confirmSwitch ? (
                    <Button variant="outline" size="sm" onClick={() => setConfirmSwitch(true)}>
                        Ganti metode pembayaran
                    </Button>
                ) : (
                    <Alert role="alertdialog" aria-labelledby="switch-method-title" aria-describedby="switch-method-desc" className="border-status-degraded/30 bg-status-degraded-bg text-status-degraded" data-testid="switch-method-confirm">
                        <AlertCircle className="h-5 w-5 text-status-degraded" />
                        <AlertTitle id="switch-method-title" className="font-semibold">Ganti metode pembayaran?</AlertTitle>
                        <AlertDescription className="space-y-3">
                            <p id="switch-method-desc">
                                Kode pembayaran sebelumnya masih bisa dibayar sampai {txn.expires_at ? expiryText(txn.expires_at) : 'masa berlakunya habis'}. Jangan bayar dua kali.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Button ref={keepCodeRef} size="sm" variant="outline" onClick={() => setConfirmSwitch(false)}>
                                    Tetap pakai kode ini
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => {
                                        setConfirmSwitch(false);
                                        setTxn(null);
                                    }}
                                >
                                    Ganti metode
                                </Button>
                            </div>
                        </AlertDescription>
                    </Alert>
                )}
            </div>
        );
    }

    return (
        <div className="space-y-4" data-testid="online-methods">
            {returning && (
                <Alert className="border-status-degraded/30 bg-status-degraded-bg text-status-degraded">
                    <Clock className="h-5 w-5 text-status-degraded" />
                    <AlertTitle className="font-semibold">Pembayaran sedang diverifikasi</AlertTitle>
                    <AlertDescription>Jika saldo sudah terpotong, tunggu sebentar; status akan berubah otomatis. Jangan bayar ulang.</AlertDescription>
                </Alert>
            )}
            {txn && txn.status !== 'pending' && txn.status !== 'created' && (
                <Alert>
                    <AlertCircle className="h-4 w-4" />
                    <AlertDescription>Pembayaran sebelumnya {txn.status === 'expired' ? 'sudah kedaluwarsa' : 'tidak berhasil'}. Silakan pilih metode lagi.</AlertDescription>
                </Alert>
            )}
            {activeCodes.length > 0 && (
                <Alert className="border-status-degraded/30 bg-status-degraded-bg text-status-degraded" data-testid="active-codes-banner">
                    <Clock className="h-5 w-5 text-status-degraded" />
                    <AlertTitle className="font-semibold">Kode pembayaran masih aktif</AlertTitle>
                    <AlertDescription className="space-y-2">
                        <p>Jika sudah membayar dengan salah satu kode ini, jangan bayar lagi dengan metode lain.</p>
                        <ul className="space-y-2">
                            {activeCodes.map((c) => {
                                // A code for an older amount (the bill changed meanwhile) would be rejected when paid:
                                // list it (it still counts as active) but never offer it again.
                                const mismatched = c.amount_invoice !== online.remaining;
                                return (
                                    <li key={c.order_id} className="flex flex-wrap items-center justify-between gap-2" data-testid="active-code-row">
                                        <span>
                                            <span className="font-medium">{c.method_name ?? c.method}</span> • {formatIdr(c.amount_charged)}
                                            {c.expires_at ? ` • sampai ${expiryText(c.expires_at)}` : ''}
                                            {mismatched && (
                                                <span className="block text-xs" data-testid="active-code-mismatch">
                                                    Nominal kode ini tidak sesuai sisa tagihan — jangan dibayar. Hubungi layanan pelanggan jika sudah terlanjur membayar.
                                                </span>
                                            )}
                                        </span>
                                        {!mismatched && (
                                            <Button size="sm" variant="outline" onClick={() => isActiveCode(c) && setTxn(c)}>
                                                Tampilkan lagi
                                            </Button>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}
            <p className="text-sm text-muted-foreground">Verifikasi otomatis • Layanan aktif kembali setelah pembayaran diterima</p>

            {methods === null && !loadError && (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="h-4 w-4 animate-spin" /> Memuat metode pembayaran…
                </div>
            )}
            {loadError && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertDescription>
                        {manualFallback ? `${loadError} Silakan bayar melalui Transfer Bank.` : `${loadError} Silakan hubungi layanan pelanggan.`}
                    </AlertDescription>
                </Alert>
            )}
            {methods !== null && methods.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {manualFallback
                        ? 'Belum ada metode pembayaran online. Silakan bayar melalui Transfer Bank.'
                        : 'Belum ada metode pembayaran online. Silakan hubungi layanan pelanggan.'}
                </p>
            )}

            {grouped.map(([group, rows]) => (
                <div key={group} className="space-y-2">
                    <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{GROUP_LABELS[group] ?? group}</h3>
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {rows.map((m) => (
                            <button
                                key={m.code}
                                type="button"
                                onClick={() => setSelected(m.code)}
                                className={`relative flex flex-col items-center gap-1 rounded-lg border-2 p-3 text-center transition-all hover:border-primary ${
                                    selected === m.code ? 'border-primary bg-primary-soft' : 'border-border bg-card'
                                }`}
                            >
                                <MethodIcon name={m.name} imageUrl={m.image_url ?? null} />
                                <span className="text-xs font-medium leading-tight">{m.name}</span>
                                {m.fee > 0 && <span className="text-[10px] text-muted-foreground">+ biaya {formatIdr(m.fee)}</span>}
                            </button>
                        ))}
                    </div>
                </div>
            ))}

            {error && (
                <Alert variant="destructive">
                    <AlertCircle className="h-4 w-4" />
                    <AlertTitle>Pembayaran belum dimulai</AlertTitle>
                    <AlertDescription>{error}</AlertDescription>
                </Alert>
            )}

            {methods !== null && methods.length > 0 && (
                <Button className="w-full" size="lg" onClick={start} disabled={!selected || starting}>
                    {starting ? 'Memproses…' : `Bayar ${formatIdr(selectedOption?.total ?? online.remaining)}`}
                </Button>
            )}
        </div>
    );
}
