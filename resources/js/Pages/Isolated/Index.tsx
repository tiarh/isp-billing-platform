import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CircleCheck, CircleHelp, LoaderCircle, WifiOff } from 'lucide-react';
import { formatIdr, formatMonth } from '@/lib/format';

type PortalState = 'isolated' | 'paid_recovering' | 'not_isolated' | 'unknown';

interface PortalStatus {
    name?: string | null;
    customer_code?: string | null;
    wa_admin?: string | null;
    wa_digits?: string | null;
    state?: PortalState | null;
    office_address?: string | null;
    office_hours?: string | null;
    is_isolated: boolean;
    portal_type: 'customer' | 'office' | null;
    message: string | null;
    period: string | null;
    amount: number | null;
    payment_url: string | null;
}

interface Props extends PortalStatus {}

// P2: sama dengan IsolatedController::waDigits() — 0812… → 62812…, minimal 8 digit.
function waDigits(status: PortalStatus): string {
    const fromServer = (status.wa_digits ?? '').replace(/[^0-9]/g, '');
    if (fromServer !== '') return fromServer;

    let digits = (status.wa_admin ?? '').replace(/[^0-9]/g, '');
    if (digits.startsWith('0')) digits = `62${digits.slice(1)}`;

    return digits.length >= 8 ? digits : '';
}

// P3: payload lama (tanpa `state`) tetap dipetakan dengan benar.
function resolveState(status: PortalStatus): PortalState {
    if (status.state) return status.state;
    if (status.is_isolated) return 'isolated';

    return status.message ? 'paid_recovering' : 'unknown';
}

const RECOVERY_PROMISE = 'paling lambat 5 menit';

export default function Index(initialStatus: Props) {
    const [status, setStatus] = useState<PortalStatus>(initialStatus);

    useEffect(() => {
        let active = true;
        let timer: number | undefined;

        const poll = async () => {
            const url = new URL(route('public.isolated.status'), window.location.origin);
            const ip = new URLSearchParams(window.location.search).get('ip');

            if (ip) {
                url.searchParams.set('ip', ip);
            }

            try {
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (active && response.ok) {
                    setStatus(await response.json());
                }
            } catch {
                // The next jittered 30-second poll retries without interrupting the portal.
            }
        };

        const schedulePoll = () => {
            timer = window.setTimeout(async () => {
                if (!active) {
                    return;
                }

                await poll();
                if (active) {
                    schedulePoll();
                }
            }, 30_000 + Math.floor(Math.random() * 5_001));
        };

        schedulePoll();

        return () => {
            active = false;
            if (timer !== undefined) {
                window.clearTimeout(timer);
            }
        };
    }, []);

    const state = resolveState(status);
    const waNumber = waDigits(status);
    const period = status.period ? formatMonth(status.period) : 'tagihan berjalan';
    const isolatedTitle = status.message ?? `Internet Diisolir — Tagihan ${period}`;
    const settledTitle = status.message ?? 'Pembayaran Diterima';
    const otherStates: Record<Exclude<PortalState, 'isolated'>, { title: string; body: string }> = {
        paid_recovering: {
            title: settledTitle,
            body: `Terima kasih. Pembayaran sudah tercatat dan koneksi internet akan terbuka otomatis ${RECOVERY_PROMISE}.`,
        },
        not_isolated: {
            title: 'Koneksi Anda normal',
            body: 'Koneksi ini tidak sedang diisolir. Jika internet tetap tidak bisa dipakai, hubungi admin.',
        },
        unknown: {
            title: 'Status koneksi belum dapat dipastikan',
            body: 'Kami belum bisa mencocokkan koneksi ini dengan data pelanggan. Jika internet Anda terputus, hubungi admin via WhatsApp atau datang ke kantor.',
        },
    };
    const other = state === 'isolated' ? null : otherStates[state];
    const iconTone =
        state === 'isolated'
            ? 'bg-status-down-bg text-status-down'
            : state === 'unknown'
                ? 'bg-muted text-muted-foreground'
                : 'bg-status-up-bg text-status-up';

    return (
        <>
            <Head title={status.is_isolated ? isolatedTitle : other?.title ?? settledTitle} />
            <main className="min-h-screen bg-background px-5 py-10 text-foreground sm:px-6">
                <div className="mx-auto flex min-h-[80vh] max-w-lg flex-col justify-center">
                    <section className="rounded-xl border border-border bg-card p-6 text-center shadow-sm sm:p-10" aria-live="polite">
                        <div className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full ${iconTone}`}>
                            {state === 'isolated' ? (
                                <WifiOff className="h-8 w-8" aria-hidden="true" />
                            ) : state === 'unknown' ? (
                                <CircleHelp className="h-8 w-8" aria-hidden="true" />
                            ) : (
                                <CircleCheck className="h-8 w-8" aria-hidden="true" />
                            )}
                        </div>

                        {state === 'isolated' ? (
                            <>
                                <h1 className="mt-6 text-2xl font-semibold tracking-tight">{isolatedTitle}</h1>
                                {status.name ? (
                                    <p className="mt-1 text-sm font-medium text-muted-foreground">{status.name}{status.customer_code ? ` · ID ${status.customer_code}` : ''}</p>
                                ) : null}
                                {status.portal_type !== 'office' && (
                                    <>
                                        <p className="mt-2 text-sm text-muted-foreground">Tagihan {period}</p>
                                        <p className="mt-8 font-data text-4xl font-semibold tabular-nums tracking-tight">{formatIdr(status.amount)}</p>
                                        {status.payment_url && (
                                            <a
                                                href={status.payment_url}
                                                className="mt-8 inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-primary px-5 py-3 font-medium text-primary-foreground transition-colors hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-background"
                                            >
                                                Bayar Sekarang
                                            </a>
                                        )}
                                    </>
                                )}
                                <div className="mt-6 space-y-1 rounded-lg border border-border bg-muted p-4 text-left text-sm text-muted-foreground">
                                    <p className="font-semibold text-foreground">Bisa bayar tunai di kantor</p>
                                    {(status.office_address ?? '') !== '' ? <p>{status.office_address}</p> : null}
                                    {(status.office_hours ?? '') !== '' ? <p>Jam: {status.office_hours}</p> : null}
                                    <p>Tunjukkan halaman ini.</p>
                                </div>
                            </>
                        ) : (
                            <>
                                <h1 className="mt-6 text-2xl font-semibold tracking-tight">{other?.title}</h1>
                                <p className="mt-3 text-sm leading-6 text-muted-foreground">{other?.body}</p>
                            </>
                        )}
                    </section>

                    {waNumber !== '' && (
                        <a
                            href={'https://wa.me/' + waNumber}
                            target="_blank"
                            rel="noreferrer"
                            className="mt-4 flex min-h-12 w-full items-center justify-center rounded-lg border border-border bg-card px-5 py-3 text-center font-medium text-foreground transition-colors hover:bg-muted"
                        >
                            WhatsApp Admin
                        </a>
                    )}
                    <footer className="mt-6 flex flex-col items-center justify-center gap-2 text-center text-xs text-muted-foreground">
                        {(state === 'isolated' || state === 'paid_recovering') && (
                            <span>Setelah bayar aktif otomatis {RECOVERY_PROMISE}.</span>
                        )}
                        <span className="flex items-center gap-2">
                            <LoaderCircle className="h-3.5 w-3.5" aria-hidden="true" />
                            Memeriksa status setiap 30 detik
                        </span>
                    </footer>
                </div>
            </main>
        </>
    );
}
