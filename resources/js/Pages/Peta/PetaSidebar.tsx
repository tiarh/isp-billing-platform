import { StatusBadge } from '@/Components/StatusBadge';
import { formatNumber } from '@/lib/format';
import { Search, X } from 'lucide-react';
import { useState, useMemo, useEffect } from 'react';

interface MapEntry {
    name: string;
    geojson: any | null;
    loading: boolean;
}

interface Props {
    maps: Record<string, MapEntry>;
    stats: { odp: number; odc: number; pole: number; customer: number; customer_total: number; customer_missing_coordinates: number; cable_km: number };
    sidebarOpen: boolean;
    setSidebarOpen: (v: boolean) => void;
    isOverlay?: boolean;
    active: Record<string, boolean>;
    setActive: (v: Record<string, boolean>) => void;
    filterType: Record<string, boolean>;
    setFilterType: (v: Record<string, boolean>) => void;
    rwFilter: string;
    setRwFilter: (v: string) => void;
    rwOptions: string[];
    focusFeature: (name: string) => void;
    searchResults?: { id: number; area_code: string | null; area_name: string | null; name: string; code: string | null; odp: string | null; lat: number | null; lng: number | null }[];
    searchLoading?: boolean;
    onSearch?: (query: string) => void;
    focusSearchResult?: (result: NonNullable<Props['searchResults']>[number]) => void;
}

