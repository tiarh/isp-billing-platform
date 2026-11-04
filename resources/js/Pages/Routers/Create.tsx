import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { Link, useForm } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Switch } from '@/Components/ui/switch';
import { Router, Save, Server } from 'lucide-react';
import { FormEventHandler } from 'react';
import { requiredNumber, requiredString, validateForm } from '@/lib/validation';
import { z } from 'zod';

const ipAddressSchema = requiredString('IP address')
    .refine((value) => {
        const ipv4 = /^(\d{1,3}\.){3}\d{1,3}$/.test(value)
            && value.split('.').every((part) => Number(part) >= 0 && Number(part) <= 255);
        const ipv6 = value.includes(':') && /^[0-9a-fA-F:]+$/.test(value);

        return ipv4 || ipv6;
    }, 'IP address must be valid.');

const routerCreateSchema = z.object({
    name: requiredString('Router name'),
    ip_address: ipAddressSchema,
    port: requiredNumber('API port', 1).refine((value) => value <= 65535, 'API port must be at most 65535.'),
    username: requiredString('Username'),
    password: requiredString('Password'),
    is_active: z.boolean(),
});

interface Props {
    /** Example values for placeholders (config site.ui_examples). */
    ui_examples?: { router_address?: string };
}

export default function Create({ ui_examples: uiExamples }: Props) {
    const form = useForm({
        name: '',
        ip_address: '',
        port: 8728,
        username: 'admin',
        password: '',
        is_active: true,
    });
    const { data, setData, post, processing, errors } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (!validateForm(routerCreateSchema, data, form)) return;
        post(route('routers.store'));
    };

    return (
        <EnterpriseFormLayout
            title="Add New Router"
            pageTitle="Tambah Router"
            subtitle="Tambah router MikroTik baru ke sistem"
            icon={Router}
            backHref={route('routers.index')}
            breadcrumbs={[
                { label: 'Router', href: route('routers.index') },
                { label: 'Create' },
            ]}
        >
            <form onSubmit={submit}>
                        <Card className="border-border bg-card">
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Server className="h-5 w-5 text-primary" />
                                    Konfigurasi Router
                                </CardTitle>
                                <CardDescription>
                                    Tambah router MikroTik baru ke sistem. Router harus bisa diakses via API (port default: 8728).
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-6">
                                {/* Basic Information */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Informasi Dasar</h3>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="name">Nama Router *</Label>
                                            <Input
                                                id="name"
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="contoh: Skynet-Tutur"
                                                maxLength={255}
                                                required
                                                className={errors.name ? 'border-destructive' : ''}
                                            />
                                            {errors.name && (
                                                <p className="text-sm text-destructive">{errors.name}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="ip_address">Alamat IP *</Label>
                                            <Input
                                                id="ip_address"
                                                value={data.ip_address}
                                                onChange={(e) => setData('ip_address', e.target.value)}
                                                placeholder={uiExamples?.router_address ?? ''}
                                                maxLength={255}
                                                required
                                                className={errors.ip_address ? 'border-destructive' : ''}
                                            />
                                            {errors.ip_address && (
                                                <p className="text-sm text-destructive">{errors.ip_address}</p>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                {/* Network Configuration */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Konfigurasi Jaringan</h3>
                                    <div className="space-y-2">
                                        <Label htmlFor="port">Port API MikroTik</Label>
                                        <Input
                                            id="port"
                                            type="number"
                                            min={1}
                                            max={65535}
                                            value={data.port}
                                            onChange={(e) => setData('port', parseInt(e.target.value) || 8728)}
                                            placeholder="8728"
                                            className={errors.port ? 'border-destructive' : ''}
                                        />
                                        {errors.port && (
                                            <p className="text-sm text-destructive">{errors.port}</p>
                                        )}
                                        <p className="text-xs text-muted-foreground">Port default API MikroTik: 8728</p>
                                    </div>
                                </div>

                                {/* Authentication */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Autentikasi</h3>
                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label htmlFor="username">Username *</Label>
                                            <Input
                                                id="username"
                                                value={data.username}
                                                onChange={(e) => setData('username', e.target.value)}
                                                placeholder="admin"
                                                maxLength={255}
                                                required
                                                className={errors.username ? 'border-destructive' : ''}
                                            />
                                            {errors.username && (
                                                <p className="text-sm text-destructive">{errors.username}</p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="password">Password *</Label>
                                            <Input
                                                id="password"
                                                type="password"
                                                value={data.password}
                                                onChange={(e) => setData('password', e.target.value)}
                                                placeholder="••••••••"
                                                required
                                                className={errors.password ? 'border-destructive' : ''}
                                            />
                                            {errors.password && (
                                                <p className="text-sm text-destructive">{errors.password}</p>
                                            )}
                                            <p className="text-xs text-muted-foreground">Akan dienkripsi di database</p>
                                        </div>
                                    </div>
                                </div>

                                {/* Status */}
                                <div className="space-y-4">
                                    <h3 className="text-lg font-medium">Status</h3>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked: boolean) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active" className="cursor-pointer">
                                            Aktif (Router diaktifkan untuk operasi)
                                        </Label>
                                    </div>
                                </div>

                                {/* Actions */}
                                <div className="flex items-center justify-between pt-6 border-t">
                                    <Link href={route('routers.index')}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        <Save className="mr-2 h-4 w-4" />
                                        {processing ? 'Menyimpan...' : 'Tambah Router'}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
            </form>
        </EnterpriseFormLayout>
    );
}
