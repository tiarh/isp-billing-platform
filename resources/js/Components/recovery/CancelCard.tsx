import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { operationKey } from './serial';
import { ActionCardProps } from './types';

export function CancelCard({ selected, writesEnabled, operationKeys, onDone }: ActionCardProps) {
    const cancelForm = useForm({ reason: '' });

    const handleCancel = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        cancelForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `cancel-${selected.id}`),
        }));
        cancelForm.post(route('device-recovery.cancel', selected.id), { preserveScroll: true, onSuccess: onDone });
    };

    return <Card className="border-status-down/30"><CardHeader className="pb-3"><CardTitle className="text-sm text-status-down">Batalkan case</CardTitle></CardHeader><CardContent><form onSubmit={handleCancel} className="space-y-3"><Textarea aria-label="Alasan pembatalan recovery" placeholder="Alasan pembatalan, minimal 5 karakter" value={cancelForm.data.reason} onChange={(event) => cancelForm.setData('reason', event.target.value)} /><FormError errors={cancelForm.errors} /><Button type="submit" variant="destructive" disabled={cancelForm.processing || cancelForm.data.reason.trim().length < 5}>{cancelForm.processing ? 'Memproses...' : 'Batalkan recovery'}</Button></form></CardContent></Card>;
}
