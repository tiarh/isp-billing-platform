/**
 * Database VLAN service per MikroTik — tab Aktivasi.
 * Nomor VLAN boleh kosong dulu; diisi kapan NOC siap (Sprint 2 HTB).
 */
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Badge } from '@/Components/ui/badge';
import { Switch } from '@/Components/ui/switch';
import { Skeleton } from '@/Components/ui/skeleton';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import { toast } from 'sonner';
import { Loader2, Plus, Trash2, RefreshCw, Save } from 'lucide-react';

type RouterOpt = { id: number; name: string; ip_address?: string };
type AreaOpt = { id: number; name: string };
type RouterVlanRow = {
    id: number;
    router_id: number;
    vlan: number;
    label: string | null;
    area_id: number | null;
    mikrotik_profile: string | null;
    is_default: boolean;
    is_active: boolean;
    notes: string | null;
    router?: RouterOpt;
    area?: AreaOpt | null;
};

const csrf = () => (window as any).csrfToken || '';

export default function RouterVlanPanel() {
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [deleteConfirmRow, setDeleteConfirmRow] = useState<RouterVlanRow | null>(null);
    const [routers, setRouters] = useState<RouterOpt[]>([]);
    const [areas, setAreas] = useState<AreaOpt[]>([]);
    const [rows, setRows] = useState<RouterVlanRow[]>([]);
    const [filterRouterId, setFilterRouterId] = useState<string>('');

    // form
    const [routerId, setRouterId] = useState('');
    const [vlan, setVlan] = useState('');
    const [label, setLabel] = useState('');
    const [areaId, setAreaId] = useState('');
    const [profile, setProfile] = useState('');
    const [isDefault, setIsDefault] = useState(false);
    const [notes, setNotes] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const q = filterRouterId ? `?router_id=${filterRouterId}` : '';
            const res = await fetch(`/api/router-vlans${q}`, {
                credentials: 'include',
                headers: { Accept: 'application/json' },
            });
            const d = await res.json();
            if (!res.ok) throw new Error(d.message || 'Gagal load');
            setRows(d.data || []);
            setRouters(d.routers || []);
            setAreas(d.areas || []);
            if (!routerId && (d.routers || []).length) {
                // no auto pick
            }
        } catch (e: any) {
            toast.error(e?.message || 'Gagal load VLAN MT');
        } finally {
            setLoading(false);
        }
    }, [filterRouterId, routerId]);

    useEffect(() => {
        load();
    }, [load]);

    const filteredHint = useMemo(() => {
        if (!filterRouterId) return 'Semua MikroTik';
        const r = routers.find((x) => String(x.id) === filterRouterId);
        return r ? r.name : `Router #${filterRouterId}`;
    }, [filterRouterId, routers]);

    const resetForm = () => {
        setVlan('');
        setLabel('');
        setAreaId('');
        setProfile('');
        setIsDefault(false);
        setNotes('');
    };

    const handleSave = async () => {
        if (!routerId || !vlan) {
            toast.error('Pilih MikroTik dan isi nomor VLAN');
            return;
        }
        const v = parseInt(vlan, 10);
        if (!v || v < 1 || v > 4094) {
            toast.error('VLAN harus 1–4094');
            return;
        }
        setSaving(true);
        try {
            const res = await fetch('/api/router-vlans', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    router_id: parseInt(routerId, 10),
                    vlan: v,
                    label: label || null,
                    area_id: areaId ? parseInt(areaId, 10) : null,
                    mikrotik_profile: profile || null,
                    is_default: isDefault,
                    is_active: true,
                    notes: notes || null,
                }),
            });
            const d = await res.json();
            if (!res.ok) throw new Error(d.message || JSON.stringify(d.errors || d) || 'Gagal simpan');
            toast.success(`VLAN ${v} disimpan untuk MT`);
            resetForm();
            await load();
        } catch (e: any) {
            toast.error(e?.message || 'Gagal simpan');
        } finally {
            setSaving(false);
        }
    };

    const toggleDefault = async (row: RouterVlanRow) => {
        try {
            const res = await fetch(`/api/router-vlans/${row.id}`, {
                method: 'PUT',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ is_default: !row.is_default }),
            });
            const d = await res.json();
            if (!res.ok) throw new Error(d.message || 'Gagal update');
            await load();
        } catch (e: any) {
            toast.error(e?.message || 'Gagal update default');
        }
    };

    const handleDelete = async (row: RouterVlanRow) => {
        setDeleting(true);
        try {
            const res = await fetch(`/api/router-vlans/${row.id}`, {
                method: 'DELETE',
                credentials: 'include',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
            if (!res.ok) {
                const d = await res.json().catch(() => ({}));
                throw new Error(d.message || 'Gagal hapus');
            }
            toast.success('Dihapus');
            setDeleteConfirmRow(null);
            await load();
        } catch (e: any) {
            toast.error(e?.message || 'Gagal hapus');
        } finally {
            setDeleting(false);
        }
    };

    return (
        <div className="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Database VLAN per MikroTik</CardTitle>
                    <CardDescription>
                        Sumber kebenaran VLAN service untuk site HTB/HiOSO (pppoe_vlan). Bukan map PON OLT C300.
                        Nomor VLAN boleh diisi belakangan — daftar MT dulu, angka menyusul.
                        Site target: Bumiayu, Srigading, Martopuro, Gadang, Mangliawan.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div className="space-y-2">
                            <Label>MikroTik</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={routerId}
                                onChange={(e) => setRouterId(e.target.value)}
                            >
                                <option value="">— pilih —</option>
                                {routers.map((r) => (
                                    <option key={r.id} value={String(r.id)}>
                                        {r.name}
                                        {r.ip_address ? ` (${r.ip_address})` : ''}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>VLAN (1–4094)</Label>
                            <Input
                                type="number"
                                min={1}
                                max={4094}
                                value={vlan}
                                onChange={(e) => setVlan(e.target.value)}
                                placeholder="boleh diisi nanti"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Label</Label>
                            <Input value={label} onChange={(e) => setLabel(e.target.value)} placeholder="mis. PPPoE utama" />
                        </div>
                        <div className="space-y-2">
                            <Label>Area (opsional)</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={areaId}
                                onChange={(e) => setAreaId(e.target.value)}
                            >
                                <option value="">— semua / tidak spesifik —</option>
                                {areas.map((a) => (
                                    <option key={a.id} value={String(a.id)}>
                                        {a.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>PPP profile MT (opsional)</Label>
                            <Input
                                value={profile}
                                onChange={(e) => setProfile(e.target.value)}
                                placeholder="nama profile di MikroTik"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Catatan</Label>
                            <Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="opsional" />
                        </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <Switch checked={isDefault} onCheckedChange={setIsDefault} id="rv-default" />
                            <Label htmlFor="rv-default">Default untuk MT ini</Label>
                        </div>
                        <Button onClick={handleSave} disabled={saving} className="gap-2">
                            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                            Simpan VLAN
                        </Button>
                        <Button variant="outline" onClick={load} disabled={loading} className="gap-2">
                            <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                            Refresh
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                    <div>
                        <CardTitle className="text-base">Daftar tersimpan</CardTitle>
                        <CardDescription>
                            Filter: {filteredHint} · {rows.length} baris
                        </CardDescription>
                    </div>
                    <select
                        className="flex h-9 rounded-md border border-input bg-background px-3 text-sm"
                        value={filterRouterId}
                        onChange={(e) => setFilterRouterId(e.target.value)}
                    >
                        <option value="">Semua MT</option>
                        {routers.map((r) => (
                            <option key={r.id} value={String(r.id)}>
                                {r.name}
                            </option>
                        ))}
                    </select>
                </CardHeader>
                <CardContent>
                    {loading ? (
                        <div className="space-y-2" aria-label="Memuat daftar VLAN">
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                            <Skeleton className="h-10 w-full" />
                        </div>
                    ) : rows.length === 0 ? (
                        <p className="text-sm text-muted-foreground py-6">
                            Belum ada VLAN. Isi form di atas kapan nomor VLAN site HTB sudah pasti — Sprint 2 akan
                            baca dari tabel ini.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="py-2 pr-3">MikroTik</th>
                                        <th className="py-2 pr-3">VLAN</th>
                                        <th className="py-2 pr-3">Label</th>
                                        <th className="py-2 pr-3">Area</th>
                                        <th className="py-2 pr-3">Profile</th>
                                        <th className="py-2 pr-3">Flags</th>
                                        <th className="py-2">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="border-b border-border/60">
                                            <td className="py-2 pr-3 font-medium">{row.router?.name || row.router_id}</td>
                                            <td className="py-2 pr-3 font-mono">{row.vlan}</td>
                                            <td className="py-2 pr-3">{row.label || '—'}</td>
                                            <td className="py-2 pr-3">{row.area?.name || '—'}</td>
                                            <td className="py-2 pr-3 font-mono text-xs">{row.mikrotik_profile || '—'}</td>
                                            <td className="py-2 pr-3 space-x-1">
                                                {row.is_default && <Badge variant="secondary">default</Badge>}
                                                {!row.is_active && <Badge variant="outline">off</Badge>}
                                            </td>
                                            <td className="py-2 space-x-1 whitespace-nowrap">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => toggleDefault(row)}
                                                    aria-label={`Jadikan VLAN ${row.vlan} default`}
                                                    title="Set default"
                                                >
                                                    <Save className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setDeleteConfirmRow(row)}
                                                    aria-label={`Hapus VLAN ${row.vlan}`}
                                                    title="Hapus VLAN"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5 text-destructive" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </CardContent>
            </Card>
            <ConfirmDestructive
                open={Boolean(deleteConfirmRow)}
                onOpenChange={(open) => { if (!open) setDeleteConfirmRow(null); }}
                title="Hapus VLAN?"
                description={deleteConfirmRow ? `VLAN ${deleteConfirmRow.vlan} di ${deleteConfirmRow.router?.name || deleteConfirmRow.router_id} akan dihapus dari daftar.` : 'VLAN akan dihapus dari daftar.'}
                confirmationLabel="Saya memahami konfigurasi VLAN ini akan dihapus."
                confirmText="Hapus VLAN"
                onConfirm={() => { if (deleteConfirmRow) void handleDelete(deleteConfirmRow); }}
                isLoading={deleting}
            />
        </div>
    );
}
