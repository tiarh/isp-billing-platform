import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { TextField } from '@/Components/ResourceFields';
import { Label } from '@/Components/ui/label';
import { ShieldCheck, UserRound } from 'lucide-react';

interface Area { id: number; name: string; code?: string | null }
interface ManagedUser { id: number; name: string; email: string; role: string; phone?: string | null; areas?: Area[] }
interface Props { managedUser: ManagedUser; areas: Area[] }

const roles = [
    { value: 'superadmin', label: 'Superadmin' },
    { value: 'admin', label: 'Admin' },
    { value: 'noc', label: 'NOC' },
    { value: 'teknisi', label: 'Teknisi' },
];

export default function Edit({ managedUser, areas }: Props) {
    const form = useForm({
        name: managedUser.name, email: managedUser.email, password: '', password_confirmation: '',
        role: managedUser.role, phone: managedUser.phone || '', area_ids: managedUser.areas?.map((area) => area.id) || [],
    });
    const { data, setData, put, processing, errors } = form;

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        put(route('users.update', managedUser.id));
    };

    const toggleArea = (id: number) => {
        setData('area_ids', data.area_ids.includes(id)
            ? data.area_ids.filter((areaId) => areaId !== id)
            : [...data.area_ids, id]);
    };

    return (
        <EnterpriseFormLayout title="Edit Pengguna" pageTitle={`Edit ${managedUser.name}`} subtitle="Perbarui akses dan data login" icon={UserRound}
            backHref={route('users.index')} breadcrumbs={[{ label: 'Pengguna', href: route('users.index') }, { label: 'Edit' }]}>
            <ResourceFormShell title="Data Akun" description="Kosongkan password jika tidak ingin menggantinya." onSubmit={submit}
                submitLabel="Simpan Perubahan" processingLabel="Menyimpan..." processing={processing} cancelHref={route('users.index')}>
                <TextField id="name" label="Nama" value={data.name} onChange={(value) => setData('name', value)} required autoFocus error={errors.name} />
                <TextField id="email" label="Email Login" type="email" value={data.email} onChange={(value) => setData('email', value)} required error={errors.email} />
                <TextField id="phone" label="Telepon" value={data.phone} onChange={(value) => setData('phone', value)} error={errors.phone} />
                <div className="space-y-2">
                    <Label htmlFor="role">Peran</Label>
                    <select id="role" value={data.role} onChange={(event) => setData('role', event.target.value)} className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm" required>
                        {roles.map((role) => <option key={role.value} value={role.value}>{role.label}</option>)}
                    </select>
                    {errors.role && <p className="text-sm text-destructive">{errors.role}</p>}
                </div>
                <TextField id="password" label="Password Baru" type="password" value={data.password} onChange={(value) => setData('password', value)} error={errors.password} />
                <TextField id="password_confirmation" label="Ulangi Password Baru" type="password" value={data.password_confirmation} onChange={(value) => setData('password_confirmation', value)} />
                <div className="space-y-3">
                    <div><Label>Area Admin</Label><p className="text-xs text-muted-foreground">Hanya berlaku untuk peran Admin; peran lain memiliki akses area global.</p></div>
                    <div className="grid gap-2 sm:grid-cols-2">
                        {areas.map((area) => <label key={area.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={data.area_ids.includes(area.id)} onChange={() => toggleArea(area.id)} />{area.name}</label>)}
                    </div>
                    {errors.area_ids && <p className="text-sm text-destructive">{errors.area_ids}</p>}
                </div>
                <div className="flex items-center gap-2 text-xs text-muted-foreground"><ShieldCheck className="h-4 w-4" />Perubahan akun tidak mengubah data pelanggan atau perangkat.</div>
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
