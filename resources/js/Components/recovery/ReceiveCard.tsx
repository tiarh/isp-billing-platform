import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { PhotoPicker } from './PhotoPicker';
import { operationKey, serialsMatch } from './serial';
import { ActionCardProps } from './types';

export function ReceiveCard({ selected, detail, writesEnabled, operationKeys, onDone, photoBusy, setPhotoBusy }: ActionCardProps & {
    photoBusy: boolean;
    setPhotoBusy: (busy: boolean) => void;
}) {
    const receiveForm = useForm({ reason: '', serials: {} as Record<string, string>, receipt_photos: [] as File[], submit_as_exception: false });

    const handleReceive = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        receiveForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `receive-${selected.id}-${detail?.version ?? 0}-${data.submit_as_exception ? 1 : 0}`),
        }));
        receiveForm.post(route('device-recovery.receive', selected.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                receiveForm.reset();
                onDone();
            },
        });
    };

    const receiveMismatch = detail.items.some((item) => (receiveForm.data.serials[String(item.id)] || '').trim() !== '' && !serialsMatch(receiveForm.data.serials[String(item.id)], item.captured_serial_number));

    return <Card><CardHeader className="pb-3"><CardTitle className="flex items-center gap-2 text-sm"><ClipboardCheck className="size-4" aria-hidden />Penerimaan gudang</CardTitle></CardHeader><CardContent><form onSubmit={handleReceive} className="space-y-4">
        <p className="text-xs text-muted-foreground">Ketik atau scan SN pada fisik perangkat. Harus sama dengan SN yang dicatat teknisi. Setelah diterima, tiket dismantle ditutup otomatis.</p>
        {detail.items.map((item) => {
            const typed = receiveForm.data.serials[String(item.id)] || '';
            const matched = serialsMatch(typed, item.captured_serial_number);
            return <div key={item.id} className="space-y-1.5"><label htmlFor={`recovery-receive-sn-${item.id}`} className="text-sm font-medium">SN fisik {item.device_type.toUpperCase()} <span className="font-mono text-xs text-muted-foreground">(dicatat: {item.captured_serial_number || '-'})</span></label><Input id={`recovery-receive-sn-${item.id}`} autoCapitalize="characters" autoComplete="off" placeholder="Ketik / scan SN" value={typed} onChange={(event) => receiveForm.setData('serials', { ...receiveForm.data.serials, [String(item.id)]: event.target.value })} className="h-11 font-mono text-base sm:h-9 sm:text-sm" />{typed.trim() !== '' && <p className={matched ? 'text-xs text-status-up' : 'text-xs text-status-down'}>{matched ? 'SN cocok.' : 'SN tidak cocok dengan catatan teknisi.'}</p>}</div>;
        })}
        <PhotoPicker id="recovery-photo-receipt" label="Foto penerimaan gudang" hint="Foto perangkat yang diterima di gudang (label SN terlihat)." files={receiveForm.data.receipt_photos} max={3} required onChange={(files) => receiveForm.setData('receipt_photos', files)} onBusy={setPhotoBusy} />
        <Textarea aria-label="Catatan penerimaan gudang" placeholder="Catatan penerimaan (opsional)" value={receiveForm.data.reason} onChange={(event) => receiveForm.setData('reason', event.target.value)} />
        {receiveMismatch && <label className="flex items-start gap-2 rounded-md border border-status-down/30 bg-status-down-bg p-3 text-sm"><input type="checkbox" className="mt-1" checked={receiveForm.data.submit_as_exception} onChange={(event) => receiveForm.setData('submit_as_exception', event.target.checked)} /><span>SN memang berbeda: ajukan sebagai exception (&quot;SN gudang tidak cocok&quot;) untuk ditinjau admin global.</span></label>}
        <FormError errors={receiveForm.errors} />
        <Button type="submit" className="h-11 w-full sm:h-9 sm:w-auto" disabled={receiveForm.processing || photoBusy || receiveForm.data.receipt_photos.length === 0 || detail.items.some((item) => (receiveForm.data.serials[String(item.id)] || '').trim() === '')}>{receiveForm.processing ? 'Memproses...' : receiveMismatch && receiveForm.data.submit_as_exception ? 'Ajukan exception SN gudang' : 'Tandai diterima gudang'}</Button>
    </form></CardContent></Card>;
}
