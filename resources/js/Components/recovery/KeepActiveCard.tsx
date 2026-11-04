import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { useForm } from '@inertiajs/react';
import { FormError } from './FormError';
import { operationKey } from './serial';
import { ActionCardProps } from './types';

export function KeepActiveCard({ selected, detail, writesEnabled, operationKeys, onDone, isAdminLike }: ActionCardProps & { isAdminLike: boolean }) {
    const keepActiveForm = useForm({ keep_customer_active: false, reason: '' });

    const handleKeepActive = (keep: boolean) => {
        if (!writesEnabled) return;
        keepActiveForm.transform((data) => ({
            ...data,
            keep_customer_active: keep,
            idempotency_key: operationKey(operationKeys, `keep-active-${selected.id}-${keep ? 1 : 0}`),
        }));
        keepActiveForm.post(route('device-recovery.keep-customer-active', selected.id), { preserveScroll: true, onSuccess: onDone });
    };

    return <Card><CardHeader className="pb-3"><CardTitle className="text-sm">Status pelanggan setelah diterima gudang</CardTitle></CardHeader><CardContent className="space-y-3 text-sm"><p>{detail.keep_customer_active ? 'Pelanggan TETAP AKTIF (multi-perangkat: hanya perangkat tambahan yang diambil).' : 'Pelanggan otomatis di-terminate saat perangkat diterima gudang.'}</p>{isAdminLike && !['received', 'exception_accepted'].includes(detail.status) && <><FormError errors={keepActiveForm.errors} /><Button type="button" variant="outline" size="sm" disabled={keepActiveForm.processing} onClick={() => handleKeepActive(!detail.keep_customer_active)}>{keepActiveForm.processing ? 'Memproses...' : detail.keep_customer_active ? 'Terminate pelanggan saat diterima' : 'Pelanggan tetap aktif'}</Button></>}</CardContent></Card>;
}