export function PetaSidebar({ maps, stats, sidebarOpen, setSidebarOpen, isOverlay = false, active, setActive, filterType, setFilterType, rwFilter, setRwFilter, rwOptions, focusFeature, searchResults = [], searchLoading = false, onSearch, focusSearchResult }: Props) {
    const [search, setSearch] = useState('');

    useEffect(() => { onSearch?.(search); }, [search, onSearch]);

    const localSearchResults = useMemo(() => {
        if (!search.trim()) return [];
        const q = search.toLowerCase();
        const res: { key: string; name: string; type: string }[] = [];
        Object.entries(maps).forEach(([key, entry]) => {
            entry.geojson?.features?.forEach((f: any) => {
                const nm = f.properties?.name || '';
                if (nm.toLowerCase().includes(q)) res.push({ key, name: nm, type: f.properties?.type || '' });
            });
        });
        return res.slice(0, 30);
    }, [search, maps]);

    const combinedSearchResults = [
        ...searchResults.map(result => ({ key: result.area_code || '', name: result.name, type: 'CUSTOMER', server: result })),
        ...localSearchResults.map(result => ({ ...result, server: undefined })),
    ].filter((result, index, all) => all.findIndex(candidate => candidate.name === result.name && candidate.key === result.key) === index).slice(0, 30);

    const filterBtn = (type: string, label: string, on: boolean, color: string) => (
        <button type="button" key={type} aria-pressed={on} onClick={() => setFilterType({ ...filterType, [type]: !on })}
            className={`px-2 py-1 rounded text-[10px] font-semibold border transition-all ${on ? color + ' text-white border-transparent' : 'bg-muted text-muted-foreground border-border'}`}>
            {label}
        </button>
    );

    const shellClass = isOverlay
        ? sidebarOpen
            ? 'fixed inset-y-0 left-0 z-[1002] w-[min(288px,85vw)] shadow-xl'
            : 'hidden'
        : sidebarOpen
            ? 'w-72 shrink-0'
            : 'w-0 shrink-0 overflow-hidden';

    return (
        <div className={`${shellClass} transition-all duration-300 border-r border-border bg-card flex flex-col`}>
            <div className="min-w-[288px] flex flex-col h-full max-h-[100dvh] md:max-h-none">
                {/* Header */}
                <div className="px-4 py-3 border-b border-border flex items-center justify-between">
                    <div>
                        <h1 className="text-sm font-bold text-foreground">Peta Aset Skynet</h1>
                        <p className="text-[10px] text-muted-foreground mt-0.5">ODP / ODC / POP Mapping</p>
                    </div>
                    <button type="button" aria-label="Tutup panel peta" onClick={() => setSidebarOpen(false)} className="p-1 rounded hover:bg-muted"><X className="h-4 w-4 text-muted-foreground" aria-hidden /></button>
                </div>

                {/* Search */}
                <div className="relative p-3 border-b border-border">
                    <Search className="absolute left-5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" aria-hidden />
                    <input type="text" aria-label="Cari ODP, pelanggan..." placeholder="Cari ODP, pelanggan..." value={search} onChange={e => setSearch(e.target.value)}
                        className="w-full h-8 pl-8 pr-3 text-xs rounded-md border border-input bg-background focus:outline-none focus:ring-1 focus:ring-primary" />
                    {searchLoading && <span className="absolute right-5 top-1/2 h-2.5 w-6 -translate-y-1/2 rounded-sm bg-muted-foreground/30" role="status" aria-label="Mencari" />}
                    {combinedSearchResults.length > 0 && (
                        <div className="absolute left-3 right-3 top-full mt-1 bg-popover border border-border rounded-md shadow-lg z-20 max-h-48 overflow-y-auto">
                            {combinedSearchResults.map((r, i) => (
                                <button type="button" key={i} onClick={() => { r.server ? focusSearchResult?.(r.server) : focusFeature(r.name); setSearch(''); }}
                                    className="w-full text-left px-3 py-1.5 text-xs hover:bg-muted transition-colors flex items-center gap-2">
                                    <span aria-hidden className={`w-2 h-2 rounded-full shrink-0 ${r.type === 'ODC' ? 'bg-primary' : r.type === 'ODP' ? 'bg-status-down' : 'bg-status-info'}`} />
                                    <span className="font-medium truncate">{r.name}</span>
                                    <span className="ml-auto text-muted-foreground text-[10px]">{r.key}</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Stats */}
                <div className="px-3 py-2 border-b border-border">
                    <div className="grid grid-cols-3 gap-1.5 text-xs">
                        <div className="rounded-md bg-muted p-2 text-center"><div className="font-data text-base font-semibold text-foreground">{formatNumber(stats.odp, { maximumFractionDigits: 0 })}</div><div className="text-muted-foreground text-[10px]">ODP</div></div>
                        <div className="rounded-md bg-muted p-2 text-center"><div className="font-data text-base font-semibold text-foreground">{formatNumber(stats.odc, { maximumFractionDigits: 0 })}</div><div className="text-muted-foreground text-[10px]">ODC/POP</div></div>
                        <div className="rounded-md bg-muted p-2 text-center"><div className="font-data text-base font-semibold text-foreground">{formatNumber(stats.customer, { maximumFractionDigits: 0 })}</div><div className="text-muted-foreground text-[10px]">Di peta</div></div>
                    </div>
                    <div className="mt-1 text-[10px] leading-snug text-muted-foreground">
                        Total DB: <span className="font-data font-semibold text-foreground">{formatNumber(stats.customer_total, { maximumFractionDigits: 0 })}</span>
                        {' · '}Belum tikor: <span className="font-data font-semibold text-foreground">{formatNumber(stats.customer_missing_coordinates, { maximumFractionDigits: 0 })}</span>
                        <div className="mt-1.5"><StatusBadge label={stats.customer_missing_coordinates > 0 ? 'Perlu tikor' : 'Koordinat lengkap'} tone={stats.customer_missing_coordinates > 0 ? 'degraded' : 'up'} /></div>
                    </div>
                    <div className="mt-1.5 grid grid-cols-2 gap-1.5 text-xs">
                        <div className="rounded-md bg-muted p-2 text-center"><span className="font-data font-semibold text-foreground">{formatNumber(stats.pole, { maximumFractionDigits: 0 })}</span><span className="text-muted-foreground ml-1">Pole</span></div>
                        <div className="rounded-md bg-muted p-2 text-center"><span className="font-data font-semibold text-foreground">{formatNumber(stats.cable_km, { maximumFractionDigits: 1 })} km</span><span className="text-muted-foreground ml-1">Kabel</span></div>
                    </div>
                </div>

                {/* Filter */}
                <div className="px-3 py-2 border-b border-border">
                    <div className="text-[10px] font-semibold text-muted-foreground mb-1.5 uppercase tracking-wider">Filter</div>
                    <div className="flex flex-wrap gap-1">
                        {filterBtn('ODP', 'ODP', filterType.ODP, 'bg-red-600')}
                        {filterBtn('ODC', 'ODC', filterType.ODC, 'bg-violet-600')}
                        {filterBtn('POLE', 'Pole', filterType.POLE, 'bg-green-700')}
                        {filterBtn('CUSTOMER', 'Pelanggan', filterType.CUSTOMER, 'bg-sky-700')}
                        {filterBtn('CABLE', 'Kabel', filterType.CABLE, 'bg-blue-600')}
                    </div>
                    <select
                        aria-label="Filter RW"
                        value={rwFilter || '_all'}
                        onChange={(event) => setRwFilter(event.target.value === '_all' ? '' : event.target.value)}
                        className="mt-2 h-8 w-full rounded-md border border-input bg-background px-2 text-xs"
                    >
                        <option value="_all">Semua RW</option>
                        {rwOptions.map((rw) => <option key={rw} value={rw}>{rw}</option>)}
                    </select>
                    {filterType.CUSTOMER && (
                        <p className="mt-1.5 text-[10px] leading-snug text-muted-foreground">
                            Pelanggan bertikor tampil sebagai pin; warna menunjukkan online, offline, atau isolir.
                        </p>
                    )}
                </div>

                {/* Area toggles */}
                <div className="flex-1 overflow-y-auto p-3 space-y-1">
                    <div className="text-[10px] font-semibold text-muted-foreground mb-1.5 uppercase tracking-wider">Area</div>
                    {Object.entries(maps).map(([k, v]) => (
                        <label key={k} className="flex items-center gap-2 px-2 py-1.5 rounded hover:bg-muted cursor-pointer text-xs">
                            <input type="checkbox" checked={active[k]} onChange={e => setActive({ ...active, [k]: e.target.checked })}
                                className="rounded border-input" />
                            <span className="font-medium">{v.name}</span>
                            {v.loading && <span className="h-2 w-5 rounded-sm bg-muted-foreground/30" role="status" aria-label={`Memuat area ${v.name}`} />}
                            <span className="ml-auto font-data text-muted-foreground text-[10px]">{formatNumber(v.geojson?.features?.length || 0, { maximumFractionDigits: 0 })}</span>
                        </label>
                    ))}
                </div>

                {/* Footer */}
                <div className="px-3 py-2 border-t border-border text-center">
                    <span className="text-[10px] text-muted-foreground">Skynet eBilling &mdash; Aset Mapping</span>
                </div>
            </div>
        </div>
    );
}
