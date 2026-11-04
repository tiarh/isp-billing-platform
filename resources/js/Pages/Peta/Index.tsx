import { useState, useEffect, useRef, useMemo, useCallback } from 'react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { resolveIsDark } from '@/lib/theme';
import { MapPin, Menu, Satellite } from 'lucide-react';
import { PetaMap } from './PetaMap';
import { PetaSidebar } from './PetaSidebar';

interface AreaInfo {
    name: string;
    stats: { odp: number; odc: number; customers?: number; customers_total?: number; customers_missing_coordinates?: number };
    rws?: string[];
}

interface Props {
    areas: Record<string, AreaInfo>;
}

interface GeoJsonData {
    type: string;
    features: any[];
    stats: { odp?: number; odc?: number; pole?: number; cable_km?: number; customer_total?: number; customer_visible?: number; customer_clustered?: number; customer_missing_coordinates?: number };
}

interface CustomerSearchResult {
    id: number;
    area_code: string | null;
    area_name: string | null;
    name: string;
    code: string | null;
    odp: string | null;
    lat: number | null;
    lng: number | null;
}

function useIsDesktop() {
    const [isDesktop, setIsDesktop] = useState(() =>
        typeof window !== 'undefined' ? window.matchMedia('(min-width: 768px)').matches : true
    );
    useEffect(() => {
        const mq = window.matchMedia('(min-width: 768px)');
        const sync = () => setIsDesktop(mq.matches);
        sync();
        mq.addEventListener('change', sync);
        return () => mq.removeEventListener('change', sync);
    }, []);
    return isDesktop;
}

