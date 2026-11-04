import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { FormError } from './FormError';
import { PhotoPicker } from './PhotoPicker';
import { operationKey } from './serial';
import { ActionCardProps } from './types';

export function CaptureCard({ selected, detail, writesEnabled, operationKeys, onDone, photoBusy, setPhotoBusy }: ActionCardProps & {
    photoBusy: boolean;
    setPhotoBusy: (busy: boolean) => void;
}) {
    const [captureItemId, setCaptureItemId] = useState<number | null>(detail.items.find((recoveryItem) => recoveryItem.status === 'pending')?.id || null);
    const captureForm = useForm({ serial_number: '', condition: 'good', reason: '', label_sn_photos: [] as File[], device_photos: [] as File[] });
    const [captureNotice, setCaptureNotice] = useState('');

    const handleCapture = (event: FormEvent) => {
        event.preventDefault();
        const item = detail?.items.find((recoveryItem) => recoveryItem.id === captureItemId);
        if (!writesEnabled || !item) return;
        captureForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `capture-${selected.id}-${item.id}-${detail?.version ?? 0}`),
        }));
        setCaptureNotice('');
        let settled = false;
        // Owner 2026-10-09: on any failure the SN, catatan and photos stay in
        // the form so the technician can simply resend.
        captureForm.post(route('device-recovery.capture', [selected.id, item.id]), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                settled = true;
                captureForm.reset();
                onDone();
            },
            onError: () => {
                settled = true;
                setCaptureNotice('Data belum tersimpan. Perbaiki isian lalu kirim ulang; foto tidak perlu diambil ulang.');
            },
            onFinish: () => {
                if (!settled) setCaptureNotice('Upload gagal (koneksi terputus?). Data dan foto masih ada di form, silakan kirim ulang.');
            },
        });
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="text-sm">Catat pengambilan perangkat</CardTitle></CardHeader><CardContent><form onSubmit={handleCapture} className="space-y-4">
        {detail.status === 'exception' && <p className="text-xs text-status-down">Satu atau lebih perangkat perlu tinjauan; selesaikan item pending sebelum mengajukan resolusi exception.</p>}
        {detail.items.filter((item) => item.status === 'pending').length > 1 && <select aria-label="Pilih perangkat yang dicatat" value={captureItemId || ''} onChange={(event) => setCaptureItemId(Number(event.target.value))} className="h-11 w-full rounded-md border border-input bg-background px-3 text-base sm:h-9 sm:text-sm"><option value="">Pilih perangkat</option>{detail.items.filter((item) => item.status === 'pending').map((item) => <option key={item.id} value={item.id}>{item.device_type.toUpperCase()} · {item.expected_serial_number || 'SN expected tidak tersedia'}</option>)}</select>}
        <div className="space-y-1.5"><label htmlFor="recovery-capture-sn" className="text-sm font-medium">SN perangkat</label><Input id="recovery-capture-sn" autoCapitalize="characters" autoComplete="off" placeholder="SN pada label perangkat" value={captureForm.data.serial_number} onChange={(event) => captureForm.setData('serial_number', event.target.value)} className="h-11 font-mono text-base sm:h-9 sm:text-sm" /></div>
        <div className="space-y-1.5"><label htmlFor="recovery-capture-condition" className="text-sm font-medium">Kondisi</label><select id="recovery-capture-condition" value={captureForm.data.condition} onChange={(event) => captureForm.setData('condition', event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-base sm:h-9 sm:text-sm"><option value="good">Baik dan SN cocok</option><option value="damaged">Rusak</option><option value="unknown">SN tidak terbaca</option><option value="lost">Hilang / tidak ada di lokasi</option></select></div>
        <div className="space-y-1.5"><label htmlFor="recovery-capture-note" className="text-sm font-medium">Catatan{captureForm.data.condition !== 'good' && <span className="text-status-down"> *</span>}</label><Textarea id="recovery-capture-note" placeholder={captureForm.data.condition === 'good' ? 'Catatan (opsional)' : 'Wajib diisi: jelaskan kondisi / kenapa perangkat tidak ada'} value={captureForm.data.reason} onChange={(event) => captureForm.setData('reason', event.target.value)} className="text-base sm:text-sm" /></div>
        <PhotoPicker id="recovery-photo-label-sn" label="Foto label SN" hint="Foto stiker SN/MAC perangkat, harus terbaca." files={captureForm.data.label_sn_photos} max={2} required={captureForm.data.condition !== 'lost'} onChange={(files) => captureForm.setData('label_sn_photos', files)} onBusy={setPhotoBusy} />
        <PhotoPicker id="recovery-photo-device" label="Foto perangkat utuh" hint="Foto perangkat beserta adaptor/kelengkapan yang dibawa." files={captureForm.data.device_photos} max={3} required={captureForm.data.condition !== 'lost'} onChange={(files) => captureForm.setData('device_photos', files)} onBusy={setPhotoBusy} />
        {captureForm.data.condition === 'lost' && <p className="text-xs text-muted-foreground">Perangkat hilang: foto boleh kosong, catatan wajib. Case masuk tinjauan exception.</p>}
        <FormError errors={captureForm.errors} />
        {captureNotice && <p className="text-sm text-status-degraded" role="status">{captureNotice}</p>}
        {captureForm.progress && <p className="text-xs text-muted-foreground">Mengunggah {captureForm.progress.percentage ?? 0}%</p>}
        <Button type="submit" className="h-11 w-full sm:h-9" disabled={captureForm.processing || photoBusy || !captureItemId || captureForm.data.condition !== 'lost' && (captureForm.data.label_sn_photos.length === 0 || captureForm.data.device_photos.length === 0)} data-testid="recovery-capture-submit">{photoBusy ? 'Memproses foto...' : captureForm.processing ? 'Menyimpan...' : 'Simpan hasil pengambilan'}</Button>
    </form></CardContent></Card>;
}
