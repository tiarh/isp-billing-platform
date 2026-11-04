import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import { FormError } from './FormError';
import { PhotoPicker } from './PhotoPicker';
import { operationKey, serialsMatch } from './serial';

interface ReportCustomer { id: number; code: string; name: string; pppoe_user: string | null; area: string | null; status: string | null; onu_serial: string | null; }

// Owner 2026-10-10: technician "Lapor pengambilan tanpa tiket". Creates the
// Dismantle ticket + a carried case; the admin confirms "Diterima gudang".
export function TechnicianReportForm({ onDone }: { onDone: () => void }) {
    const form = useForm({ customer_id: '', serial_number: '', condition: 'good', reason: '', emergency_reason: '', label_sn_photos: [] as File[], device_photos: [] as File[] });
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<ReportCustomer[]>([]);
    const [searching, setSearching] = useState(false);
    const [searchError, setSearchError] = useState('');
    const [customer, setCustomer] = useState<ReportCustomer | null>(null);
    const [photoBusy, setPhotoBusy] = useState(false);
    const keys = useRef<Record<string, string>>({});

    useEffect(() => {
        if (customer || query.trim().length < 3) {
            setResults([]);
            setSearchError('');
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setSearching(true);
            setSearchError('');
            try {
                const response = await fetch(route('device-recovery.report-customers', { q: query.trim() }), { headers: { Accept: 'application/json' }, signal: controller.signal });
                if (!response.ok) throw new Error(response.status === 429 ? 'Terlalu sering mencari, coba lagi sebentar.' : 'Pencarian pelanggan gagal.');
                setResults(await response.json() as ReportCustomer[]);
            } catch (error) {
                if (!controller.signal.aborted) setSearchError(error instanceof Error ? error.message : 'Pencarian pelanggan gagal.');
            } finally {
                if (!controller.signal.aborted) setSearching(false);
            }
        }, 350);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [query, customer]);

    const pickCustomer = (picked: ReportCustomer | null) => {
        setCustomer(picked);
        form.setData('customer_id', picked ? String(picked.id) : '');
        if (!picked) setQuery('');
    };

    const typedSerial = form.data.serial_number.trim();
    const expected = customer?.onu_serial || '';
    const mismatch = typedSerial !== '' && expected !== '' && !serialsMatch(typedSerial, expected);
    const needsNote = form.data.condition !== 'good' || mismatch;
    const photosMissing = form.data.condition !== 'lost' && (form.data.label_sn_photos.length === 0 || form.data.device_photos.length === 0);
    const canSubmit = !!customer && form.data.emergency_reason.trim().length >= 10 && !photosMissing && (!needsNote || form.data.reason.trim().length >= 5) && (form.data.condition !== 'good' || typedSerial !== '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSubmit) return;
        form.transform((data) => ({ ...data, idempotency_key: operationKey(keys, `report-${data.customer_id}`) }));
        form.post(route('device-recovery.report'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                keys.current = {};
                form.reset();
                pickCustomer(null);
                onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4" data-testid="recovery-report-form">
            <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg p-3 text-xs">Hanya untuk keadaan darurat: perangkat sudah Anda ambil sebelum admin membuat tiket. Laporan ini membuat tiket Dismantle atas nama Anda; serahkan perangkat ke admin untuk konfirmasi &quot;Diterima gudang&quot;.</div>
            <div className="space-y-1.5">
                <label htmlFor="recovery-report-customer" className="text-sm font-medium">Pelanggan<span className="text-status-down"> *</span></label>
                {customer ? (
                    <div className="flex items-start justify-between gap-3 rounded-md border p-3 text-sm">
                        <div><p className="font-medium">{customer.name}</p><p className="font-mono text-xs text-muted-foreground">{customer.code}{customer.pppoe_user ? ` · ${customer.pppoe_user}` : ''}</p><p className="text-xs text-muted-foreground">{customer.area || 'Area tidak tersedia'} · {customer.status || '—'} · SN: <span className="font-mono">{customer.onu_serial || 'tidak tercatat'}</span></p></div>
                        <Button type="button" variant="ghost" size="sm" className="h-11 sm:h-9" onClick={() => pickCustomer(null)}>Ganti</Button>
                    </div>
                ) : (
                    <>
                        <div className="relative"><Search className="pointer-events-none absolute left-3 top-3.5 size-4 text-muted-foreground sm:top-2.5" aria-hidden /><Input id="recovery-report-customer" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Nama, kode, atau SN (min. 3)" autoComplete="off" className="h-11 pl-9 text-base sm:h-9 sm:text-sm" /></div>
                        {searching && <p className="text-xs text-muted-foreground">Mencari...</p>}
                        {searchError && <p className="text-xs text-status-down" role="alert">{searchError}</p>}
                        {!searching && query.trim().length >= 3 && results.length === 0 && !searchError && <p className="text-xs text-muted-foreground">Pelanggan tidak ditemukan.</p>}
                        {results.length > 0 && <ul className="max-h-64 divide-y overflow-y-auto rounded-md border" role="listbox" aria-label="Hasil pencarian pelanggan">{results.map((result) => <li key={result.id}><button type="button" role="option" aria-selected={false} className="w-full p-3 text-left text-sm hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none" onClick={() => pickCustomer(result)}><span className="font-medium">{result.name}</span><span className="block font-mono text-xs text-muted-foreground">{result.code}{result.pppoe_user ? ` · ${result.pppoe_user}` : ''} · {result.area || '—'} · {result.status || '—'}</span></button></li>)}</ul>}
                    </>
                )}
            </div>
            <div className="space-y-1.5"><label htmlFor="recovery-report-sn" className="text-sm font-medium">SN perangkat{form.data.condition === 'good' && <span className="text-status-down"> *</span>}</label><Input id="recovery-report-sn" autoCapitalize="characters" autoComplete="off" placeholder="SN pada label perangkat" value={form.data.serial_number} onChange={(event) => form.setData('serial_number', event.target.value)} className="h-11 font-mono text-base sm:h-9 sm:text-sm" />{typedSerial !== '' && expected !== '' && <p className={mismatch ? 'text-xs text-status-down' : 'text-xs text-status-up'}>{mismatch ? 'SN tidak cocok dengan SN pelanggan: laporan masuk exception (catatan wajib).' : 'SN cocok dengan data pelanggan.'}</p>}</div>
            <div className="space-y-1.5"><label htmlFor="recovery-report-condition" className="text-sm font-medium">Kondisi</label><select id="recovery-report-condition" value={form.data.condition} onChange={(event) => form.setData('condition', event.target.value)} className="h-11 w-full rounded-md border border-input bg-background px-3 text-base sm:h-9 sm:text-sm"><option value="good">Baik dan SN cocok</option><option value="damaged">Rusak</option><option value="unknown">SN tidak terbaca</option><option value="lost">Hilang / tidak ada di lokasi</option></select></div>
            <div className="space-y-1.5"><label htmlFor="recovery-report-note" className="text-sm font-medium">Catatan{needsNote && <span className="text-status-down"> *</span>}</label><Textarea id="recovery-report-note" placeholder={needsNote ? 'Wajib: jelaskan kondisi / SN berbeda (min. 5 karakter)' : 'Catatan (opsional)'} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} className="text-base sm:text-sm" /></div>
            <div className="space-y-1.5"><label htmlFor="recovery-report-emergency" className="text-sm font-medium">Alasan darurat (kenapa tanpa tiket)<span className="text-status-down"> *</span></label><Textarea id="recovery-report-emergency" placeholder="Contoh: pelanggan pindah, perangkat diserahkan langsung (min. 10 karakter)" rows={3} value={form.data.emergency_reason} onChange={(event) => form.setData('emergency_reason', event.target.value)} className="text-base sm:text-sm" /></div>
            <PhotoPicker id="recovery-report-photo-label-sn" label="Foto label SN" hint="Foto stiker SN/MAC perangkat, harus terbaca." files={form.data.label_sn_photos} max={2} required={form.data.condition !== 'lost'} onChange={(files) => form.setData('label_sn_photos', files)} onBusy={setPhotoBusy} />
            <PhotoPicker id="recovery-report-photo-device" label="Foto perangkat utuh" hint="Foto perangkat beserta adaptor/kelengkapan yang dibawa." files={form.data.device_photos} max={3} required={form.data.condition !== 'lost'} onChange={(files) => form.setData('device_photos', files)} onBusy={setPhotoBusy} />
            {form.data.condition === 'lost' && <p className="text-xs text-muted-foreground">Perangkat hilang: foto boleh kosong, catatan wajib. Laporan masuk tinjauan exception.</p>}
            <FormError errors={form.errors} />
            {form.progress && <p className="text-xs text-muted-foreground">Mengunggah {form.progress.percentage ?? 0}%</p>}
            <Button type="submit" className="h-11 w-full sm:h-9" disabled={form.processing || photoBusy || !canSubmit} data-testid="recovery-report-submit">{photoBusy ? 'Memproses foto...' : form.processing ? 'Mengirim...' : 'Kirim laporan'}</Button>
        </form>
    );
}
