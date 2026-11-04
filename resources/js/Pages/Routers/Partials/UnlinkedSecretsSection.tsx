import { forwardRef, useEffect, useState } from 'react';
import useSWR from 'swr';
import { Link } from '@inertiajs/react';
import { formatDistanceToNow } from 'date-fns';
import { id as idLocale } from 'date-fns/locale';
import { Copy, Trash2, UserPlus } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Skeleton } from '@/Components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { StatusBadge } from '@/Components/StatusBadge';
import { DataTablePagination } from '@/Components/DataTablePagination';
import { PaginatedData } from '@/Components/DataTable';
import DeleteUnlinkedSecretDialog from './DeleteUnlinkedSecretDialog';

interface UnlinkedSecret {
    id: number;
    pppoe_user: string;
    profile: string | null;
    disabled: boolean;
    last_seen_at: string | null;
    ip: string | null;
}

interface UnlinkedSecretsMeta {
    router_active: boolean;
    last_scanned_at: string | null;
    can_add: boolean;
    /** Hanya superadmin/NOC, flag menyala, mode aman mati. Kalau false, tombol Hapus tidak tampil. */
    can_delete: boolean;
}

type UnlinkedSecretsPage = PaginatedData<UnlinkedSecret> & { meta: UnlinkedSecretsMeta };

const PER_PAGE_OPTIONS = [25];

const fetcher = async (url: string): Promise<UnlinkedSecretsPage> => {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error('Gagal memuat daftar secret.');
    return res.json();
};

function copyUsername(value: string) {
    navigator.clipboard
        .writeText(value)
        .then(() => toast.success('Username disalin'))
        .catch(() => toast.error('Gagal menyalin'));
}

function lastSeenLabel(value: string | null): string {
    if (!value) return '-';
    return `${formatDistanceToNow(new Date(value), { locale: idLocale })} lalu`;
}

interface UnlinkedSecretsSectionProps {
    routerId: number;
}

/** Daftar secret di router yang belum punya pelanggan di eBilling. Hanya baca; tidak mengubah router. */
const UnlinkedSecretsSection = forwardRef<HTMLDivElement, UnlinkedSecretsSectionProps>(function UnlinkedSecretsSection(
    { routerId },
    ref,
) {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [deleteRow, setDeleteRow] = useState<UnlinkedSecret | null>(null);

    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearch(search.trim());
            setPage(1);
        }, 400);
        return () => clearTimeout(timer);
    }, [search]);

    const params = new URLSearchParams({ page: String(page), search: debouncedSearch });
    const { data, error, isLoading, mutate } = useSWR<UnlinkedSecretsPage>(
        `/api/routers/${routerId}/unlinked-secrets?${params.toString()}`,
        fetcher,
        { keepPreviousData: true },
    );

    const canAdd = data?.meta.can_add ?? false;
    const canDelete = data?.meta.can_delete ?? false;

    return (
        <div ref={ref} id="unlinked-secrets" className="scroll-mt-24 space-y-4 border-t border-border pt-6">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <h3 className="text-base font-semibold">Secret belum terhubung ({data?.total ?? '...'})</h3>
                    <p className="max-w-3xl text-sm text-muted-foreground">
                        Secret ada di MikroTik tapi belum ada pelanggannya di eBilling. Terakhir terlihat = sinkron terakhir
                        yang masih menemukan secret ini, bukan waktu login.
                    </p>
                    {data && !data.meta.router_active && (
                        <p className="text-sm font-medium text-status-degraded">Router diarsipkan — daftar hanya untuk dilihat.</p>
                    )}
                </div>
                <Button variant="outline" size="sm" onClick={() => mutate()}>
                    Muat ulang
                </Button>
            </div>

            <Input
                type="search"
                aria-label="Cari username secret"
                placeholder="Cari username..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                maxLength={64}
                className="max-w-sm"
            />

            <div className="overflow-hidden rounded-md border border-border bg-card">
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow>
                            <TableHead>Username</TableHead>
                            <TableHead>Profil</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>IP</TableHead>
                            <TableHead>Terakhir terlihat</TableHead>
                            <TableHead className="text-right">Aksi</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {isLoading && !data ? (
                            Array.from({ length: 5 }).map((_, i) => (
                                <TableRow key={i}>
                                    {Array.from({ length: 6 }).map((__, j) => (
                                        <TableCell key={j}>
                                            <Skeleton className="h-4 w-20" />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : error ? (
                            <TableRow>
                                <TableCell colSpan={6} className="h-24 text-center text-status-down">
                                    Gagal memuat daftar. Coba muat ulang.
                                </TableCell>
                            </TableRow>
                        ) : data && data.data.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                                    {debouncedSearch ? 'Tidak ada secret yang cocok.' : 'Semua secret sudah terhubung.'}
                                </TableCell>
                            </TableRow>
                        ) : (
                            data?.data.map((row) => (
                                <TableRow key={row.id} className="hover:bg-muted/20">
                                    <TableCell className="whitespace-nowrap py-1.5 font-mono text-sm">
                                        <span className="inline-flex items-center gap-1">
                                            {row.pppoe_user}
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="h-6 w-6"
                                                aria-label={`Salin username ${row.pppoe_user}`}
                                                onClick={() => copyUsername(row.pppoe_user)}
                                            >
                                                <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                                            </Button>
                                        </span>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap py-1.5 text-sm">{row.profile || '-'}</TableCell>
                                    <TableCell className="py-1.5">
                                        <StatusBadge label={row.disabled ? 'Nonaktif' : 'Aktif'} tone={row.disabled ? 'neutral' : 'up'} />
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap py-1.5 font-mono text-xs text-muted-foreground">
                                        {row.ip || '-'}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap py-1.5 text-sm text-muted-foreground">
                                        {lastSeenLabel(row.last_seen_at)}
                                    </TableCell>
                                    <TableCell className="py-1.5 text-right">
                                        <div className="flex items-center justify-end gap-2">
                                            {canAdd && (
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={route('customers.create', { staged: row.id })}>
                                                        <UserPlus className="mr-1 h-4 w-4" aria-hidden="true" />
                                                        Tambah pelanggan
                                                    </Link>
                                                </Button>
                                            )}
                                            {canDelete && (
                                                <Button size="sm" variant="outline" onClick={() => setDeleteRow(row)}>
                                                    <Trash2 className="mr-1 h-4 w-4 text-destructive" aria-hidden="true" />
                                                    Hapus secret
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            <DeleteUnlinkedSecretDialog
                routerId={routerId}
                row={deleteRow}
                onClose={() => setDeleteRow(null)}
                onChanged={() => void mutate()}
            />

            {data && (
                <DataTablePagination
                    data={data}
                    onPageChange={setPage}
                    onPageSizeChange={() => undefined}
                    pageSizeOptions={PER_PAGE_OPTIONS}
                />
            )}
        </div>
    );
});

export default UnlinkedSecretsSection;
