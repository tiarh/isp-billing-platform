import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import DataTable, { Column, PaginatedData } from '@/Components/DataTable';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { StatusBadge } from '@/Components/StatusBadge';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus, ShieldCheck, Trash2, UserRound } from 'lucide-react';
import { useState } from 'react';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    role: string;
    phone?: string | null;
    areas?: Array<{ id: number; name: string; code?: string | null }>;
    created_at?: string;
    totp_enabled?: boolean;
}

interface Props {
    users: PaginatedData<ManagedUser>;
    filters?: {
        search?: string;
        role?: string;
        limit?: number;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
}

const roleLabels: Record<string, string> = {
    superadmin: 'Superadmin',
    admin: 'Admin',
    noc: 'NOC',
    teknisi: 'Teknisi',
};

export default function Index({ users, filters = {} }: Props) {
    const [userPendingDeletion, setUserPendingDeletion] = useState<ManagedUser | null>(null);
    const [userPendingTotpReset, setUserPendingTotpReset] = useState<ManagedUser | null>(null);
    const totpResetForm = useForm({ password: '' });

    const columns: Column<ManagedUser>[] = [
        {
            header: 'Nama',
            accessorKey: 'name',
            sortable: true,
            cell: (user) => (
                <div className="space-y-1">
                    <div className="font-medium">{user.name}</div>
                    <div className="text-xs text-muted-foreground">{user.email}</div>
                </div>
            ),
        },
        {
            header: 'Peran',
            accessorKey: 'role',
            sortable: true,
            cell: (user) => <Badge variant="secondary">{roleLabels[user.role] ?? user.role}</Badge>,
        },
        {
            header: 'TOTP',
            accessorKey: 'totp_enabled',
            cell: (user) => (
                <StatusBadge
                    label={user.totp_enabled ? 'Aktif' : 'Belum aktif'}
                    tone={user.totp_enabled ? 'up' : 'degraded'}
                />
            ),
        },
        {
            header: 'Area',
            cell: (user) => (
                <span className="text-sm text-muted-foreground">
                    {user.areas?.length ? user.areas.map((area) => area.name).join(', ') : 'Semua area'}
                </span>
            ),
        },
        {
            header: 'Telepon',
            accessorKey: 'phone',
            cell: (user) => <span className="text-sm text-muted-foreground">{user.phone || '-'}</span>,
        },
        {
            header: 'Aksi',
            className: 'w-[180px] text-right',
            cell: (user) => (
                <div className="flex justify-end gap-1" onClick={(event) => event.stopPropagation()}>
                    <Button asChild variant="ghost" size="icon" className="h-8 w-8" title={`Edit ${user.name}`}>
                        <Link href={route('users.edit', user.id)} aria-label={`Edit ${user.name}`}>
                            <Pencil className="h-4 w-4" />
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 text-destructive hover:text-destructive"
                        title={`Hapus ${user.name}`}
                        aria-label={`Hapus ${user.name}`}
                        onClick={() => setUserPendingDeletion(user)}
                    >
                        <Trash2 className="h-4 w-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8"
                        title={`Reset TOTP ${user.name}`}
                        aria-label={`Reset TOTP ${user.name}`}
                        onClick={() => setUserPendingTotpReset(user)}
                    >
                        <ShieldCheck className="h-4 w-4" />
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <EnterpriseIndexLayout
            title="Pengguna"
            pageTitle="Pengguna"
            subtitle="Kelola akun dan akses operator eBilling"
            icon={UserRound}
            breadcrumbs={[{ label: 'Pengguna', href: route('users.index') }]}
        >
            <DataTable
                compact
                data={users}
                columns={columns}
                filters={filters}
                title=""
                description={`Menampilkan ${users.data.length} dari ${users.total} pengguna`}
                searchPlaceholder="Cari nama atau email..."
                routeName="users.index"
                actions={
                    <Button asChild size="sm">
                        <Link href={route('users.create')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Tambah Pengguna
                        </Link>
                    </Button>
                }
                filterConfigs={[
                    {
                        key: 'role',
                        placeholder: 'Peran',
                        options: [
                            { value: 'all', label: 'Semua peran' },
                            { value: 'superadmin', label: 'Superadmin' },
                            { value: 'admin', label: 'Admin' },
                            { value: 'noc', label: 'NOC' },
                            { value: 'teknisi', label: 'Teknisi' },
                        ],
                    },
                ]}
            />
            <ConfirmDestructive
                open={userPendingDeletion !== null}
                onOpenChange={(open) => {
                    if (!open) setUserPendingDeletion(null);
                }}
                title="Hapus akun pengguna"
                description={
                    userPendingDeletion
                        ? `Akun ${userPendingDeletion.name} akan dihapus dan aksesnya dicabut.`
                        : 'Pilih akun pengguna yang akan dihapus.'
                }
                confirmationLabel="Saya memahami akun ini akan dihapus dan tindakan ini tidak dapat dibatalkan."
                confirmText="Hapus akun"
                onConfirm={() => {
                    if (!userPendingDeletion) return;

                    router.delete(route('users.destroy', userPendingDeletion.id), {
                        onFinish: () => setUserPendingDeletion(null),
                    });
                }}
            />
            <ConfirmDestructive
                open={userPendingTotpReset !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setUserPendingTotpReset(null);
                        totpResetForm.reset();
                        totpResetForm.clearErrors();
                    }
                }}
                title="Reset TOTP pengguna"
                description={
                    userPendingTotpReset
                        ? `Enrollment TOTP ${userPendingTotpReset.name} akan dihapus. Pengguna harus enroll ulang.`
                        : 'Pilih akun yang akan di-reset.'
                }
                confirmationLabel="Saya memahami pengguna akan diminta enroll ulang."
                confirmText="Reset TOTP"
                onConfirm={() => {
                    if (!userPendingTotpReset) return;

                    totpResetForm.post(route('users.totp-reset', userPendingTotpReset.id), {
                        preserveScroll: true,
                        onSuccess: () => {
                            setUserPendingTotpReset(null);
                            totpResetForm.reset();
                        },
                    });
                }}
                isLoading={totpResetForm.processing}
            >
                <div className="grid gap-2">
                    <Label htmlFor="totp-reset-password">Password superadmin saat ini</Label>
                    <Input
                        id="totp-reset-password"
                        type="password"
                        autoComplete="current-password"
                        value={totpResetForm.data.password}
                        onChange={(event) => totpResetForm.setData('password', event.target.value)}
                        aria-invalid={Boolean(totpResetForm.errors.password)}
                        required
                    />
                    {totpResetForm.errors.password && <p className="text-sm text-destructive">{totpResetForm.errors.password}</p>}
                </div>
            </ConfirmDestructive>
        </EnterpriseIndexLayout>
    );
}
