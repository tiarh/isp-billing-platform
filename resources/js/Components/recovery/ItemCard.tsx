import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { operationKey } from './serial';
import { ActionCardProps, CONDITION_LABELS, PHOTO_KIND_LABELS, RecoveryItem } from './types';

function ItemEditForm({ item, selected, writesEnabled, operationKeys, onDone, onClose }: Pick<ActionCardProps, 'selected' | 'writesEnabled' | 'operationKeys' | 'onDone'> & { item: RecoveryItem; onClose: () => void }) {
    const updateItemForm = useForm({ device_type: item.device_type, expected_serial_number: item.expected_serial_number || '' });

    const handleUpdateItem = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        updateItemForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `update-item-${selected.id}-${item.id}-${data.device_type}-${data.expected_serial_number.trim()}`),
        }));
        updateItemForm.post(route('device-recovery.update-item', [selected.id, item.id]), {
            preserveScroll: true,
            onSuccess: () => {
                onClose();
                onDone();
            },
        });
    };

    return <form onSubmit={handleUpdateItem} className="space-y-3 rounded-md border bg-muted/20 p-3"><div className="grid gap-3 sm:grid-cols-2"><div className="space-y-1.5"><label htmlFor={`recovery-device-type-${item.id}`} className="text-xs font-medium">Jenis perangkat</label><select id={`recovery-device-type-${item.id}`} value={updateItemForm.data.device_type} onChange={(event) => updateItemForm.setData('device_type', event.target.value)} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="onu">ONU</option><option value="ont">ONT</option><option value="router">Router</option><option value="modem">Modem</option><option value="adapter">Adapter</option><option value="other">Lainnya</option></select></div><div className="space-y-1.5"><label htmlFor={`recovery-expected-sn-${item.id}`} className="text-xs font-medium">SN expected</label><Input id={`recovery-expected-sn-${item.id}`} value={updateItemForm.data.expected_serial_number} onChange={(event) => updateItemForm.setData('expected_serial_number', event.target.value)} className="h-9" /></div></div><FormError errors={updateItemForm.errors} /><div className="flex flex-wrap gap-2"><Button type="submit" size="sm" disabled={updateItemForm.processing}>{updateItemForm.processing ? 'Menyimpan...' : 'Simpan data'}</Button><Button type="button" size="sm" variant="ghost" onClick={onClose}>Batal</Button></div></form>;
}

export function ItemCard({ item, canEdit, editing, onEdit, onClose, ...actionProps }: Pick<ActionCardProps, 'selected' | 'writesEnabled' | 'operationKeys' | 'onDone'> & {
    item: RecoveryItem;
    canEdit: boolean;
    editing: boolean;
    onEdit: () => void;
    onClose: () => void;
}) {
    return <Card><CardHeader className="pb-3"><CardTitle className="flex items-center justify-between gap-3 text-sm"><span>Perangkat {item.device_type.toUpperCase()}</span>{canEdit && <Button type="button" size="sm" variant="outline" onClick={onEdit}>Edit data</Button>}</CardTitle></CardHeader><CardContent className="space-y-2 text-sm"><div className="grid grid-cols-2 gap-3 font-mono text-xs"><div><p className="font-sans text-muted-foreground">SN expected</p><p>{item.expected_serial_number || 'Tidak tersedia'}</p></div><div><p className="font-sans text-muted-foreground">SN captured</p><p>{item.captured_serial_number || 'Belum dicatat'}</p></div>{item.warehouse_serial_number && <div><p className="font-sans text-muted-foreground">SN fisik gudang</p><p>{item.warehouse_serial_number}</p></div>}</div><p className="text-muted-foreground">Kondisi: <span className="text-foreground">{CONDITION_LABELS[item.condition] || item.condition}</span></p>{item.notes && <p className="rounded-md bg-muted p-2 text-xs">{item.notes}</p>}{item.photos && item.photos.length > 0 && <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">{item.photos.map((photo) => <a key={photo.index} href={photo.url} target="_blank" rel="noreferrer" className="overflow-hidden rounded-md border focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"><img src={photo.url} alt={(photo.kind ? PHOTO_KIND_LABELS[photo.kind] || 'Foto' : 'Foto perangkat') + ' ' + (photo.index + 1)} className="aspect-square w-full object-cover" loading="lazy" />{photo.kind && <span className="block bg-muted px-1 py-0.5 text-center text-[11px]">{PHOTO_KIND_LABELS[photo.kind] || photo.kind}</span>}</a>)}</div>}{editing && actionProps.writesEnabled && <ItemEditForm item={item} onClose={onClose} {...actionProps} />}</CardContent></Card>;
}
