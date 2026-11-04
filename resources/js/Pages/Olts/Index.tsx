import { useState } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';
import {
    Plus, Search, Network, MapPin, PlugZap, Edit3, Trash2,
    Wifi, Globe, Radio, Server, ChevronRight, LockKeyhole, Monitor, Globe2, RadioTower, CircleHelp
} from 'lucide-react';
import { Input } from '@/Components/ui/input';

interface Olt {
    id: number;
    name: string;
    code: string;
    vendor: string | null;
    management_ip: string | null;
    management_protocol: string | null;
    management_port: number | null;
    location: string | null;
    area: { id: number; name: string } | null;
    router: { id: number; name: string } | null;
    customer_count?: number;
}

interface Props {
    olts: {
        data: Olt[];
        total: number;
        current_page: number;
        last_page: number;
        per_page: number;
    };
    filters?: {
        search?: string;
        limit?: number;
    };
}

const vendorInfo = (vendor: string | null) => {
    if (vendor === 'hioso') return { label: 'Hioso', color: 'bg-muted text-muted-foreground', icon: Globe };
    if (vendor === 'huawei_ma5608t') return { label: 'Huawei MA5608T', color: 'bg-primary-soft text-primary-soft-foreground', icon: RadioTower };
    return { label: 'ZTE C300', color: 'bg-secondary text-secondary-foreground', icon: Server };
};

const protocolInfo = (protocol: string | null) => {
    switch (protocol) {
        case 'ssh': return { label: 'SSH', icon: LockKeyhole, desc: 'Akses CLI terenkripsi' };
        case 'telnet': return { label: 'Telnet', icon: Monitor, desc: 'Akses CLI tanpa enkripsi' };
        case 'http': return { label: 'HTTP', icon: Globe2, desc: 'Akses web (tidak terenkripsi)' };
        case 'https': return { label: 'HTTPS', icon: LockKeyhole, desc: 'Akses web terenkripsi' };
        case 'snmp': return { label: 'SNMP', icon: RadioTower, desc: 'Monitoring via SNMP' };
        default: return { label: '-', icon: CircleHelp, desc: 'Belum dikonfigurasi' };
    }
};

