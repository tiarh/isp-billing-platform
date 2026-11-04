import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { TextField } from '@/Components/ResourceFields';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { Package } from 'lucide-react';
import { z } from 'zod';

const packageSchema = z.object({
    name: requiredString('Package name'),
    price: requiredNumber('Monthly price', 0),
    mikrotik_profile: z.string().max(255, 'MikroTik profile may not be greater than 255 characters.').optional(),
    router_id: z.string().optional(),
});

interface Props {
    routers?: Array<{ id: number; name: string }>;
}

export default function Create({ routers = [] }: Props) {
    const form = useForm({
        name: '',
        price: '',
        mikrotik_profile: '',
        router_id: '',
    });
    const { data, setData, post, processing, errors } = form;

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(packageSchema, data, form)) return;
        post(route('packages.store'));
    };

    return (
        <EnterpriseFormLayout
            title="Create Package"
            pageTitle="Create Package"
            subtitle="Buat paket internet baru"
            icon={Package}
            backHref={route('packages.index')}
            breadcrumbs={[
                { label: 'Packages', href: route('packages.index') },
                { label: 'Create' },
            ]}
        >
            <ResourceFormShell
                title="Package Details"
                description="Create a new internet package."
                onSubmit={submit}
                submitLabel="Create Package"
                processingLabel="Creating..."
                processing={processing}
                cancelHref={route('packages.index')}
            >
                <TextField
                    id="name"
                    label="Package Name"
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    placeholder="e.g. Paket Premium 20Mbps"
                    required
                    autoFocus
                    maxLength={255}
                    help="This is what customers see on invoices."
                    error={errors.name}
                />
                <TextField
                    id="mikrotik_profile"
                    label="MikroTik Profile Name"
                    value={data.mikrotik_profile}
                    onChange={(value) => setData('mikrotik_profile', value)}
                    placeholder="e.g. profile-10m"
                    maxLength={255}
                    help="Leave empty for manual packages."
                    error={errors.mikrotik_profile}
                />
                <div className="space-y-1.5">
                    <label htmlFor="router_id" className="text-sm font-medium">Router asal</label>
                    <select id="router_id" value={data.router_id} onChange={(event) => setData('router_id', event.target.value)} className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm">
                        <option value="">Global / belum dipetakan</option>
                        {routers.map((router) => <option key={router.id} value={router.id}>{router.name}</option>)}
                    </select>
                    {errors.router_id && <p className="text-sm text-destructive">{errors.router_id}</p>}
                </div>
                <TextField
                    id="price"
                    label="Monthly Price (IDR)"
                    type="number"
                    step="1000"
                    min={0}
                    value={data.price}
                    onChange={(value) => setData('price', value)}
                    placeholder="e.g. 150000"
                    required
                    error={errors.price}
                />
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
