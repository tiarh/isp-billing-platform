import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { useForm } from '@inertiajs/react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Send } from 'lucide-react';
import { nullableId, requiredString, validateForm } from '@/lib/validation';
import { z } from 'zod';

interface Area {
    id: number;
    name: string;
}

interface Props {
    areas: Area[];
}

const broadcastCreateSchema = z.object({
    name: requiredString('Nama kampanye'),
    target_type: z.enum(['all', 'active', 'isolated'], { error: 'Target penerima wajib dipilih.' }),
    target_area_id: nullableId('Area'),
    message_template: z.string().trim().min(1, 'Template pesan wajib diisi.'),
});

export default function Create({ areas }: Props) {
    const form = useForm({
        name: '',
        target_type: 'all',
        target_area_id: '',
        message_template: 'Halo {name},\n\nTagihan internet Anda sebesar {billing_amount} sudah terbit.\n\nTerima kasih.',
    });
    const { data, setData, post, processing, errors } = form;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!validateForm(broadcastCreateSchema, data, form)) return;
        form.transform((payload) => ({
            ...payload,
            target_area_id: payload.target_area_id ? Number(payload.target_area_id) : null,
        }));
        post(route('broadcasts.store'), {
            preserveScroll: true,
            onError: (errors) => {
                if (errors?.message && /csrf|expired|419/i.test(String(errors.message))) {
                    window.location.reload();
                }
            },
        });
    };

    return (
        <EnterpriseFormLayout
            icon={Send}
            title="Buat Kampanye Broadcast"
            pageTitle="Buat Broadcast"
            subtitle="Nomor billing dipakai untuk broadcast; default saat ini dry-run"
            backHref={route('broadcasts.index')}
            breadcrumbs={[
                { label: 'Broadcast', href: route('broadcasts.index') },
                { label: 'Buat' },
            ]}
        >
            <ResourceFormShell
                title="Detail Kampanye"
                description="Target akan dihitung dulu. Pengiriman nyata aktif setelah guard broadcast dibuka."
                onSubmit={handleSubmit}
                submitLabel="Mulai Kampanye"
                processingLabel="Memproses..."
                processing={processing}
                cancelHref={route('broadcasts.index')}
            >
                <div className="space-y-2">
                    <Label htmlFor="name">Nama Kampanye</Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        placeholder="Contoh: Reminder Tagihan Februari"
                        maxLength={255}
                    />
                    {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                </div>

                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <div className="space-y-2">
                        <Label htmlFor="target_type">Target Penerima</Label>
                        <select
                            id="target_type"
                            className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            value={data.target_type}
                            onChange={e => setData('target_type', e.target.value)}
                        >
                            <option value="all">Semua Pelanggan</option>
                            <option value="active">Pelanggan Aktif Saja</option>
                            <option value="isolated">Pelanggan Terisolasi Saja</option>
                        </select>
                        {errors.target_type && <p className="text-sm text-destructive">{errors.target_type}</p>}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="target_area_id">Filter Area</Label>
                        <select
                            id="target_area_id"
                            className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            value={data.target_area_id}
                            onChange={e => setData('target_area_id', e.target.value)}
                        >
                            <option value="">-- Semua Area --</option>
                            {areas.map(area => (
                                <option key={area.id} value={area.id}>{area.name}</option>
                            ))}
                        </select>
                        {errors.target_area_id && <p className="text-sm text-destructive">{errors.target_area_id}</p>}
                    </div>
                </div>

                <div className="space-y-2">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="message_template">Template Pesan</Label>
                        <span className="text-xs text-muted-foreground">
                            Variabel: <code className="rounded bg-muted px-1 py-0.5">{`{name}`}</code>, <code className="rounded bg-muted px-1 py-0.5">{`{billing_amount}`}</code>
                        </span>
                    </div>
                    <Textarea
                        id="message_template"
                        rows={8}
                        value={data.message_template}
                        onChange={e => setData('message_template', e.target.value)}
                        className="font-mono text-sm"
                    />
                    {errors.message_template && <p className="text-sm text-destructive">{errors.message_template}</p>}
                </div>
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