export default function Index({ areas }: Props) {
    const isDesktop = useIsDesktop();
    const [dark, setDark] = useState(() => resolveIsDark());
    const [sat, setSat] = useState(false);
    const [sidebarOpen, setSidebarOpen] = useState(() =>
        typeof window !== 'undefined' ? window.matchMedia('(min-width: 768px)').matches : true
    );
    const [active, setActive] = useState<Record<string, boolean>>(
        Object.keys(areas).reduce((a, k, index) => ({ ...a, [k]: index === 0 }), {})
    );
    const [filterType, setFilterType] = useState<Record<string, boolean>>({ ODP: true, ODC: true, POLE: true, OTHER: true, CABLE: true, CUSTOMER: false });
    const [rwFilter, setRwFilter] = useState('');
    const [focused, setFocused] = useState<string | null>(null);
    const [maps, setMaps] = useState<Record<string, { name: string; geojson: GeoJsonData | null; customerGeojson: GeoJsonData | null; loading: boolean }>>(
        Object.entries(areas).reduce((a, [k, v]) => ({ ...a, [k]: { name: v.name, geojson: null, customerGeojson: null, loading: false } }), {})
    );
    const mapFlyTo = useRef<((lat: number, lng: number) => void) | null>(null);
    const [viewport, setViewport] = useState<{ bbox: string; zoom: number } | null>(null);
    const [searchResults, setSearchResults] = useState<CustomerSearchResult[]>([]);
    const [searchLoading, setSearchLoading] = useState(false);
    const searchAbort = useRef<AbortController | null>(null);
    const searchTimer = useRef<number | null>(null);
    const customerAbort = useRef<AbortController | null>(null);
    const customerTimer = useRef<number | null>(null);

    useEffect(() => {
        const syncDark = () => setDark(resolveIsDark());
        syncDark();
        window.addEventListener('theme-change', syncDark);
        return () => window.removeEventListener('theme-change', syncDark);
    }, []);

    const loadArea = useCallback(async (areaCode: string) => {
        setMaps(prev => ({ ...prev, [areaCode]: { ...prev[areaCode], loading: true } }));
        try {
            const layers = ['ODP', 'ODC', 'POLE', 'OTHER', 'CABLE'].filter(key => filterType[key]).join(',');
            const params = new URLSearchParams({ layers, limit: '300' });
            const res = await fetch(`/api/peta/geojson/${areaCode}?${params.toString()}`);
            if (!res.ok) throw new Error('Failed to load');
            const data = await res.json();
            setMaps(prev => ({ ...prev, [areaCode]: { ...prev[areaCode], name: prev[areaCode].name, geojson: data, loading: false } }));
        } catch (e) {
            console.error('Failed to load area', areaCode, e);
            setMaps(prev => ({ ...prev, [areaCode]: { ...prev[areaCode], loading: false } }));
        }
    }, [filterType.ODP, filterType.ODC, filterType.POLE, filterType.OTHER, filterType.CABLE]);

    useEffect(() => {
        Object.entries(active).forEach(([k, on]) => {
            if (on && !maps[k]?.geojson && !maps[k]?.loading) loadArea(k);
        });
    }, [active, maps, loadArea]);

    useEffect(() => {
        Object.entries(active).forEach(([k, on]) => {
            if (on) loadArea(k);
        });
    }, [filterType.ODP, filterType.ODC, filterType.POLE, filterType.OTHER, filterType.CABLE, loadArea]);

    const loadCustomers = useCallback(async (areaCode: string, signal: AbortSignal) => {
        const params = new URLSearchParams({ limit: isDesktop ? '300' : '200', zoom: String(viewport?.zoom ?? 12) });
        if (rwFilter) params.set('rw', rwFilter);
        if (viewport?.bbox) params.set('bbox', viewport.bbox);
        try {
            const res = await fetch(`/api/peta/customers/${areaCode}?${params.toString()}`, { signal });
            if (!res.ok) throw new Error('Failed to load customer layer');
            const data = await res.json();
            setMaps(prev => ({ ...prev, [areaCode]: { ...prev[areaCode], customerGeojson: data } }));
        } catch (e: any) {
            if (e?.name !== 'AbortError') console.error('Failed to load customer layer', areaCode, e);
        }
    }, [isDesktop, rwFilter, viewport]);

    const searchCustomers = useCallback((term: string) => {
        if (searchTimer.current !== null) window.clearTimeout(searchTimer.current);
        searchAbort.current?.abort();
        if (term.trim().length < 2) {
            setSearchResults([]);
            setSearchLoading(false);
            return;
        }
        searchTimer.current = window.setTimeout(async () => {
            const controller = new AbortController();
            searchAbort.current = controller;
            setSearchLoading(true);
            try {
                const response = await fetch(`/api/peta/customer-search?q=${encodeURIComponent(term.trim())}&limit=20`, { signal: controller.signal });
                if (!response.ok) throw new Error('Failed to search customers');
                const data = await response.json();
                setSearchResults(data.results || []);
            } catch (error: any) {
                if (error?.name !== 'AbortError') setSearchResults([]);
            } finally {
                if (searchAbort.current === controller) {
                    searchAbort.current = null;
                    setSearchLoading(false);
                }
            }
        }, 300);
    }, []);

    useEffect(() => () => {
        if (searchTimer.current !== null) window.clearTimeout(searchTimer.current);
        searchAbort.current?.abort();
    }, []);

    const focusSearchResult = useCallback((result: CustomerSearchResult) => {
        if (result.area_code) setActive(prev => ({ ...prev, [result.area_code as string]: true }));
        if (result.lat !== null && result.lng !== null) mapFlyTo.current?.(result.lat, result.lng);
    }, []);

    useEffect(() => {
        if (!filterType.CUSTOMER) {
            customerAbort.current?.abort();
            setMaps(prev => Object.fromEntries(Object.entries(prev).map(([k, v]) => [k, { ...v, customerGeojson: null }])));
            return;
        }
        if (customerTimer.current !== null) window.clearTimeout(customerTimer.current);
        customerAbort.current?.abort();
        const controller = new AbortController();
        customerAbort.current = controller;
        const areasToLoad = Object.entries(active).filter(([, on]) => on).map(([key]) => key);
        customerTimer.current = window.setTimeout(async () => {
            for (const areaCode of areasToLoad) {
                if (controller.signal.aborted) return;
                await loadCustomers(areaCode, controller.signal);
            }
        }, 350);
        return () => {
            if (customerTimer.current !== null) window.clearTimeout(customerTimer.current);
            controller.abort();
        };
    }, [active, filterType.CUSTOMER, rwFilter, viewport, loadCustomers]);

    const stats = useMemo(() => {
        const s = { odp: 0, odc: 0, pole: 0, customer: 0, customer_total: 0, customer_missing_coordinates: 0, cable_km: 0 };
        Object.values(areas).forEach(a => {
            s.odp += a.stats.odp || 0;
            s.odc += a.stats.odc || 0;
            s.customer_total += a.stats.customers_total || a.stats.customers || 0;
            s.customer_missing_coordinates += a.stats.customers_missing_coordinates || 0;
            if (!filterType.CUSTOMER) s.customer += a.stats.customers || 0;
        });
        Object.values(maps).forEach(m => {
            if (m.geojson?.stats) {
                s.pole += m.geojson.stats.pole || 0;
                if (filterType.CUSTOMER) {
                    s.customer += m.customerGeojson?.stats?.customer_visible || 0;
                }
                s.cable_km += m.geojson.stats.cable_km || 0;
            }
        });
        return s;
    }, [areas, maps, filterType.CUSTOMER]);

    const rwOptions = useMemo(() => {
        const all = Object.values(areas).flatMap(a => a.rws || []);
        return Array.from(new Set(all)).sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
    }, [areas]);

    const focusFeature = (name: string) => {
        if (!isDesktop) setSidebarOpen(false);
        setFocused(name);
        for (const entry of Object.values(maps)) {
            for (const f of [...(entry.geojson?.features || []), ...(entry.customerGeojson?.features || [])]) {
                if (f.properties?.name === name && f.geometry?.type === 'Point') {
                    const c = f.geometry.coordinates;
                    mapFlyTo.current?.(c[1], c[0]);
                    setTimeout(() => setFocused(null), 2000);
                    return;
                }
            }
        }
    };

    const loadedMaps = useMemo(() => {
        const result: Record<string, { name: string; geojson: any; customerGeojson?: any }> = {};
        Object.entries(maps).forEach(([k, m]) => {
            if (m.geojson && active[k]) {
                result[k] = { name: m.name, geojson: m.geojson, customerGeojson: m.customerGeojson };
            }
        });
        return result;
    }, [maps, active]);

    const isLoading = Object.values(maps).some(m => m.loading);

    return (
        <EnterpriseIndexLayout
            title="Peta Aset Skynet"
            pageTitle="Peta Aset"
            subtitle="Mapping POP, ODC, ODP, dan kabel feeder per area"
            icon={MapPin}
            breadcrumbs={[{ label: 'Peta Aset' }]}
            actions={
                <button
                    type="button"
                    onClick={() => { setDark(false); setSat(s => !s); }}
                    className={`inline-flex h-9 items-center gap-1.5 rounded-lg border px-3 text-xs font-medium shadow-sm transition-colors ${sat ? 'border-primary bg-primary text-primary-foreground' : 'border-border bg-card hover:bg-muted'}`}
                    aria-pressed={sat}
                >
                    <Satellite className="h-3.5 w-3.5" aria-hidden />
                    Satelit
                </button>
            }
        >
            <div className="-mx-2 sm:-mx-4 lg:-mx-6 max-w-none">
                <div className="relative flex h-[calc(100vh-10rem)] min-h-[60vh] md:h-[calc(100vh-14rem)] md:min-h-[480px] overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    {sidebarOpen && !isDesktop && (
                        <button
                            type="button"
                            aria-label="Tutup panel peta"
                            className="fixed inset-0 z-[1001] bg-black/40 md:hidden"
                            onClick={() => setSidebarOpen(false)}
                        />
                    )}
                    <PetaSidebar
                        maps={maps}
                        stats={stats}
                        sidebarOpen={sidebarOpen}
                        setSidebarOpen={setSidebarOpen}
                        isOverlay={!isDesktop}
                        active={active}
                        setActive={setActive}
                        filterType={filterType}
                        setFilterType={setFilterType}
                        rwFilter={rwFilter}
                        setRwFilter={setRwFilter}
                        rwOptions={rwOptions}
                        focusFeature={focusFeature}
                        searchResults={searchResults}
                        searchLoading={searchLoading}
                        onSearch={searchCustomers}
                        focusSearchResult={focusSearchResult}
                    />
                    <div className="relative flex-1">
                        {isLoading && (
                            <div className="absolute left-1/2 top-3 z-[1001] flex -translate-x-1/2 items-center gap-2 rounded-lg border border-border bg-card px-4 py-2 shadow-lg" role="status" aria-live="polite">
                                <span className="h-2 w-8 rounded-sm bg-muted-foreground/30" aria-hidden />
                                <span className="text-xs font-medium">Memuat data peta...</span>
                            </div>
                        )}
                        <PetaMap
                            maps={loadedMaps}
                            active={active}
                            filterType={filterType}
                            focused={focused}
                            dark={dark}
                            sat={sat}
                            onReady={flyTo => { mapFlyTo.current = flyTo; }}
                            onViewportChange={setViewport}
                        />
                        <div className="absolute left-3 top-3 z-[1000] flex gap-1.5">
                            {!sidebarOpen && (
                                <button
                                    type="button"
                                    aria-label="Buka panel peta"
                                    onClick={() => setSidebarOpen(true)}
                                    className="flex h-8 w-8 items-center justify-center rounded-md border border-border bg-card shadow hover:bg-muted"
                                >
                                    <Menu className="h-4 w-4" aria-hidden />
                                </button>
                            )}
                        </div>
                        <div className="absolute bottom-4 left-3 z-[1000] flex gap-3 rounded-lg border border-border bg-card px-3 py-2 text-[10px]">
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-red-500" />ODP</span>
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-[#8b5cf6]" />ODC/POP</span>
                            <span className="flex items-center gap-1"><span className="flex h-3 w-3 items-center justify-center rounded-full border-2 border-white bg-emerald-600 shadow-sm" />Pelanggan</span>
                            <span className="flex items-center gap-1"><span className="h-2.5 w-2.5 rounded-full bg-green-500" />Pole</span>
                            <span className="flex items-center gap-1"><span className="h-4 w-0.5 rounded bg-blue-500" />Kabel</span>
                        </div>
                    </div>
                </div>
            </div>
        </EnterpriseIndexLayout>
    );
}
