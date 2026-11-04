import PublicLayout from '@/Layouts/PublicLayout';
import { useEffect, useRef, useState } from 'react';
import { PublicPageShell } from '@/Components/enterprise/PublicPageShell';
import { invoiceStatusLabel } from '@/lib/invoiceLabels';
import { isPastDueWib } from '@/lib/dateTime';
import { formatDate, formatIdr, formatMonth } from '@/lib/format';
import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/Components/ui/tabs";
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Separator } from '@/Components/ui/separator';
import { AlertCircle, CheckCircle2, Clock, CreditCard, Building2, Smartphone, Mail, FileText } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/Components/ui/alert';
import OnlinePaymentPanel, { isFallbackStartFailure, type OnlineConfig, type UnavailableReason } from '@/Components/payment/OnlinePaymentPanel';

interface Invoice {
    uuid: string;
    code: string;
    period: string;
    amount: number;
    status: string;
    due_date: string;
    customer: {
        name: string;
    };
    payment_link?: string;
}

interface Channel {
    code: string;
    name: string;
    description: string;
    icon_url: string;
    group: string;
}

interface ManualAccount {
    bank: string;
    account_number: string;
    account_name: string;
}

interface Props {
    invoice: Invoice;
    channels: Channel[];
    midtrans_enabled?: boolean;
    midtrans_client_key?: string | null;
    midtrans_snap_js_url?: string | null;
    payment_result?: string;
    company: {
        name: string;
        address: string;
        wa_admin?: string | null;
    };
    manual_accounts: ManualAccount[];
    // Direct-API provider (Duitku). Null when online payment is off or Midtrans is active.
    online?: OnlineConfig | null;
    from_isolir?: boolean;
    // True when online payment is available for this invoice (gateway active + kill switch ON + payable): manual transfer is hidden.
    online_only?: boolean;
    // Display only: Duitku is the active provider and in sandbox mode.
    sandbox?: boolean;
}

declare global {
    interface Window {
        snap?: {
            pay?: (token: string, options?: {
                onSuccess?: () => void;
                onPending?: () => void;
                onError?: () => void;
                onClose?: () => void;
            }) => void;
            embed?: (token: string, options: { embedId: string }) => void;
        };
    }
}

