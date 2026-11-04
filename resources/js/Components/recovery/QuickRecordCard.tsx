import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { Warehouse } from 'lucide-react';
import { FormEvent, useState } from 'react';
import { FormError } from './FormError';
import { PhotoPicker } from './PhotoPicker';
import { operationKey, serialsMatch } from './serial';
import { ActionCardProps, TechnicianOption } from './types';

export function QuickRecordCard({ selected, detail, writesEnabled, operationKeys, onDone, technicians, photoBusy, setPhotoBusy }: ActionCardProps & {
    technicians: TechnicianOption[];
    photoBusy: boolean;
    setPhotoBusy: (busy: boolean) => void;
}) {
    const quickForm = useForm({ technician_id: selected.assigned_to ? String(selected.assigned_to.id) : '', serial_number: '', condition: 'good', reason: '', label_sn_photos: [] as File[], device_photos: [] as File[], confirm_received: false });
    const [quickOpen, setQuickOpen] = useState(false);

    const handleQuickRecord = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        quickForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `quick-${selected.id}-${detail?.version ?? 0}`),
        }));
        quickForm.post(route('device-recovery.quick-record', selected.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                quickForm.reset();
                onDone();
            },
        });
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="flex items-center justify-between gap-3 text-sm"><span className="flex items-center gap-2"><Warehouse className="size-4" aria-hidden />Sudah diambil, catat langsung</span>{!quickOpen && <Button type="button" size="sm" variant="outline" onClick={() => setQuickOpen(true)}>Buka form</Button>}</CardTitle></CardHeader>{quickOpen && <CardContent><form onSubmit={handleQuickRecord} className="space-y-4">
        <p className="text-xs text-muted-foreground">Untuk perangkat yang sudah dibawa teknisi ke gudang tanpa dicatat di aplikasi. Case langsung diterima gudang (pelanggan di-terminate kecuali ditandai tetap aktif, tiket ditutup otomatis). SN berbeda dari SN expected atau kondisi tidak baik masuk exception.</p>
        <div className="space-y-1.5"><label htmlFor="recovery-quick-technician" className="text-sm font-medium">Teknisi yang mengambil</label><select id="recovery-quick-technician" value={quickForm.data.technician_id} onChange={(event) => quickForm.setData('technician_id', event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-base sm:h-9 sm:text-sm"><option value="">Pilih teknisi</option>{technicians.map((technician) => <option key={technician.id} value={technician.id}>{technician.name}</option>)}</select></div>
        <div className="space-y-1.5"><label htmlFor="recovery-quick-sn" className="text-sm font-medium">SN perangkat <span className="font-mono text-xs text-muted-foreground">(expected: {detail.items[0].expected_serial_number || '-'})</span></label><Input id="recovery-quick-sn" autoCapitalize="characters" autoComplete="off" placeholder="Ketik / scan SN" value={quickForm.data.serial_number} onChange={(event) => quickForm.setData('serial_number', event.target.value)} className="h-11 font-mono text-base sm:h-9 sm:text-sm" />{quickForm.data.serial_number.trim() !== '' && detail.items[0].expected_serial_number && <p className={serialsMatch(quickForm.data.serial_number, detail.items[0].expected_serial_number) ? 'text-xs text-status-up' : 'text-xs text-status-down'}>{serialsMatch(quickForm.data.serial_number, detail.items[0].expected_serial_number) ? 'SN cocok.' : 'SN tidak cocok: akan masuk exception (alasan wajib).'}</p>}</div>
        <div className="space-y-1.5"><label htmlFor="recovery-quick-condition" className="text-sm font-medium">Kondisi</label><select id="recovery-quick-condition" value={quickForm.data.condition} onChange={(event) => quickForm.setData('condition', event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-base sm:h-9 sm:text-sm"><option value="good">Baik dan SN cocok</option><option value="damaged">Rusak</option><option value="unknown">SN tidak terbaca</option><option value="lost">Hilang / tidak ada</option></select></div>
        <div className="space-y-1.5"><label htmlFor="recovery-quick-note" className="text-sm font-medium">Catatan</label><Textarea id="recovery-quick-note" placeholder="Wajib bila kondisi tidak baik atau SN tidak cocok" value={quickForm.data.reason} onChange={(event) => quickForm.setData('reason', event.target.value)} className="text-base sm:text-sm" /></div>
        <PhotoPicker id="recovery-quick-photo-label-sn" label="Foto label SN" hint="Foto stiker SN/MAC perangkat, harus terbaca." files={quickForm.data.label_sn_photos} max={2} required={quickForm.data.condition !== 'lost'} onChange={(files) => quickForm.setData('label_sn_photos', files)} onBusy={setPhotoBusy} />
        <PhotoPicker id="recovery-quick-photo-device" label="Foto perangkat utuh" hint="Foto perangkat beserta kelengkapannya." files={quickForm.data.device_photos} max={3} required={quickForm.data.condition !== 'lost'} onChange={(files) => quickForm.setData('device_photos', files)} onBusy={setPhotoBusy} />
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={quickForm.data.confirm_received} onChange={(event) => quickForm.setData('confirm_received', event.target.checked)} /><span>Saya konfirmasi perangkat ini sudah diterima di gudang.</span></label>
        <FormError errors={quickForm.errors} />
        <div className="flex flex-wrap gap-2"><Button type="submit" className="h-11 sm:h-9" disabled={quickForm.processing || photoBusy || !quickForm.data.technician_id || !quickForm.data.confirm_received || quickForm.data.condition !== 'lost' && (quickForm.data.label_sn_photos.length === 0 || quickForm.data.device_photos.length === 0)}>{quickForm.processing ? 'Menyimpan...' : 'Simpan & terima gudang'}</Button><Button type="button" variant="ghost" className="h-11 sm:h-9" onClick={() => setQuickOpen(false)}>Tutup</Button></div>
    </form></CardContent>}</Card>;
}
