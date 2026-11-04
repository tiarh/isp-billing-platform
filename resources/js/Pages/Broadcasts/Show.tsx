import { useMemo, useState } from 'react';
import { EnterpriseResourceLayout } from '@/Components/enterprise/EnterpriseResourceLayout';
import { router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { RefreshCw, AlertCircle, CheckCircle, Clock, PlayCircle, Send } from "lucide-react";
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { campaignStatusLabel, RECIPIENT_STATUS_HINTS, recipientStatusLabel, targetTypeLabel } from '@/lib/waCampaignLabels';
import { formatTime } from '@/lib/format';
import { reloadOnly, usePollWhileVisible } from '@/hooks/usePollWhileVisible';

interface Area {
    id: number;
    name: string;
}

interface Campaign {
    id: number;
    name: string;
    message_template: string;
    status: 'draft' | 'processing' | 'completed' | 'failed' | 'paused';
    target_type: string;
    total_recipients: number;
    sent_count: number;
    failed_count: number;
    unconfirmed_count?: number;
    created_at: string;
    target_area?: Area;
}

interface Customer {
    id: number;
    name: string;
}

interface Recipient {
    id: number;
    wa_campaign_id: number;
    customer_id: number;
    phone_number: string;
    status: 'pending' | 'sending' | 'retrying' | 'sent' | 'failed' | 'unconfirmed';
    error_message: string | null;
    sent_at: string | null;
    customer: Customer;
}

interface Props {
    campaign: Campaign;
    recipients: PaginatedData<Recipient>;
    filters: any;
}

export default function Show({ campaign, recipients, filters = {} }: Props) {
    const campaignRouteParams = useMemo(() => ({ campaign: campaign.id }), [campaign.id]);
    const [retryOpen, setRetryOpen] = useState(false);
    const [retrying, setRetrying] = useState(false);
    const [starting, setStarting] = useState(false);

    // R6: visible-only poll while processing; 3 s → 15 s.
    usePollWhileVisible(() => reloadOnly(['campaign', 'recipients']), 3000, {
        enabled: campaign.status === 'processing',
        backoff: 1.5,
        maxIntervalMs: 15000,
    });

    const handleRetry = () => {
        setRetryOpen(true);
    };

    const confirmRetry = () => {
        setRetrying(true);
        router.post(route('broadcasts.retry', campaign.id), {}, {
            onSuccess: () => setRetryOpen(false),
            onError: () => setRetryOpen(false),
            onFinish: () => setRetrying(false),
        });
    };

    const startDraft = () => {
        setStarting(true);
        router.post(route('broadcasts.start', campaign.id), {}, {
            onFinish: () => setStarting(false),
        });
    };

    const getStatusBadge = (status: string) => {
        const variants: Record<string, string> = {
            completed: 'text-status-up border-status-up/30 bg-status-up-bg',
            processing: 'text-status-info border-status-info/30 bg-status-info-bg',
            failed: 'text-status-down border-status-down/30 bg-status-down-bg',
            draft: 'text-muted-foreground border-border bg-muted',
            paused: 'text-status-degraded border-status-degraded/30 bg-status-degraded-bg',
            sent: 'text-status-up border-status-up/30 bg-status-up-bg',
            pending: 'text-status-info border-status-info/30 bg-status-info-bg',
            sending: 'text-status-info border-status-info/30 bg-status-info-bg',
            retrying: 'text-status-degraded border-status-degraded/30 bg-status-degraded-bg',
            unconfirmed: 'text-status-degraded border-status-degraded/30 bg-status-degraded-bg',
        };
        const className = variants[status] || variants.draft;

        const label = ['pending', 'sending', 'retrying', 'sent', 'failed', 'unconfirmed'].includes(status)
            ? recipientStatusLabel(status)
            : campaignStatusLabel(status);

        return (
            <Badge variant="outline" className={`${className} border`} title={RECIPIENT_STATUS_HINTS[status]}>
                {label}
            </Badge>
        );
    };

    const columns: Column<Recipient>[] = [
        {
            header: "Pelanggan",
            accessorKey: "customer",
            cell: (recipient) => (
                <div className="flex flex-col">
                    <span className="font-medium text-foreground">
                        {recipient.customer ? recipient.customer.name : 'Tidak diketahui'}
                    </span>
                    <span className="text-xs text-muted-foreground font-mono">
                        {recipient.phone_number}
                    </span>
                </div>
            )
        },
        {
            header: "Status",
            accessorKey: "status",
            cell: (recipient) => (
                <div className="flex items-center gap-2">
                    {getStatusBadge(recipient.status)}
                    {recipient.error_message && (
                        <span className="text-xs text-status-down flex items-center gap-1 group relative">
                            <AlertCircle className="h-3.5 w-3.5" />
                            <span className="truncate max-w-[200px]" title={recipient.error_message || ''}>
                                {recipient.error_message}
                            </span>
                        </span>
                    )}
                </div>
            )
        },
        {
            header: "Waktu",
            accessorKey: "sent_at",
            cell: (recipient) => (
                <span className="text-xs text-muted-foreground flex items-center gap-1.5">
                    <Clock className="h-3 w-3" />
                    {recipient.sent_at
                        ? formatTime(recipient.sent_at)
                        : (recipient.status === 'pending' ? 'Menunggu...' : '-')}
                </span>
            )
        }
    ];

    const total = campaign.total_recipients || 1;
    const unconfirmed = campaign.unconfirmed_count ?? 0;
    const processed = campaign.sent_count + campaign.failed_count + unconfirmed;
    const percent = Math.round((processed / total) * 100);

    return (
        <EnterpriseResourceLayout
            title={campaign.name}
            pageTitle={`Kampanye: ${campaign.name}`}
            subtitle={`Target: ${targetTypeLabel(campaign.target_type)}${campaign.target_area ? ` (${campaign.target_area.name})` : ' (Semua Area)'}`}
            icon={Send}
            backHref={route('broadcasts.index')}
            breadcrumbs={[
                { label: 'Broadcast WA', href: route('broadcasts.index') },
                { label: campaign.name },
            ]}
            statusStrip={getStatusBadge(campaign.status)}
            actions={
                <div className="flex items-center gap-3">
                    <Button variant="outline" onClick={() => router.reload()} className="gap-2">
                        <RefreshCw className="h-4 w-4" />
                        Muat Ulang
                    </Button>
                    {campaign.status === 'draft' && (
                        <Button type="button" variant="default" onClick={startDraft} disabled={starting} className="gap-2">
                            <PlayCircle className="h-4 w-4" />
                            {starting ? 'Memulai...' : 'Mulai Broadcast'}
                        </Button>
                    )}
                    {campaign.failed_count > 0 && (
                        <Button variant="destructive" onClick={handleRetry} className="gap-2">
                            <RefreshCw className="h-4 w-4" />
                            Ulang Gagal ({campaign.failed_count})
                        </Button>
                    )}
                </div>
            }
        >
            <div className="space-y-6">
                <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div className="col-span-1 md:col-span-2 bg-card border border-border rounded-xl p-6 shadow-sm">
                        <h3 className="font-semibold text-lg mb-4">Template Pesan</h3>
                        <div className="bg-muted p-4 rounded-lg font-mono text-sm whitespace-pre-wrap">
                            {campaign.message_template}
                        </div>
                    </div>

                    <div className="col-span-1 bg-card border border-border rounded-xl p-6 shadow-sm space-y-6">
                        <h3 className="font-semibold text-lg">Ringkasan Progres</h3>

                        <div className="space-y-2">
                            <div className="flex items-center justify-between text-sm">
                                <span className="font-medium">{percent}% Selesai</span>
                                <span className="text-muted-foreground">
                                    {processed} / {campaign.total_recipients}
                                </span>
                            </div>
                            <div className="h-3 w-full bg-secondary rounded-full overflow-hidden flex">
                                <div className="h-full bg-status-up" style={{ width: `${(campaign.sent_count / total) * 100}%` }}></div>
                                <div className="h-full bg-status-down" style={{ width: `${(campaign.failed_count / total) * 100}%` }}></div>
                                <div className="h-full bg-status-degraded" style={{ width: `${(unconfirmed / total) * 100}%` }}></div>
                                <div className="h-full bg-status-info" style={{ width: `${(Math.max(0, campaign.total_recipients - processed) / total) * 100}%` }}></div>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4 pt-4 border-t border-border">
                            <div>
                                <p className="text-xs text-muted-foreground uppercase tracking-wider mb-1">Berhasil Terkirim</p>
                                <p className="text-2xl font-bold text-status-up flex items-center gap-2">
                                    <CheckCircle className="h-5 w-5" />
                                    {campaign.sent_count}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground uppercase tracking-wider mb-1">Gagal Kirim</p>
                                <p className="text-2xl font-bold text-status-down flex items-center gap-2">
                                    <AlertCircle className="h-5 w-5" />
                                    {campaign.failed_count}
                                </p>
                            </div>
                            {unconfirmed > 0 && (
                                <div className="col-span-2">
                                    <p className="text-xs text-muted-foreground uppercase tracking-wider mb-1">Mungkin terkirim</p>
                                    <p className="text-2xl font-bold text-status-degraded">{unconfirmed}</p>
                                    <p className="text-xs text-muted-foreground mt-1">Cek chat sebelum kirim ulang. Tidak ikut "Ulang Gagal".</p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <DataTable
                    compact
                    data={recipients}
                    columns={columns}
                    filters={{ ...filters, campaign: campaign.id }}
                    searchPlaceholder="Cari nama atau nomor..."
                    title="Log Penerima"
                    description="Riwayat pengiriman pesan untuk kampanye ini"
                    routeName={`broadcasts.show`}
                    routeParams={campaignRouteParams}
                />
            </div>
            <ConfirmDestructive
                open={retryOpen}
                onOpenChange={setRetryOpen}
                title="Konfirmasi pengiriman ulang"
                description={`Ulang kirim ${campaign.failed_count} pesan yang gagal pada kampanye ini?`}
                confirmationLabel="Saya memahami bahwa hanya pesan berstatus gagal yang akan dicoba kembali."
                confirmText="Ulang kirim pesan"
                onConfirm={confirmRetry}
                isLoading={retrying}
            />
        </EnterpriseResourceLayout>
    );
}
