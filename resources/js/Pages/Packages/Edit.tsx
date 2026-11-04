import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { TextField } from '@/Components/ResourceFields';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { Package as PackageIcon } from 'lucide-react';
import { z } from 'zod';

const packageSchema = z.object({
    name: requiredString('Package name'),
    price: requiredNumber('Monthly price', 0),
    mikrotik_profile: z.string().max(255, 'MikroTik profile may not be greater than 255 characters.').optional(),
    router_id: z.string().optional(),
    is_active: z.boolean(),
});

interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string;
    router_id?: number | null;
    is_active?: boolean;
}

interface Props {
    package: Package;
    routers?: Array<{ id: number; name: string }>;
}

export default function Edit({ package: pkg, routers = [] }: Props) {
    const form = useForm({
        name: pkg.name,
        price: pkg.price.toString(),
        mikrotik_profile: pkg.mikrotik_profile || '',
        router_id: pkg.router_id ? String(pkg.router_id) : '',
        is_active: pkg.is_active ?? false,
    });
    const { data, setData, put, processing, errors } = form;

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(packageSchema, data, form)) return;
        put(route('packages.update', pkg.id));
    };

    return (
        <EnterpriseFormLayout
            title="Edit Package"
            pageTitle="Edit Package"
            subtitle={`Perbarui informasi paket #${pkg.id}`}
            icon={PackageIcon}
            backHref={route('packages.show', pkg.id)}
            breadcrumbs={[
                { label: 'Packages', href: route('packages.index') },
                { label: pkg.name, href: route('packages.show', pkg.id) },
                { label: 'Edit' },
            ]}
        >
            <ResourceFormShell
                title={`Package #${pkg.id}`}
                description="Update package information."
                onSubmit={submit}
                submitLabel="Save Changes"
                processing={processing}
                cancelHref={route('packages.index')}
                beforeFields={
                    <Alert className="mb-6">
                        <AlertDescription>
                            <strong>Warning:</strong> Changing the package price will affect future billing cycles for subscribed customers.
                        </AlertDescription>
                    </Alert>
                }
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
                <div className="flex items-center gap-3 rounded-md border p-3">
                    <Switch
                        id="is_active"
                        checked={data.is_active}
                        onCheckedChange={(checked: boolean) => setData('is_active', checked)}
                    />
                    <div>
                        <Label htmlFor="is_active" className="cursor-pointer">Aktifkan paket</Label>
                        <p className="text-xs text-muted-foreground">Paket aktif dapat dipilih oleh bot PSB dan proses pelanggan baru.</p>
                    </div>
                </div>
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
