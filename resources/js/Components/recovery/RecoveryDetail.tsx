import { formatDateTime } from '@/lib/format';
import { useState } from 'react';
import { AddItemCard } from './AddItemCard';
import { AssignCard } from './AssignCard';
import { CancelCard } from './CancelCard';
import { CaptureCard } from './CaptureCard';
import { ExceptionCard } from './ExceptionCard';
import { ItemCard } from './ItemCard';
import { KeepActiveCard } from './KeepActiveCard';
import { QuickRecordCard } from './QuickRecordCard';
import { ReceiveCard } from './ReceiveCard';
import { ReconciliationPanel } from './ReconciliationPanel';
import { RecoveryStatusChip } from './RecoveryStatusChip';
import { RecoveryTimeline } from './RecoveryTimeline';
import { ActionCardProps, RecoveryUser, TechnicianOption } from './types';

// The parent mounts this under key={detail.id}, so every action card starts
// with a fresh form each time a case is opened (replaces the manual resets).
export function RecoveryDetail({ selected, detail, writesEnabled, operationKeys, onDone, user, isAdminLike, isGlobalAdmin, technicians, photoBusy, setPhotoBusy }: ActionCardProps & {
    user: RecoveryUser;
    isAdminLike: boolean;
    isGlobalAdmin: boolean;
    technicians: TechnicianOption[];
    photoBusy: boolean;
    setPhotoBusy: (busy: boolean) => void;
}) {
    const [editingItemId, setEditingItemId] = useState<number | null>(null);
    const card = { selected, detail, writesEnabled, operationKeys, onDone };
    const preAssignment = ['waiting_assignment', 'assigned', 'scheduled'].includes(detail.status);

    return (
        <div className="space-y-6">
            <div className="space-y-2"><RecoveryStatusChip item={detail} /><p className="text-sm text-muted-foreground">Status tiket: <span className="font-medium text-foreground">{detail.ticket?.status || '—'}</span>. Status ini terpisah dari status fisik.</p>{detail.ticket?.dismantle_reason_label && <p className="text-sm text-muted-foreground">Alasan dismantle: <span className="font-medium text-foreground">{detail.ticket.dismantle_reason_label}</span>{detail.ticket.dismantle_reason_note ? <span className="block whitespace-pre-wrap text-xs">{detail.ticket.dismantle_reason_note}</span> : null}</p>}</div>
            <div className="grid gap-3 rounded-lg border p-4 text-sm sm:grid-cols-2"><div><p className="text-xs text-muted-foreground">Customer</p><p className="font-medium">{detail.customer?.name || '—'}</p><p className="font-mono text-xs">{detail.customer?.code || '—'}</p></div><div><p className="text-xs text-muted-foreground">Area</p><p>{detail.area?.name || '—'}</p></div><div><p className="text-xs text-muted-foreground">Teknisi</p><p>{detail.assigned_to?.name || 'Belum ditugaskan'}</p></div><div><p className="text-xs text-muted-foreground">Jadwal</p><p>{formatDateTime(detail.scheduled_at)}</p></div></div>

            {detail.pending_termination && <div className="rounded-lg border border-status-degraded/30 bg-status-degraded-bg p-3 text-sm text-foreground">Pelanggan belum diterminate (ditunda): {detail.pending_termination.reason_label}. Dicek ulang otomatis tiap jam; tagihan baru ditahan.</div>}

            <ReconciliationPanel data={detail.reconciliation} />

            {detail.items.map((item) => <ItemCard key={item.id} item={item} canEdit={writesEnabled && isAdminLike && preAssignment} editing={editingItemId === item.id} onEdit={() => setEditingItemId(item.id)} onClose={() => setEditingItemId(null)} selected={selected} writesEnabled={writesEnabled} operationKeys={operationKeys} onDone={onDone} />)}

            {isAdminLike && preAssignment && <AssignCard {...card} technicians={technicians} />}

            {isAdminLike && preAssignment && detail.items.length === 1 && detail.items[0].status === 'pending' && <QuickRecordCard {...card} technicians={technicians} photoBusy={photoBusy} setPhotoBusy={setPhotoBusy} />}

            {isAdminLike && preAssignment && <AddItemCard {...card} />}

            {writesEnabled && (user.role === 'superadmin' || user.role === 'teknisi' && detail.assigned_to?.id === user.id) && ['assigned', 'scheduled', 'pickup_started', 'exception'].includes(detail.status) && detail.items.some((item) => item.status === 'pending') && <CaptureCard {...card} photoBusy={photoBusy} setPhotoBusy={setPhotoBusy} />}

            {isAdminLike && detail.status === 'carried' && <ReceiveCard {...card} photoBusy={photoBusy} setPhotoBusy={setPhotoBusy} />}

            {detail.status === 'exception' && isGlobalAdmin && <ExceptionCard {...card} />}

            {!['cancelled'].includes(detail.status) && <KeepActiveCard {...card} isAdminLike={isAdminLike} />}

            {isAdminLike && !['received', 'exception_accepted', 'cancelled'].includes(detail.status) && <CancelCard {...card} />}

            <RecoveryTimeline events={detail.events} />
        </div>
    );
}