export default function Show({ invoice, channels = [], midtrans_enabled = false, midtrans_client_key, midtrans_snap_js_url, payment_result, company, manual_accounts, online = null, from_isolir = false, online_only = false, sandbox = false }: Props) {
    const [selectedChannel, setSelectedChannel] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [paymentError, setPaymentError] = useState('');
    // Manual transfer fallback (only when online_only): online payment failed for a system reason.
    const [onlineUnavailable, setOnlineUnavailable] = useState(false);
    const [tab, setTab] = useState('instant');
    const [txnActive, setTxnActive] = useState(false);
    const manualHeadingRef = useRef<HTMLParagraphElement>(null);
    const focusManualRef = useRef(false);
    const hasManual = manual_accounts.length > 0;
    // While a VA/QR is active the customer must stay on it: no fallback, instant tab only.
    const manualFallback = online_only && onlineUnavailable && hasManual && !txnActive;
    const showManual = !online_only || manualFallback;
    const activeTab = txnActive ? 'instant' : tab;

    const handleTxnActive = (active: boolean) => {
        setTxnActive(active);
        if (active) {
            setOnlineUnavailable(false);
            setTab('instant');
        }
    };

    const openManualTab = () => {
        focusManualRef.current = true;
        setTab('manual');
    };

    useEffect(() => {
        if (tab === 'manual' && focusManualRef.current) {
            focusManualRef.current = false;
            // The tab content mounts a frame later (Radix Presence); focus once it exists.
            const id = window.setTimeout(() => manualHeadingRef.current?.focus(), 50);
            return () => window.clearTimeout(id);
        }
        return undefined;
    }, [tab]);

    const handleOnlineUnavailable = (reason: UnavailableReason) => {
        setOnlineUnavailable(true);
        // A failed list load shows the bank accounts right away; a failed start stays on the
        // online tab with an alert + button so the customer keeps their selection.
        if (reason !== 'start_failed' && hasManual) setTab('manual');
    };

    const handlePay = async () => {
        if (!selectedChannel || processing) return;

        setProcessing(true);
        setPaymentError('');
        let failStatus = 0;
        let failReason: string | null = null;

        try {
            const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch(route('public.invoice.pay', invoice.uuid), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ method: selectedChannel }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok || !payload.token) {
                failStatus = response.status;
                failReason = typeof payload.reason === 'string' ? payload.reason : null;
                throw new Error(payload.message || 'Pembayaran belum dapat dimulai.');
            }

            if (!window.snap) {
                await new Promise<void>((resolve, reject) => {
                    const existing = document.querySelector<HTMLScriptElement>('script[data-midtrans-snap]');
                    if (existing) {
                        existing.addEventListener('load', () => resolve(), { once: true });
                        existing.addEventListener('error', () => reject(new Error('Komponen pembayaran belum termuat.')), { once: true });
                        return;
                    }

                    const script = document.createElement('script');
                    script.src = midtrans_snap_js_url || '';
                    script.dataset.midtransSnap = 'true';
                    script.async = true;
                    if (midtrans_client_key) script.setAttribute('data-client-key', midtrans_client_key);
                    script.onload = () => resolve();
                    script.onerror = () => reject(new Error('Komponen pembayaran belum termuat.'));
                    document.head.appendChild(script);
                });
            }

            if (!window.snap) throw new Error('Komponen pembayaran belum siap. Silakan muat ulang halaman.');

            if (typeof window.snap.embed === 'function') {
                window.snap.embed(payload.token, { embedId: 'midtrans-snap-container' });
            } else if (typeof window.snap.pay === 'function') {
                window.snap.pay(payload.token, {
                    onSuccess: () => window.location.reload(),
                    onPending: () => {
                        setProcessing(false);
                        // A pending Snap payment exists: never offer manual transfer next to it, not even after
                        // a later failed retry (txnActive keeps the fallback hidden). The online_only guard is
                        // defensive: the server sets online_only=true whenever the Snap channel is offered, so
                        // on this branch it is always true.
                        setOnlineUnavailable(false);
                        if (online_only) setTxnActive(true);
                    },
                    onError: () => {
                        setPaymentError('Pembayaran gagal diproses. Silakan coba lagi atau hubungi layanan pelanggan.');
                        setProcessing(false);
                        if (online_only) setOnlineUnavailable(true);
                    },
                    onClose: () => setProcessing(false),
                });
            } else {
                throw new Error('Komponen pembayaran tidak menyediakan checkout.');
            }
        } catch (error) {
            setPaymentError(error instanceof Error ? error.message : 'Pembayaran belum dapat dimulai.');
            setProcessing(false);
            if (online_only && isFallbackStartFailure(failStatus, failReason)) setOnlineUnavailable(true);
        }
    };

    const groupedChannels = channels.reduce((acc, channel) => {
        const group = channel.group;
        if (!acc[group]) acc[group] = [];
        acc[group].push(channel);
        return acc;
    }, {} as Record<string, Channel[]>);

    const isOverdue = invoice.status === 'unpaid' && isPastDueWib(invoice.due_date);
    // WhatsApp admin comes from the backend: the isolation-portal setting
    // (same source as /isolir), else the company_wa_admin setting whose
    // default is the number this page used to hardcode.
    const waAdminRaw = (company.wa_admin && company.wa_admin.trim()) || '';
    const waAdminDigits = waAdminRaw.replace(/[^0-9]/g, '').replace(/^0/, '62');
    const waAdminDisplay = waAdminDigits.replace(/^62/, '0').replace(/(\d{4})(?=\d)/g, '$1-');

    const isPaid = invoice.status === 'paid';
    const isVoid = invoice.status === 'void';
    // Only an open invoice may show payment options. Same set as the server
    // guard (PaymentGatewayController::isPayable); a void invoice is never
    // payable, and anything unknown fails closed.
    const isPayable = invoice.status === 'unpaid' || invoice.status === 'overdue';

    const statusBadge = (
        <Badge
            // Red only when actually overdue; an unpaid invoice before its due
            // date is a normal state for the customer, not an alarm.
            variant={isPaid ? 'up' : isVoid ? 'outline' : isOverdue ? 'destructive' : 'secondary'}
            className={`w-fit self-start text-xs px-3 py-1 font-semibold ${isPayable && !isOverdue ? 'bg-status-degraded-bg text-status-degraded hover:bg-status-degraded-bg' : ''}`}
        >
            {invoiceStatusLabel(invoice.status)}
        </Badge>
    );

    return (
        <PublicLayout companyName={company.name}>
            <Head title={`Tagihan ${invoice.code}`} />

            <PublicPageShell
                icon={FileText}
                title={`Tagihan ${invoice.code}`}
                subtitle={company.name}
                statusStrip={statusBadge}
            >
                {sandbox && (
                    <Alert className="border-status-degraded/40 bg-status-degraded-bg text-status-degraded">
                        <AlertTitle className="font-semibold">MODE SANDBOX</AlertTitle>
                        <AlertDescription>
                            Ini lingkungan uji coba pembayaran. Tidak ada uang sungguhan yang dipindahkan.
                        </AlertDescription>
                    </Alert>
                )}
                {isPaid && (
                    <Alert className="border-status-up/30 bg-status-up-bg text-status-up">
                        <CheckCircle2 className="h-5 w-5 text-status-up" />
                        <AlertTitle className="text-status-up font-semibold">Pembayaran Dikonfirmasi</AlertTitle>
                        <AlertDescription className="text-status-up">
                            Terima kasih! Pembayaran Anda sudah diterima dan layanan internet aktif.
                        </AlertDescription>
                    </Alert>
                )}

                {isVoid && (
                    <Alert data-testid="public-invoice-void">
                        <AlertCircle className="h-5 w-5" />
                        <AlertTitle className="font-semibold">Tagihan ini dibatalkan</AlertTitle>
                        <AlertDescription>
                            Tagihan ini sudah dibatalkan dan tidak perlu dibayar. Jangan transfer untuk tagihan ini.
                            Jika ada pertanyaan, hubungi CS kami via WhatsApp {waAdminDisplay}.
                        </AlertDescription>
                    </Alert>
                )}

                {isPayable && payment_result === 'finish' && (
                    <Alert className="border-status-degraded/30 bg-status-degraded-bg text-status-degraded">
                        <Clock className="h-5 w-5 text-status-degraded" />
                        <AlertTitle className="font-semibold">Pembayaran sedang diverifikasi</AlertTitle>
                        <AlertDescription>
                            QRIS sudah kembali ke eBilling. Status akan berubah setelah notifikasi pembayaran diterima.
                            Jangan ulangi pembayaran jika saldo sudah terpotong.
                        </AlertDescription>
                    </Alert>
                )}

                {isOverdue && (
                    <Alert variant="destructive">
                        <AlertCircle className="h-5 w-5" />
                        <AlertTitle className="font-semibold">Tagihan Jatuh Tempo</AlertTitle>
                        <AlertDescription>
                            Layanan dapat terputus. Segera selesaikan pembayaran.
                        </AlertDescription>
                    </Alert>
                )}

                {/* Main Content Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Left Column - Invoice & Payment */}
                    <div className="lg:col-span-2 space-y-6">
                        {/* Invoice Card */}
                        <Card className="border-border">
                            <CardHeader className="space-y-4">
                                <div className="flex items-start justify-between">
                                    <div className="space-y-1">
                                        <CardTitle className="text-2xl font-bold">
                                            Rincian Tagihan
                                        </CardTitle>
                                        <CardDescription className="text-sm">{company.address || company.name}</CardDescription>
                                    </div>
                                    {statusBadge}
                                </div>

                                <Separator />

                                {/* Customer & Date Info */}
                                <div className="grid grid-cols-2 gap-4 text-sm">
                                    <div className="space-y-1">
                                        <p className="text-muted-foreground font-medium">Pelanggan</p>
                                        <p className="font-semibold">{invoice.customer.name}</p>
                                    </div>
                                    <div className="text-right space-y-1">
                                        <p className="text-muted-foreground font-medium">Jatuh Tempo</p>
                                        <p className="font-semibold flex items-center justify-end gap-1.5">
                                            <Clock className="w-4 h-4" />
                                            {formatDate(invoice.due_date)}
                                        </p>
                                        {isOverdue && (
                                            <p className="text-xs text-destructive font-medium">Terlambat</p>
                                        )}
                                    </div>
                                </div>
                            </CardHeader>

                            <CardContent className="space-y-4">
                                <Separator />

                                {/* Line Item */}
                                <div className="flex justify-between items-center py-3">
                                    <div>
                                        <p className="font-medium">Layanan Internet</p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatMonth(invoice.period)}
                                        </p>
                                    </div>
                                    <p className="text-lg font-bold font-data tabular-nums">{formatIdr(invoice.amount)}</p>
                                </div>

                                <Separator />

                                {/* Total */}
                                <div className="flex justify-between items-center py-4 bg-muted/50 -mx-6 px-6 rounded-lg">
                                    <p className="text-lg font-bold">Total Tagihan</p>
                                    <p className="text-2xl font-bold text-primary font-data tabular-nums">{formatIdr(invoice.amount)}</p>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Payment Methods */}
                        {isPayable && (
                            <Card className="border-border">
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2">
                                        <CreditCard className="w-5 h-5" />
                                        Metode Pembayaran
                                    </CardTitle>
                                    <CardDescription>Pilih metode pembayaran yang Anda inginkan</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    {manualFallback && (
                                        <Alert className="mb-4 border-status-degraded/30 bg-status-degraded-bg text-status-degraded" data-testid="manual-fallback-alert">
                                            <AlertCircle className="h-5 w-5 text-status-degraded" />
                                            <AlertTitle className="font-semibold">Pembayaran online sedang tidak tersedia</AlertTitle>
                                            <AlertDescription className="space-y-2">
                                                <p>
                                                    Anda tetap bisa membayar melalui Transfer Bank ke rekening resmi kami. Jika saldo Anda sudah terpotong untuk pembayaran online, jangan transfer lagi; hubungi layanan pelanggan.
                                                </p>
                                                {tab !== 'manual' && (
                                                    <Button type="button" size="sm" variant="outline" onClick={openManualTab}>
                                                        Lihat rekening Transfer Bank
                                                    </Button>
                                                )}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                    <Tabs value={activeTab} onValueChange={setTab} className="w-full">
                                        {showManual && (
                                            <TabsList className="grid w-full grid-cols-2">
                                                <TabsTrigger value="instant" className="gap-2">
                                                    <Smartphone className="w-4 h-4" />
                                                    Bayar Instan
                                                </TabsTrigger>
                                                <TabsTrigger value="manual" className="gap-2">
                                                    <Building2 className="w-4 h-4" />
                                                    Transfer Bank
                                                </TabsTrigger>
                                            </TabsList>
                                        )}

                                        {/* The online panel stays mounted when the customer looks at the bank accounts, so a
                                            created VA / selection is never lost (no second charge). Switch OFF: unchanged. */}
                                        <TabsContent
                                            value="instant"
                                            forceMount={online_only && online ? true : undefined}
                                            className={`mt-6 space-y-4${online_only && online ? ' data-[state=inactive]:hidden' : ''}`}
                                        >
                                            {online ? (
                                                <OnlinePaymentPanel
                                                    invoiceUuid={invoice.uuid}
                                                    online={online}
                                                    fromIsolir={from_isolir}
                                                    returning={payment_result === 'return'}
                                                    onUnavailable={handleOnlineUnavailable}
                                                    manualFallback={manualFallback}
                                                    onTxnActive={handleTxnActive}
                                                />
                                            ) : channels.length > 0 ? (
                                                <>
                                                    <p className="text-sm text-muted-foreground">
                                                        Verifikasi otomatis • Aktivasi langsung
                                                    </p>

                                                    {Object.entries(groupedChannels).map(([group, groupChannels]) => (
                                                        <div key={group} className="space-y-3">
                                                            <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                                                {group}
                                                            </h3>
                                                            <div className="grid grid-cols-3 sm:grid-cols-4 gap-3">
                                                                {groupChannels.map((channel) => (
                                                                    <button
                                                                        key={channel.code}
                                                                        type="button"
                                                                        onClick={() => {
                                                                            setSelectedChannel(channel.code);
                                                                        }}
                                                                        className={`
                                                                            relative flex flex-col items-center gap-2 p-4 rounded-lg border-2 
                                                                            transition-all hover:border-primary hover:bg-accent
                                                                            ${selectedChannel === channel.code
                                                                                ? 'border-primary bg-primary/5 shadow-sm'
                                                                                : 'border-border bg-card'
                                                                            }
                                                                        `}
                                                                    >
                                                                        {channel.icon_url ? (
                                                                            <img
                                                                                src={channel.icon_url}
                                                                                alt={channel.name}
                                                                                className="h-8 w-auto object-contain"
                                                                            />
                                                                        ) : (
                                                                            <Smartphone className="h-8 w-8 text-primary" />
                                                                        )}
                                                                        <span className="text-xs font-medium text-center leading-tight">
                                                                            {channel.name}
                                                                        </span>
                                                                        {channel.description && (
                                                                            <span className="text-[10px] text-muted-foreground text-center">
                                                                                {channel.description}
                                                                            </span>
                                                                        )}
                                                                        {selectedChannel === channel.code && (
                                                                            <div className="absolute -top-1.5 -right-1.5 w-5 h-5 bg-primary rounded-full flex items-center justify-center">
                                                                                <CheckCircle2 className="w-3 h-3 text-primary-foreground" />
                                                                            </div>
                                                                        )}
                                                                    </button>
                                                                ))}
                                                            </div>
                                                        </div>
                                                    ))}

                                                    {paymentError && (
                                                        <Alert variant="destructive">
                                                            <AlertCircle className="h-4 w-4" />
                                                            <AlertTitle>Pembayaran belum dimulai</AlertTitle>
                                                            <AlertDescription>{paymentError}</AlertDescription>
                                                        </Alert>
                                                    )}

                                                    <Button
                                                        className="w-full mt-6"
                                                        size="lg"
                                                        onClick={handlePay}
                                                        disabled={!selectedChannel || processing}
                                                    >
                                                        {processing ? 'Memproses...' : `Bayar ${formatIdr(invoice.amount)}`}
                                                    </Button>
                                                    {processing && (
                                                        <div id="midtrans-snap-container" className="min-h-[520px] rounded-lg border border-border bg-white" />
                                                    )}
                                                </>
                                            ) : (
                                                <div className="text-center py-12">
                                                    <div className="mx-auto w-12 h-12 rounded-full bg-muted flex items-center justify-center mb-3">
                                                        <AlertCircle className="w-6 h-6 text-muted-foreground" />
                                                    </div>
                                                    <p className="font-medium text-muted-foreground mb-1">Belum Ada Kanal Pembayaran</p>
                                                    <p className="text-sm text-muted-foreground">
                                                        {online_only ? 'Silakan hubungi layanan pelanggan.' : 'Gunakan transfer bank atau hubungi dukungan.'}
                                                    </p>
                                                </div>
                                            )}
                                        </TabsContent>

                                        {showManual && (
                                        <TabsContent value="manual" className="mt-6 space-y-4">
                                            {manualFallback && (
                                                <p className="text-sm text-muted-foreground" data-testid="manual-fallback-hint">
                                                    Pembayaran online sedang gangguan; transfer bank diverifikasi manual oleh admin.
                                                </p>
                                            )}
                                            <p
                                                ref={manualHeadingRef}
                                                className={`text-sm text-muted-foreground${manualFallback ? ' focus:outline-none' : ''}`}
                                                {...(manualFallback ? { tabIndex: -1, 'data-testid': 'manual-accounts-heading' } : {})}
                                            >
                                                Transfer ke salah satu rekening berikut
                                            </p>

                                            {manual_accounts.length > 0 ? (
                                                <div className="space-y-3">
                                                    {manual_accounts.map((acc, idx) => (
                                                        <div
                                                            key={idx}
                                                            className="p-4 border border-border rounded-lg bg-card hover:bg-accent transition-colors"
                                                        >
                                                            <div className="flex items-center justify-between mb-2">
                                                                <span className="font-bold text-base">{acc.bank}</span>
                                                                <Building2 className="w-4 h-4 text-muted-foreground" />
                                                            </div>
                                                            <div className="space-y-1">
                                                                <p className="font-mono text-lg font-bold text-primary select-all">
                                                                    {acc.account_number}
                                                                </p>
                                                                <p className="text-sm text-muted-foreground uppercase">
                                                                    {acc.account_name}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    ))}

                                                    <Alert>
                                                        <Mail className="h-4 w-4" />
                                                        <AlertTitle className="text-sm">Konfirmasi Diperlukan</AlertTitle>
                                                        <AlertDescription className="text-xs">
                                                            Setelah transfer, kirim bukti pembayaran via WhatsApp untuk verifikasi.
                                                        </AlertDescription>
                                                    </Alert>

                                                    <Button
                                                        variant="outline"
                                                        className="w-full"
                                                        asChild
                                                    >
                                                        <a
                                                            href={`https://wa.me/${waAdminDigits}?text=${encodeURIComponent(`Saya sudah bayar tagihan ${invoice.code}`)}`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                        >
                                                            Konfirmasi via WhatsApp
                                                        </a>
                                                    </Button>
                                                </div>
                                            ) : (
                                                <p className="text-center text-sm text-muted-foreground py-4">
                                                    Informasi rekening transfer belum tersedia. Silakan hubungi layanan pelanggan.
                                                </p>
                                            )}
                                        </TabsContent>
                                        )}
                                    </Tabs>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right Column - Help Card */}
                    <div className="space-y-6">
                        <Card className="border-border sticky top-24">
                            <CardHeader>
                                <CardTitle className="text-base">Butuh Bantuan?</CardTitle>
                                <CardDescription className="text-xs">Tim dukungan siap membantu Anda</CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-start gap-3 p-3 rounded-lg bg-muted/50">
                                    <div className="w-10 h-10 rounded-full bg-primary-soft flex items-center justify-center flex-shrink-0">
                                        <svg className="w-5 h-5 text-primary-soft-foreground" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                                        </svg>
                                    </div>
                                    <div className="flex-1 min-w-0">
                                        <p className="text-xs text-muted-foreground mb-1">Dukungan WhatsApp</p>
                                        <a
                                            href={`https://wa.me/${waAdminDigits}`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="font-mono text-sm font-bold text-primary hover:underline"
                                        >
                                            {waAdminDisplay}
                                        </a>
                                    </div>
                                </div>

                                <Separator />

                                <div className="text-xs text-muted-foreground space-y-2">
                                    <p className="font-medium">Bantuan Pembayaran:</p>
                                    <ul className="space-y-1 ml-4 list-disc">
                                        <li>Cek ketersediaan kanal pembayaran</li>
                                        <li>Laporkan kendala pembayaran</li>
                                        <li>Minta detail tagihan</li>
                                    </ul>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </PublicPageShell>
        </PublicLayout>
    );
}
