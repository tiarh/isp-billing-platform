import { useEffect, useRef, useState } from 'react';
// R5: Leaflet comes from the bundle (`vendor-map` chunk), not from unpkg at
// runtime without integrity. CSS is bundled with this lazily loaded page.
import 'leaflet/dist/leaflet.css';
import { customerStatusTone } from '@/lib/customerLabels';

interface Props {
    maps: Record<string, { name: string; geojson: any; customerGeojson?: any }>;
    active: Record<string, boolean>;
    filterType: Record<string, boolean>;
    focused: string | null;
    dark: boolean;
    sat: boolean;
    onReady: (flyTo: (lat: number, lng: number) => void) => void;
    onViewportChange?: (viewport: { bbox: string; zoom: number }) => void;
}


function markerIconCfg(nm: string, pt: string, focused: string | null, online = false, status = '') {
    if (pt === 'CUSTOMER_CLUSTER') {
        const count = nm || '0';
        return { className: '', html: '<div style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#0f766e;color:#fff;border:3px solid #ccfbf1;font:700 11px system-ui;box-shadow:0 2px 5px rgba(0,0,0,.35)">' + count + '</div>', iconSize: [36, 36] as [number, number], iconAnchor: [18, 18] as [number, number] };
    }
    if (pt === 'CUSTOMER') {
        const color = customerStatusTone(status) === 'degraded'
            ? '#f59e0b'
            : online ? '#16a34a' : '#64748b';
        const initials = nm.trim().split(/\s+/).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase() || 'P';
        const html = '<div title="Pelanggan" style="width:26px;height:32px;position:relative;filter:drop-shadow(0 2px 2px rgba(15,23,42,.3))">' +
            '<div style="position:absolute;left:3px;top:1px;width:20px;height:20px;border-radius:50% 50% 50% 0;transform:rotate(-45deg);background:' + color + ';border:2px solid #fff;box-sizing:border-box">' +
            '<span style="position:absolute;inset:2px;display:flex;align-items:center;justify-content:center;border-radius:50%;transform:rotate(45deg);background:#fff;color:' + color + ';font:700 8px/1 system-ui,sans-serif">' + initials + '</span>' +
            '</div><div style="position:absolute;left:10px;top:25px;width:6px;height:6px;border-radius:50%;background:' + color + ';border:1px solid #fff"></div></div>';
        return { className: '', html, iconSize: [26, 32] as [number, number], iconAnchor: [13, 32] as [number, number], popupAnchor: [0, -28] as [number, number] };
    }
    const isOdp = pt === 'ODP' || nm.startsWith('ODP');
    const isOdc = pt === 'ODC' || nm.startsWith('ODC') || nm.startsWith('POP');
    const col = isOdp ? '#ef4444' : isOdc ? '#8b5cf6' : '#22c55e';
    const r = isOdc ? 10 : 8;
    const hl = focused === nm;
    const border = hl ? '#fbbf24' : col;
    const shadow = hl ? 'filter:drop-shadow(0 0 6px #fbbf24);' : '';
    const html = '<div style="position:relative;text-align:center">' +
        '<div style="width:0;height:0;margin:0 auto;border-left:' + r + 'px solid transparent;border-right:' + r + 'px solid transparent;border-bottom:' + (r * 1.5) + 'px solid ' + border + ';' + shadow + '"></div>' +
        '<div style="position:absolute;top:' + (r * 1.5 + 2) + 'px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:9px;font-weight:700;color:#fff;background:rgba(0,0,0,.6);padding:1px 5px;border-radius:3px">' + nm + '</div></div>';
    return { className: '', html, iconSize: [0, 0] as [number, number], iconAnchor: [0, r * 1.5] as [number, number] };
}

function popupTheme(dark: boolean) {
    return {
        muted: dark ? '#94a3b8' : '#6b7280',
        label: dark ? '#64748b' : '#9ca3af',
        title: dark ? '#f1f5f9' : '#0f172a',
        cardBg: dark ? '#1e293b' : '#f8fafc',
        barBg: dark ? '#334155' : '#e2e8f0',
        accent: '#3b82f6',
    };
}