export default function Index({ olts, filters = {} }: Props) {
    const [oltToDelete, setOltToDelete] = useState<Olt | null>(null);
    const [search, setSearch] = useState(filters.search || '');

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('olts.index'), { search }, { preserveState: true });
    };

    return (
        <EnterpriseIndexLayout
            title="Manajemen OLT"
            pageTitle="Manajemen OLT"
            subtitle={`Enterprise OLT collection — ${olts.total} perangkat terdaftar`}
            icon={Network}
            breadcrumbs={[{ label: 'Manajemen OLT', href: route('olts.index') }]}
            actions={
                <Button asChild size="sm" className="h-9 gap-2 bg-primary">
                    <Link href={route('olts.create')}>
                        <Plus className="h-4 w-4" />
                        Tambah OLT
                    </Link>
                </Button>
            }
        >
            <div className="mx-auto max-w-7xl space-y-6">
                    <form onSubmit={handleSearch} className="flex gap-2 justify-end">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Cari nama, kode, IP..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-9 h-9 w-[240px]"
                                />
                            </div>
                            <Button type="submit" variant="outline" size="sm" className="h-9">
                                Cari
                            </Button>
                    </form>

                    {/* OLT Cards Grid */}
                    {olts.data.length === 0 ? (
                        <div className="flex flex-col items-center justify-center py-16 text-center">
                            <Network className="h-16 w-16 text-muted-foreground/30 mb-4" />
                            <h3 className="text-lg font-medium text-muted-foreground">Belum ada OLT</h3>
                            <p className="text-sm text-muted-foreground mt-1 mb-4">
                                Tambahkan OLT pertama untuk mulai mengelola jaringan fiber optik.
                            </p>
                            <Button asChild>
                                <Link href={route('olts.create')}>
                                    <Plus className="h-4 w-4 mr-2" />
                                    Tambah OLT
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                            {olts.data.map((olt) => {
                                const vi = vendorInfo(olt.vendor);
                                const pi = protocolInfo(olt.management_protocol);
                                const VendorIcon = vi.icon;

                                return (
                                    <Card key={olt.id} className="group relative overflow-hidden border-border/60 hover:border-primary/40 hover:shadow-md transition-all duration-200">
                                        <CardContent className="p-0">
                                            {/* Card Header — Vendor Color Bar */}
                                            <div className="border-b border-border/40 bg-muted/30 px-5 pb-3 pt-4">
                                                <div className="flex items-start justify-between">
                                                    <div className="flex-1 min-w-0">
                                                        <Link
                                                            href={route('olts.show', olt.id)}
                                                            className="text-base font-semibold text-foreground hover:text-primary transition-colors truncate block"
                                                        >
                                                            {olt.name}
                                                        </Link>
                                                        <div className="flex items-center gap-2 mt-1">
                                                            <Badge variant="outline" className={`text-[10px] font-medium px-2 py-0 border-0 ${vi.color}`}>
                                                                <VendorIcon className="h-3 w-3 mr-1" />
                                                                {vi.label}
                                                            </Badge>
                                                            <span className="text-xs font-mono text-muted-foreground">
                                                                {olt.code}
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <Link
                                                        href={route('olts.show', olt.id)}
                                                        className="text-muted-foreground hover:text-primary transition-colors mt-1"
                                                        aria-label={`Buka detail ${olt.name}`}
                                                    >
                                                        <ChevronRight className="h-5 w-5" aria-hidden="true" />
                                                    </Link>
                                                </div>
                                            </div>

                                            {/* Card Body */}
                                            <div className="px-5 py-4 space-y-3">
                                                {/* Connection Info */}
                                                <div className="flex items-center gap-2 text-sm">
                                                    <pi.icon className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                                                    <div>
                                                        <span className="font-medium text-foreground">{pi.label}</span>
                                                        <span className="text-muted-foreground mx-1">·</span>
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {olt.management_ip || '-'}{olt.management_port ? `:${olt.management_port}` : ''}
                                                        </span>
                                                    </div>
                                                </div>

                                                {/* Area & Router */}
                                                <div className="grid grid-cols-2 gap-2 text-xs">
                                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                                        <MapPin className="h-3.5 w-3.5 shrink-0" />
                                                        <span className="truncate">{olt.area?.name || 'Tanpa area'}</span>
                                                    </div>
                                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                                        <Radio className="h-3.5 w-3.5 shrink-0" />
                                                        <span className="truncate">{olt.router?.name || 'Tanpa router'}</span>
                                                    </div>
                                                </div>

                                                {/* Location */}
                                                {olt.location && (
                                                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                        <Wifi className="h-3.5 w-3.5 shrink-0" />
                                                        <span className="truncate">{olt.location}</span>
                                                    </div>
                                                )}
                                            </div>

                                            {/* Card Footer — Actions */}
                                            <div className="px-5 py-3 border-t border-border/40 bg-muted/20 flex items-center justify-between">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 gap-1.5 text-xs"
                                                    onClick={() => router.post(route('olts.test', olt.id))}
                                                >
                                                    <PlugZap className="h-3.5 w-3.5" />
                                                    Test Koneksi
                                                </Button>
                                                <div className="flex items-center gap-1">
                                                    <Button variant="ghost" size="icon" className="h-7 w-7" asChild>
                                                        <Link href={route('olts.edit', olt.id)} title={`Edit ${olt.name}`} aria-label={`Edit ${olt.name}`}>
                                                            <Edit3 className="h-3.5 w-3.5" aria-hidden="true" />
                                                        </Link>
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="h-7 w-7 text-destructive hover:text-destructive"
                                                        onClick={() => setOltToDelete(olt)}
                                                        title={`Hapus ${olt.name}`}
                                                        aria-label={`Hapus ${olt.name}`}
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    )}

                    {/* Pagination */}
                    {olts.last_page > 1 && (
                        <div className="flex items-center justify-center gap-2 pt-4">
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                disabled={olts.current_page <= 1}
                                onClick={() => router.get(route('olts.index'), { page: olts.current_page - 1, search }, { preserveState: true })}
                            >
                                Sebelumnya
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                Hal {olts.current_page} dari {olts.last_page}
                            </span>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-8"
                                disabled={olts.current_page >= olts.last_page}
                                onClick={() => router.get(route('olts.index'), { page: olts.current_page + 1, search }, { preserveState: true })}
                            >
                                Berikutnya
                            </Button>
                        </div>
                    )}
            </div>

            <ConfirmDialog
                open={!!oltToDelete}
                onOpenChange={(open) => !open && setOltToDelete(null)}
                title="Hapus OLT?"
                description={`Yakin ingin menghapus "${oltToDelete?.name}"? Semua data pelanggan dan konfigurasi terkait akan ikut terhapus. Tindakan ini tidak bisa dibatalkan.`}
                confirmText="Hapus"
                variant="destructive"
                onConfirm={() => oltToDelete && router.delete(route('olts.destroy', oltToDelete.id), {
                    onFinish: () => setOltToDelete(null),
                })}
            />
        </EnterpriseIndexLayout>
    );
}
