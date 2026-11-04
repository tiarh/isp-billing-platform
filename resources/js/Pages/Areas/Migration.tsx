import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { RefreshCw } from 'lucide-react';
import { useUnsavedChangesGuard } from '@/hooks/useUnsavedChangesGuard';

interface Area { id: number; name: string; code: string; customers_count: number; }

export default function Migration({ areas }: { areas: Area[] }) {
    const form = useForm({ from_area_id: '', to_area_id: '' });
    useUnsavedChangesGuard(form.isDirty && !form.processing);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const fromArea = areas.find((area) => String(area.id) === form.data.from_area_id);
    const toArea = areas.find((area) => String(area.id) === form.data.to_area_id);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        setConfirmOpen(true);
    };

    const confirmMigration = () => {
        form.post(route('areas.migration.store'), {
            onSuccess: () => setConfirmOpen(false),
            onError: () => setConfirmOpen(false),
        });
    };

    return (
        <EnterpriseFormLayout title="Migrasi Area" pageTitle="Migrasi Area" subtitle="Pindahkan pelanggan antar-area" icon={RefreshCw} backHref={route('areas.index')} breadcrumbs={[{ label: 'Area', href: route('areas.index') }, { label: 'Migrasi Area' }]} maxWidth="3xl">
            <form onSubmit={submit}>
                <Card>
                    <CardHeader><CardTitle className="text-base">Pilih Area</CardTitle></CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2">
                        <div className="space-y-2"><Label>Area Asal</Label><Select value={form.data.from_area_id} onValueChange={(value) => form.setData('from_area_id', value)}><SelectTrigger><SelectValue placeholder="Pilih area asal" /></SelectTrigger><SelectContent>{areas.map((area) => <SelectItem key={area.id} value={String(area.id)}>{area.name} ({area.customers_count})</SelectItem>)}</SelectContent></Select>{form.errors.from_area_id && <p className="text-xs text-destructive">{form.errors.from_area_id}</p>}</div>
                        <div className="space-y-2"><Label>Area Tujuan</Label><Select value={form.data.to_area_id} onValueChange={(value) => form.setData('to_area_id', value)}><SelectTrigger><SelectValue placeholder="Pilih area tujuan" /></SelectTrigger><SelectContent>{areas.map((area) => <SelectItem key={area.id} value={String(area.id)}>{area.name}</SelectItem>)}</SelectContent></Select>{form.errors.to_area_id && <p className="text-xs text-destructive">{form.errors.to_area_id}</p>}</div>
                        <div className="md:col-span-2"><Button type="submit" disabled={form.processing || !form.data.from_area_id || !form.data.to_area_id} className="gap-2"><RefreshCw className="h-4 w-4" />{form.processing ? 'Memproses...' : 'Pindahkan Pelanggan'}</Button></div>
                    </CardContent>
                </Card>
            </form>
            <ConfirmDestructive
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Konfirmasi migrasi area"
                description={`Pindahkan ${fromArea?.customers_count ?? 0} pelanggan aktif dari ${fromArea?.name ?? 'area asal'} ke ${toArea?.name ?? 'area tujuan'}?`}
                confirmationLabel="Saya memahami bahwa perubahan area pelanggan akan dicatat dan tidak dapat dibatalkan dari dialog ini."
                confirmText="Pindahkan pelanggan"
                onConfirm={confirmMigration}
                isLoading={form.processing}
            />
        </EnterpriseFormLayout>
    );
}