function makePopup(f: any, areaName: string, dark: boolean): string {
    const t = popupTheme(dark);
    const nm = f.properties?.name || '';
    const desc = f.properties?.description || '';
    const coords = f.geometry?.coordinates;
    const pt = f.properties?.type || '';
    if (pt === 'CUSTOMER_CLUSTER') {
        const count = f.properties?.count || 0;
        return '<div style="min-width:150px;font-family:system-ui,sans-serif">' +
            '<div style="font-size:12px;font-weight:700;color:' + t.title + '">Cluster pelanggan</div>' +
            '<div style="font-size:11px;color:' + t.muted + ';margin-top:4px">Total: ' + count + '</div>' +
            '<div style="font-size:11px;color:#16a34a">Online: ' + (f.properties?.online_count || 0) + '</div>' +
            '<div style="font-size:11px;color:#dc2626">Offline: ' + (f.properties?.offline_count || 0) + '</div>' +
            '<div style="font-size:11px;color:#d97706">Tidak diketahui: ' + (f.properties?.unknown_count || 0) + '</div>' +
            '<div style="font-size:10px;color:' + t.label + ';margin-top:4px">Perbesar peta untuk melihat pelanggan.</div></div>';
    }
    if (f.geometry?.type === 'LineString') {
        const ck = f.properties?.cable_km;
        let h = '<div style="min-width:120px;font-family:system-ui"><div style="font-size:11px;font-weight:600;color:' + t.muted + '">Kabel Feeder</div>';
        if (nm) h += '<div style="font-size:12px;font-weight:700;color:' + t.title + '">' + nm + '</div>';
        if (ck) h += '<div style="font-size:11px;color:' + t.accent + '">' + ck + ' km</div>';
        h += '</div>';
        return h;
    }
    const lat = coords?.[1], lng = coords?.[0];
    if (!lat) return '';
    const gmUrl = 'https://www.google.com/maps?q=' + lat + ',' + lng;
    const badge = pt === 'ODC'
        ? '<span style="display:inline-block;background:#8b5cf6;color:#fff;font-size:9px;padding:1px 6px;border-radius:3px;font-weight:600">ODC/POP</span>'
        : pt === 'ODP'
        ? '<span style="display:inline-block;background:#ef4444;color:#fff;font-size:9px;padding:1px 6px;border-radius:3px;font-weight:600">ODP</span>'
        : pt === 'CUSTOMER'
        ? '<span style="display:inline-block;background:#0ea5e9;color:#fff;font-size:9px;padding:1px 6px;border-radius:3px;font-weight:600">Pelanggan</span>'
        : '<span style="display:inline-block;background:#3b82f6;color:#fff;font-size:9px;padding:1px 6px;border-radius:3px;font-weight:600">' + pt + '</span>';
    const oltP = f.properties?.olt_port;
    const tP = f.properties?.total_ports;
    const aP = f.properties?.active_ports ?? 0;
    const pct = tP > 0 ? (aP / tP * 100) : 0;
    const barCol = pct > 80 ? '#ef4444' : '#22c55e';
    let portInfo = '';
    let h = '';
    if (pt === 'ODC' && tP) {
        portInfo = '<div style="margin-top:6px;padding:6px;background:' + t.cardBg + ';border-radius:6px">' +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + '"><span>OLT Port</span><span style="font-weight:600;color:' + t.title + '">' + (oltP || '-') + '</span></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + ';margin-top:2px"><span>Port Aktif / Total</span><span style="font-weight:600;color:' + t.title + '">' + aP + ' / ' + tP + '</span></div>' +
            '<div style="margin-top:4px;height:4px;background:' + t.barBg + ';border-radius:2px;overflow:hidden"><div style="height:100%;width:' + pct + '%;background:' + barCol + ';border-radius:2px"></div></div></div>';
    }
    if (pt === 'CUSTOMER') {
        const online = f.properties?.online;
        const connStatus = String(f.properties?.connectivity_status || (online ? 'online' : 'unknown'));
        const connLabel = String(f.properties?.connectivity_label || (online ? 'Online' : 'Tidak diketahui'));
        const connReason = String(f.properties?.connectivity_reason || '');
        const connColor = connStatus === 'online' ? '#16a34a' : connStatus === 'offline' ? '#dc2626' : '#d97706';
        const st = f.properties?.status || '-';
        const rx = f.properties?.onu_rx_power_dbm;
        const odp = f.properties?.odp || '-';
        const rw = f.properties?.rw || '-';
        const last = f.properties?.last_snapshot_at || '-';
        h = '<div style="min-width:220px;font-family:system-ui,sans-serif">' +
            '<div style="font-size:10px;font-weight:600;color:' + t.label + ';margin-bottom:3px">' + areaName + '</div>' +
            '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">' + badge + '<span style="font-size:13px;font-weight:700;color:' + t.title + '">' + nm + '</span></div>' +
            '<div style="margin-top:6px;padding:6px;background:' + t.cardBg + ';border-radius:6px">' +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + '"><span>Koneksi</span><span style="font-weight:700;color:' + connColor + '">' + connLabel + '</span></div>' +
            (connReason ? '<div style="font-size:9px;color:' + t.label + ';margin-top:2px">' + connReason + '</div>' : '') +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + ';margin-top:2px"><span>Status</span><span style="font-weight:600;color:' + t.title + '">' + st + '</span></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + ';margin-top:2px"><span>ODP / RW</span><span style="font-weight:600;color:' + t.title + '">' + odp + ' / ' + rw + '</span></div>' +
            '<div style="display:flex;justify-content:space-between;font-size:10px;color:' + t.muted + ';margin-top:2px"><span>RX terakhir</span><span style="font-weight:600;color:' + t.title + '">' + (rx ?? '-') + ' dBm</span></div>' +
            '<div style="font-size:10px;color:' + t.label + ';margin-top:4px">Snapshot ' + last + '</div>' +
            '</div>' +
            '<div style="font-size:10px;color:' + t.label + ';margin-top:4px">' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</div>' +
            '<a href="' + gmUrl + '" target="_blank" style="display:inline-block;background:#2563eb;color:#fff;font-size:11px;font-weight:600;padding:5px 12px;border-radius:6px;text-decoration:none;margin-top:6px">Google Maps</a></div>';
        return h;
    }
    h = '<div style="min-width:200px;font-family:system-ui,sans-serif">' +
        '<div style="font-size:10px;font-weight:600;color:' + t.label + ';margin-bottom:3px">' + areaName + '</div>' +
        '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px">' + badge + '<span style="font-size:13px;font-weight:700;color:' + t.title + '">' + nm + '</span></div>';
    if (desc) h += '<div style="font-size:11px;color:' + t.muted + ';margin-bottom:4px">' + desc + '</div>';
    h += portInfo;
    h += '<div style="font-size:10px;color:' + t.label + ';margin-top:4px">' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</div>';
    h += '<a href="' + gmUrl + '" target="_blank" style="display:inline-block;background:#2563eb;color:#fff;font-size:11px;font-weight:600;padding:5px 12px;border-radius:6px;text-decoration:none;margin-top:6px">Google Maps</a></div>';
    return h;
}

