import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { operationKey } from './serial';
import { ActionCardProps } from './types';

export function ExceptionCard({ selected, writesEnabled, operationKeys, onDone }: ActionCardProps) {
    const exceptionForm = useForm({ resolution: 'reopen', reason: '' });

    const handleException = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        exceptionForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `exception-${selected.id}-${data.resolution}`),
        }));
        exceptionForm.post(route('device-recovery.resolve-exception', selected.id), { preserveScroll: true, onSuccess: onDone });
    };

    return <Card className="border-status-down/30"><CardHeader className="pb-3"><CardTitle className="flex items-center gap-2 text-sm text-status-down"><ShieldAlert className="size-4" aria-hidden />Tinjauan exception</CardTitle></CardHeader><CardContent><form onSubmit={handleException} className="space-y-3"><select aria-label="Resolusi exception" value={exceptionForm.data.resolution} onChange={(event) => exceptionForm.setData('resolution', event.target.value)} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="reopen">Buka ulang pengambilan</option><option value="accept">Setujui exception untuk closing</option></select><Textarea aria-label="Alasan resolusi exception" placeholder="Alasan keputusan, minimal 5 karakter" value={exceptionForm.data.reason} onChange={(event) => exceptionForm.setData('reason', event.target.value)} /><FormError errors={exceptionForm.errors} /><Button type="submit" disabled={exceptionForm.processing || exceptionForm.data.reason.trim().length < 5}>{exceptionForm.processing ? 'Memproses...' : 'Simpan resolusi'}</Button></form></CardContent></Card>;
}
