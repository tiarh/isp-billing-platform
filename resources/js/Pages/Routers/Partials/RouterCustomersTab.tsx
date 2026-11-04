import { useEffect, useRef } from 'react';
import { SWRConfig } from 'swr';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import RouterCustomersTable from './RouterCustomersTable';
import UnlinkedSecretsSection from './UnlinkedSecretsSection';

interface RouterCustomersTabProps {
    routerId: number;
    activeConnections: { name: string; address: string; uptime: string }[];
    /** Naik setiap kali "Lihat daftar" ditekan atau halaman dibuka dengan ?section=unlinked. */
    focusUnlinked: number;
}

const freshCache = () => new Map();

/** Tab Pelanggan: daftar pelanggan router + daftar secret belum terhubung. */
export default function RouterCustomersTab({ routerId, activeConnections, focusUnlinked }: RouterCustomersTabProps) {
    const unlinkedRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (focusUnlinked > 0) {
            unlinkedRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, [focusUnlinked]);

    // Cache SWR baru setiap tab dibuka: setelah Tambah pelanggan, daftar harus langsung terbaca ulang
    // (bukan data lama dari kunjungan sebelumnya).
    return (
        <SWRConfig value={{ provider: freshCache }}>
            <Card className="border-border bg-card">
                <CardHeader>
                    <CardTitle>Pelanggan</CardTitle>
                    <CardDescription>Pelanggan yang dikelola oleh router ini.</CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    <RouterCustomersTable routerId={routerId} activeConnections={activeConnections} />
                    <UnlinkedSecretsSection ref={unlinkedRef} routerId={routerId} />
                </CardContent>
            </Card>
        </SWRConfig>
    );
}

/** Query dari URL halaman Inertia (URL browser belum berganti saat halaman pertama dirender). */
function pageQuery(pageUrl: string): URLSearchParams {
    return new URLSearchParams(pageUrl.split('?')[1] ?? '');
}

/** Tab awal dari ?tab=… (dipakai setelah Tambah pelanggan dari daftar secret). */
export function initialRouterTab(pageUrl: string): string {
    const tab = pageQuery(pageUrl).get('tab');
    return tab && ['overview', 'customers', 'profiles', 'vpn'].includes(tab) ? tab : 'overview';
}

export function initialUnlinkedFocus(pageUrl: string): number {
    return pageQuery(pageUrl).get('section') === 'unlinked' ? 1 : 0;
}