export function PetaMap({ maps, active, filterType, focused, dark, sat, onReady, onViewportChange }: Props) {
    const el = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const layers = useRef<any>({});
    const tileRef = useRef<any>({});
    const fittedRef = useRef(false);
    const [ready, setReady] = useState(false);

    // Load Leaflet (bundled; dynamic import keeps it in the vendor-map chunk).
    useEffect(() => {
        if ((window as any).L) { setReady(true); return; }
        let cancelled = false;
        void import('leaflet').then((mod) => {
            // The rest of this component reads the global, as with the old script tag.
            (window as any).L = (mod as any).default ?? mod;
            if (!cancelled) setReady(true);
        });
        return () => { cancelled = true; };
    }, []);

    // Init map
    useEffect(() => {
        if (!ready || !el.current || mapRef.current) return;
        const L = (window as any).L;
        const m = L.map(el.current, { center: [-7.957, 112.672], zoom: 14, zoomControl: false });
        L.control.zoom({ position: 'topright' }).addTo(m);
        const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: 'OpenStreetMap' });
        const satL = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19, attribution: 'Esri' });
        const darkL = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 19, subdomains: 'abcd', attribution: '&copy; OpenStreetMap contributors &copy; CARTO' });
        street.addTo(m);
        tileRef.current = { street, satL, darkL };
        mapRef.current = m;
        onReady((lat: number, lng: number) => m.flyTo([lat, lng], 18, { duration: 0.8 }));
        m.on('moveend', () => {
            const b = m.getBounds();
            onViewportChange?.({ bbox: [b.getWest(), b.getSouth(), b.getEast(), b.getNorth()].join(','), zoom: m.getZoom() });
        });
        return () => { m.remove(); mapRef.current = null; };
    }, [ready]);

    // Toggle tiles
    useEffect(() => {
        const m = mapRef.current; if (!m || !ready) return;
        const { street, satL, darkL } = tileRef.current;
        m.eachLayer((l: any) => { if (l._url) m.removeLayer(l); });
        if (sat) satL.addTo(m); else if (dark) darkL.addTo(m); else street.addTo(m);
    }, [dark, sat, ready]);

    // Render GeoJSON
    useEffect(() => {
        const m = mapRef.current; if (!m || !ready) return;
        const L = (window as any).L;
        Object.values(layers.current).forEach((l: any) => l?.remove?.());
        layers.current = {};
        const all: any[] = [];
        Object.entries(maps).forEach(([key, entry]) => {
            if (!active[key] || !entry.geojson) return;
            const areaName = entry.name;
            const customerFeatures = filterType.CUSTOMER ? (entry.customerGeojson?.features || []) : [];
            const geo = L.geoJSON({ ...entry.geojson, features: [...(entry.geojson.features || []), ...customerFeatures] }, {
                filter: (f: any) => {
                    const gt = f.geometry?.type;
                    const pt = f.properties?.type || '';
                    if (gt === 'LineString') return filterType.CABLE;
                    if (pt === 'CUSTOMER' || pt === 'CUSTOMER_CLUSTER') return filterType.CUSTOMER;
                    if (pt === 'ODP') return filterType.ODP;
                    if (pt === 'ODC') return filterType.ODC;
                    if (pt === 'POLE') return filterType.POLE;
                    return filterType.OTHER;
                },
                style: (f: any) => f.geometry?.type === 'LineString' ? { color: '#3b82f6', weight: 2.5, opacity: 0.7 } : {},
                pointToLayer: (f: any, ll: any) => {
                    const nm = f.properties?.name || '';
                    const pt = f.properties?.type || '';
                    if (pt === 'ODP' || pt === 'ODC' || pt === 'CUSTOMER' || pt === 'CUSTOMER_CLUSTER' || nm.startsWith('ODP') || nm.startsWith('ODC') || nm.startsWith('POP')) {
                        const markerName = pt === 'CUSTOMER_CLUSTER' ? String(f.properties?.count || 0) : nm;
                        return L.marker(ll, { icon: L.divIcon(markerIconCfg(markerName, pt, focused, Boolean(f.properties?.online), String(f.properties?.status || ''))) });
                    }
                    const col = pt === 'POLE' ? '#22c55e' : '#3b82f6';
                    return L.circleMarker(ll, { radius: 4, fillColor: col, color: '#fff', weight: 1, fillOpacity: 0.8 });
                },
                onEachFeature: (f: any, layer: any) => {
                    const html = makePopup(f, areaName, dark);
                    if (html) layer.bindPopup(html, { className: dark ? 'peta-popup-dark' : 'peta-popup-light' });
                }
            });
            geo.addTo(m); layers.current[key] = geo;
            if (geo.getLayers().length) all.push(geo);
        });
        if (all.length && !fittedRef.current) {
            fittedRef.current = true;
            setTimeout(() => { try { m.fitBounds(L.featureGroup(all).getBounds().pad(0.1)); } catch {} }, 500);
        }
    }, [active, maps, focused, filterType, dark, ready]);

    return <div ref={el} className="h-full w-full" />;
}
