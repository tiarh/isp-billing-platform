import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { FormError } from './FormError';
import { operationKey } from './serial';
import { ActionCardProps } from './types';

export function AddItemCard({ selected, writesEnabled, operationKeys, onDone }: ActionCardProps) {
    const addItemForm = useForm({ device_type: 'onu', expected_serial_number: '' });

    const handleAddItem = (event: FormEvent) => {
        event.preventDefault();
        if (!writesEnabled) return;
        addItemForm.transform((data) => ({
            ...data,
            idempotency_key: operationKey(operationKeys, `item-${selected.id}-${data.device_type}-${data.expected_serial_number.trim()}`),
        }));
        addItemForm.post(route('device-recovery.add-item', selected.id), { preserveScroll: true, onSuccess: onDone });
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="text-sm">Tambah perangkat</CardTitle></CardHeader><CardContent><form onSubmit={handleAddItem} className="space-y-3"><select aria-label="Jenis perangkat tambahan" value={addItemForm.data.device_type} onChange={(event) => addItemForm.setData('device_type', event.target.value)} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"><option value="onu">ONU</option><option value="ont">ONT</option><option value="router">Router</option><option value="modem">Modem</option><option value="adapter">Adapter</option><option value="other">Lainnya</option></select><Input aria-label="SN expected perangkat tambahan" placeholder="SN expected (opsional)" value={addItemForm.data.expected_serial_number} onChange={(event) => addItemForm.setData('expected_serial_number', event.target.value)} /><FormError errors={addItemForm.errors} /><Button type="submit" disabled={addItemForm.processing}>{addItemForm.processing ? 'Menambahkan...' : 'Tambah perangkat'}</Button></form></CardContent></Card>;
}
