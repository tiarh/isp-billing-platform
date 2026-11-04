import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { formatDateTime } from '@/lib/format';
import { ReconciliationSource, RecoveryReconciliation } from './types';

export function ReconciliationPanel({ data }: { data?: RecoveryReconciliation | null }) {
    if (!data) return null;

    const sourceState = (source: ReconciliationSource) => {
        if (!source.available) return 'Snapshot belum tersedia';
        if (source.ambiguous) return `${source.candidate_count} kandidat; perlu verifikasi`;
        if (!source.matched) return 'Tidak ada kecocokan';

        return source.stale ? 'Cocok, tetapi snapshot stale' : 'Cocok';
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="text-sm">Rekonsiliasi read-only</CardTitle></CardHeader><CardContent className="space-y-3 text-sm"><p className="text-xs text-muted-foreground">Snapshot database saja. Panel ini tidak menulis OLT atau GenieACS.</p><div className="grid gap-3 md:grid-cols-3"><div className="rounded-md border p-3"><p className="font-medium">Data customer</p><p className="mt-1 text-xs">{data.customer_device.expected_serial_number || 'SN tidak tersedia'}</p><p className="text-xs text-muted-foreground">{data.customer_device.olt_port_label || 'Port OLT tidak tersedia'} · {data.customer_device.olt_status || 'Status tidak tersedia'}</p><p className="mt-2 text-xs">{sourceState(data.customer_device)}</p><p className="text-xs text-muted-foreground">Sinkron: {formatDateTime(data.customer_device.last_synced_at)}</p></div><div className="rounded-md border p-3"><p className="font-medium">OLT ONU</p><p className="mt-1 text-xs">{data.olt_onu.onu?.serial_number || 'SN tidak cocok'}</p><p className="text-xs text-muted-foreground">{data.olt_onu.onu?.onu_name || data.olt_onu.onu?.onu_ref || 'ONU tidak tersedia'}</p><p className="mt-2 text-xs">{sourceState(data.olt_onu)}</p><p className="text-xs text-muted-foreground">Sinkron: {formatDateTime(data.olt_onu.last_synced_at)}</p></div><div className="rounded-md border p-3"><p className="font-medium">GenieACS</p><p className="mt-1 text-xs">{data.genieacs.device?.serial_number || 'SN tidak cocok'}</p><p className="text-xs text-muted-foreground">{data.genieacs.device?.manufacturer || 'Vendor tidak tersedia'} {data.genieacs.device?.model || ''}</p><p className="mt-2 text-xs">{sourceState(data.genieacs)}</p><p className="text-xs text-muted-foreground">Sinkron: {formatDateTime(data.genieacs.last_synced_at)}</p></div></div></CardContent></Card>;
}
