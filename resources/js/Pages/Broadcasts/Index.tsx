import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Eye, PlusCircle, Send } from "lucide-react";
import DataTable, { Column, PaginatedData, FilterConfig } from '@/Components/DataTable';
import { campaignStatusLabel, targetTypeLabel } from '@/lib/waCampaignLabels';
import { formatDateTime } from '@/lib/format';
import { reloadOnly, usePollWhileVisible } from '@/hooks/usePollWhileVisible';

interface Area {
    id: number;
    name: string;
}

interface Campaign {
    id: number;
    name: string;
    status: 'draft' | 'processing' | 'completed' | 'failed' | 'paused';
    target_type: string;
    total_recipients: number;
    sent_count: number;
    failed_count: number;
    unconfirmed_count?: number;
    created_at: string;
    target_area?: Area;
}

interface Props {
    campaigns: PaginatedData<Campaign>;
    filters: any;
}

export default function Index({ campaigns, filters }: Props) {
    // R6: poll only while a campaign is processing and the tab is visible; 3 s → 15 s.
    const hasProcessing = campaigns.data.some(c => c.status === 'processing');
    usePollWhileVisible(() => reloadOnly(['campaigns']), 3000, {
        enabled: hasProcessing,
        backoff: 1.5,
        maxIntervalMs: 15000,
    });

    const getStatusBadge = (status: string) => {
        const variants: Record<string, string> = {
            completed: 'text-status-up border-status-up/30 bg-status-up-bg',
            processing: 'text-status-info border-status-info/30 bg-status-info-bg',
            failed: 'text-status-down border-status-down/30 bg-status-down-bg',
            draft: 'text-muted-foreground border-border bg-muted',
            paused: 'text-status-degraded border-status-degraded/30 bg-status-degraded-bg',
        };
        const className = variants[status] || variants.draft;

        return (
            <Badge variant="outline" className={`${className} border`}>
                {campaignStatusLabel(status)}
            </Badge>
        );
    };

    const columns: Column<Campaign>[] = [
        {
            header: "Nama",
            accessorKey: "name",
            cell: (campaign) => (
                <div className="flex flex-col">
                    <span className="font-semibold text-foreground">{campaign.name}</span>
                    <span className="text-xs text-muted-foreground">
                        Target: {targetTypeLabel(campaign.target_type)}
                        {campaign.target_area ? ` (${campaign.target_area.name})` : ' (Semua Area)'}
                    </span>
                </div>
            )
        },
        {
            header: "Tanggal",
            accessorKey: "created_at",
            cell: (campaign) => (
                <span className="text-sm">
                    {formatDateTime(campaign.created_at)}
                </span>
            )
        },
        {
            header: "Progres",
            wrap: true,
            accessorKey: "sent_count",
            cell: (campaign) => {
                const total = campaign.total_recipients || 1;
                const percent = Math.round(((campaign.sent_count + campaign.failed_count + (campaign.unconfirmed_count ?? 0)) / total) * 100);

                return (
                    <div className="flex flex-col gap-1 w-full max-w-[150px]">
                        <div className="flex flex-wrap items-center justify-between gap-x-3 text-xs">
                            <span className="text-status-up font-medium">{campaign.sent_count} terkirim</span>
                            {campaign.failed_count > 0 && <span className="text-status-down font-medium">{campaign.failed_count} gagal</span>}
                            {(campaign.unconfirmed_count ?? 0) > 0 && <span className="text-status-degraded font-medium">{campaign.unconfirmed_count} mungkin terkirim</span>}
                            <span className="text-muted-foreground">{campaign.total_recipients} total</span>
                        </div>
                        <div className="h-1.5 w-full bg-secondary rounded-full overflow-hidden">
                            <div className="h-full bg-primary" style={{ width: `${percent}%` }}></div>
                        </div>
                    </div>
                );
            }
        },
        {
            header: "Status",
            accessorKey: "status",
            cell: (campaign) => getStatusBadge(campaign.status)
        },
        {
            header: "Aksi",
            className: "text-right",
            cell: (campaign) => (
                <div className="flex items-center justify-end gap-2" onClick={(e) => e.stopPropagation()}>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-primary hover:bg-primary-soft hover:text-primary-soft-foreground"
                        title="Lihat Detail"
                        aria-label="Lihat Detail"
                        onClick={() => router.visit(route('broadcasts.show', campaign.id))}
                    >
                        <Eye className="h-4 w-4" />
                    </Button>
                </div>
            )
        }
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Semua Status',
            options: [
                { label: 'Memproses', value: 'processing' },
                { label: 'Selesai', value: 'completed' },
                { label: 'Gagal', value: 'failed' },
                { label: 'Draf', value: 'draft' },
                { label: 'Dijeda', value: 'paused' },
            ]
        }
    ];

    return (
        <EnterpriseIndexLayout
            title="Broadcast WA"
            pageTitle="Broadcast WhatsApp"
            subtitle="Kelola kampanye pesan WhatsApp massal"
            icon={Send}
            breadcrumbs={[{ label: 'Broadcast WA' }]}
            actions={
                <Link href={route('broadcasts.create')}>
                    <Button size="sm" className="h-9 gap-2">
                        <PlusCircle className="h-4 w-4" />
                        Kampanye Baru
                    </Button>
                </Link>
            }
        >
            <DataTable
                compact
                data={campaigns}
                columns={columns}
                filters={filters}
                filterConfigs={filterConfigs}
                title=""
                description={`Menampilkan ${campaigns.data.length} dari ${campaigns.total} kampanye`}
                searchPlaceholder="Cari kampanye..."
                routeName="broadcasts.index"
                onRowClick={(item: Campaign) => router.visit(route('broadcasts.show', item.id))}
            />
        </EnterpriseIndexLayout>
    );
}
