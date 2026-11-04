import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useForm } from '@inertiajs/react';
import { UserRound } from 'lucide-react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { operationKey, toDateTimeLocal } from './serial';
import { ActionCardProps, TechnicianOption } from './types';

export function AssignCard({ selected, detail, writesEnabled, operationKeys, onDone, technicians }: ActionCardProps & { technicians: TechnicianOption[] }) {
    const assignForm = useForm({ assigned_to: selected.assigned_to ? String(selected.assigned_to.id) : '', scheduled_at: toDateTimeLocal(selected.scheduled_at) });

    const handleAssign = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        assignForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `assign-${selected.id}-${data.assigned_to}-${data.scheduled_at}`),
        }));
        assignForm.post(route('device-recovery.assign', selected.id), { preserveScroll: true, onSuccess: onDone });
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="flex items-center gap-2 text-sm"><UserRound className="size-4" aria-hidden />Penugasan</CardTitle></CardHeader><CardContent><form onSubmit={handleAssign} className="space-y-3"><select aria-label="Pilih teknisi recovery" value={assignForm.data.assigned_to || String(detail.assigned_to?.id || '')} onChange={(event) => assignForm.setData('assigned_to', event.target.value)} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="">Pilih teknisi</option>{technicians.map((technician) => <option key={technician.id} value={technician.id}>{technician.name}</option>)}</select><Input type="datetime-local" aria-label="Jadwal pengambilan" value={assignForm.data.scheduled_at} onChange={(event) => assignForm.setData('scheduled_at', event.target.value)} /><FormError errors={assignForm.errors} /><Button type="submit" disabled={assignForm.processing || !assignForm.data.assigned_to}>{assignForm.processing ? 'Menyimpan...' : 'Simpan penugasan'}</Button></form></CardContent></Card>;
}
