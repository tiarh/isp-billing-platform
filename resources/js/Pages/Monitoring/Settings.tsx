import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { router, useForm } from '@inertiajs/react';
import { Plug, RefreshCw, Save, Settings2, Sparkles } from 'lucide-react';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { FormField } from '@/Components/FormField';
import { StatusBadge } from '@/Components/StatusBadge';

type Connection = {
    url: string;
    datasource_uid: string;
    token_set: boolean;
    configured: boolean;
    breaker_open: boolean;
};

type UpstreamLink = {
    upstream_key: string;
    display_name: string;
    kind: 'transit' | 'ix' | 'aggregate';
    router_label: string;
    interface_name: string;
    capacity_mbps: number | null;
    sort: number;
    is_active: boolean;
};

type RouterRow = {
    id: number;
    name: string;
    is_active: boolean;
    grafana_identity: string | null;
};

type Props = {
    connection: Connection;
    upstreamLinks: UpstreamLink[];
    routers: RouterRow[];
};

type LabelSource = 'routerboard_name' | 'router' | 'upstream' | 'edge_interface';
type LabelOptions = Partial<Record<LabelSource, string[]>>;

const NONE = '__none__';

function firstError(errors: Record<string, string>): string {
    return Object.values(errors)[0] ?? 'Gagal menyimpan';
}

/** Dropdown filled from Grafana; falls back to free text when the list is empty. */
function LabelPicker({
    id,
    value,
    options,
    onChange,
    placeholder,
    ariaLabel,
}: {
    id: string;
    ariaLabel: string;
    value: string;
    options: string[];
    onChange: (value: string) => void;
    placeholder?: string;
}) {
    if (options.length === 0) {
        return <Input id={id} aria-label={ariaLabel} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} />;
    }
    const list = value && !options.includes(value) ? [value, ...options] : options;
    return (
        <Select value={value === '' ? NONE : value} onValueChange={(next) => onChange(next === NONE ? '' : next)}>
            <SelectTrigger id={id} aria-label={ariaLabel}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>(kosong)</SelectItem>
                {list.map((option) => (
                    <SelectItem key={option} value={option}>{option}</SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function Settings({ connection, upstreamLinks, routers }: Props) {
    const [options, setOptions] = useState<LabelOptions>({});
    const [optionsState, setOptionsState] = useState<'idle' | 'loading' | 'done'>('idle');
    const [optionsNote, setOptionsNote] = useState<string | null>(null);
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState<{ ok: boolean; message: string } | null>(null);
    const [suggesting, setSuggesting] = useState(false);
    const [suggestionSource, setSuggestionSource] = useState<Record<number, string | null>>({});
    const [saving, setSaving] = useState<'links' | 'routers' | null>(null);

    const connectionForm = useForm({
        url: connection.url ?? '',
        datasource_uid: connection.datasource_uid ?? '',
        token: '',
    });

    const [links, setLinks] = useState<UpstreamLink[]>(upstreamLinks);
    const [identities, setIdentities] = useState<Record<number, string>>(
        Object.fromEntries(routers.map((r) => [r.id, r.grafana_identity ?? ''])),
    );

    function submitConnection(event: React.FormEvent) {
        event.preventDefault();
        connectionForm.put(route('monitoring.settings.connection.update'), {
            preserveScroll: true,
            onSuccess: () => connectionForm.setData('token', ''),
            onError: (errors) => toast.error(firstError(errors)),
        });
    }

    async function testConnection() {
        setTesting(true);
        setTestResult(null);
        try {
            const { data } = await axios.post(route('monitoring.settings.test'));
            setTestResult({ ok: Boolean(data.ok), message: String(data.message ?? '') });
        } catch {
            setTestResult({ ok: false, message: 'Tes koneksi gagal dijalankan.' });
        } finally {
            setTesting(false);
        }
    }

    async function loadOptions() {
        setOptionsState('loading');
        setOptionsNote(null);
        const sources: LabelSource[] = ['edge_interface', 'routerboard_name'];
        const next: LabelOptions = {};
        let failed = 0;
        for (const source of sources) {
            try {
                const { data } = await axios.get(route('monitoring.settings.label-values', source));
                next[source] = Array.isArray(data.values) ? data.values : [];
                if (data.status !== 'ok') failed += 1;
            } catch {
                next[source] = [];
                failed += 1;
            }
        }
        setOptions(next);
        setOptionsState('done');
        if (failed > 0) setOptionsNote('Sebagian opsi tidak dapat dimuat; isi manual bila perlu.');
    }

    async function suggest() {
        setSuggesting(true);
        try {
            const { data } = await axios.post(route('monitoring.settings.router-identities.suggest'));
            const nextIdentities = { ...identities };
            const nextSource: Record<number, string | null> = {};
            for (const row of data.rows as Array<{ router_id: number; suggested: string | null; source: string | null }>) {
                if (row.suggested) {
                    nextIdentities[row.router_id] = row.suggested;
                    nextSource[row.router_id] = row.source;
                }
            }
            setIdentities(nextIdentities);
            setSuggestionSource(nextSource);
        } catch (error) {
            const status = axios.isAxiosError(error) ? error.response?.status : undefined;
            toast.error(status === 429 ? 'Terlalu sering, coba lagi sebentar' : 'Saran otomatis gagal dimuat');
        } finally {
            setSuggesting(false);
        }
    }

    function updateLink(key: string, patch: Partial<UpstreamLink>) {
        setLinks((current) => current.map((link) => (link.upstream_key === key ? { ...link, ...patch } : link)));
    }

    function saveLinks() {
        setSaving('links');
        router.put(
            route('monitoring.settings.upstream-links.update'),
            {
                links: links.map((link) => ({
                    upstream_key: link.upstream_key,
                    display_name: link.display_name,
                    kind: link.kind,
                    interface_name: link.interface_name,
                    capacity_mbps: link.capacity_mbps,
                    sort: link.sort,
                    is_active: link.is_active,
                })),
            },
            { preserveScroll: true, onError: (errors) => toast.error(firstError(errors)), onFinish: () => setSaving(null) },
        );
    }

    function saveIdentities() {
        setSaving('routers');
        router.put(
            route('monitoring.settings.router-identities.update'),
            {
                routers: routers.map((r) => ({
                    router_id: r.id,
                    grafana_identity: (identities[r.id] ?? '').trim() === '' ? null : identities[r.id].trim(),
                })),
            },
            { preserveScroll: true, onError: (errors) => toast.error(firstError(errors)), onFinish: () => setSaving(null) },
        );
    }

    const edgeOptions = options.edge_interface ?? [];
    const routerboardOptions = options.routerboard_name ?? [];

    return (
        <EnterpriseIndexLayout
            title="Pengaturan Monitoring"
            pageTitle="Pengaturan Monitoring"
            subtitle="Koneksi Grafana, upstream Jakarta Edge, dan identitas router"
            icon={Settings2}
            breadcrumbs={[
                { label: 'Monitoring', href: route('monitoring.index') },
                { label: 'Pengaturan' },
            ]}
            actions={
                <Button type="button" variant="outline" size="sm" onClick={loadOptions} disabled={optionsState === 'loading'}>
                    <RefreshCw className={`mr-2 h-4 w-4 ${optionsState === 'loading' ? 'animate-spin' : ''}`} />
                    Muat opsi dari Grafana
                </Button>
            }
        >
            <div className="space-y-4">
                {optionsNote && <p className="text-sm text-muted-foreground">{optionsNote}</p>}

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Plug className="h-4 w-4" />
                                Koneksi Grafana
                            </CardTitle>
                            <div className="flex flex-wrap gap-1.5">
                                <StatusBadge label={connection.configured ? 'Terkonfigurasi' : 'Belum dikonfigurasi'} tone={connection.configured ? 'up' : 'degraded'} />
                                {connection.breaker_open && <StatusBadge label="Circuit breaker terbuka" tone="down" />}
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submitConnection} className="grid gap-4 md:grid-cols-3">
                            <FormField id="grafana-url" label="URL Grafana" error={connectionForm.errors.url} hint="Host harus ada di GRAFANA_ALLOWED_HOSTS.">
                                <Input
                                    id="grafana-url"
                                    value={connectionForm.data.url}
                                    onChange={(event) => connectionForm.setData('url', event.target.value)}
                                    placeholder="http://grafana.example:3001"
                                />
                            </FormField>
                            <FormField id="grafana-uid" label="Datasource UID" error={connectionForm.errors.datasource_uid}>
                                <Input
                                    id="grafana-uid"
                                    value={connectionForm.data.datasource_uid}
                                    onChange={(event) => connectionForm.setData('datasource_uid', event.target.value)}
                                    placeholder="prom-main"
                                />
                            </FormField>
                            <FormField id="grafana-token" label="Service-account token" error={connectionForm.errors.token} hint="Kosongkan untuk mempertahankan token yang tersimpan.">
                                <Input
                                    id="grafana-token"
                                    type="password"
                                    autoComplete="new-password"
                                    value={connectionForm.data.token}
                                    onChange={(event) => connectionForm.setData('token', event.target.value)}
                                    placeholder={connection.token_set ? '•••• tersimpan' : 'Tempel token'}
                                />
                            </FormField>
                            <div className="flex flex-wrap items-center gap-2 md:col-span-3">
                                <Button type="submit" disabled={connectionForm.processing}>
                                    <Save className="mr-2 h-4 w-4" />
                                    Simpan koneksi
                                </Button>
                                <Button type="button" variant="outline" onClick={testConnection} disabled={testing}>
                                    <Plug className="mr-2 h-4 w-4" />
                                    Tes koneksi
                                </Button>
                                {testResult && (
                                    <span className={`text-sm ${testResult.ok ? 'text-status-up' : 'text-status-down'}`} role="status">
                                        {testResult.message}
                                    </span>
                                )}
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Upstream Jakarta Edge</CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Interface hanya referensi; data upstream berasal dari recording rules Grafana berdasarkan key. Link tidak dihapus; nonaktifkan dengan Aktif.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full min-w-[860px] text-sm">
                                <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left">Key</th>
                                        <th className="px-3 py-2 text-left">Nama</th>
                                        <th className="px-3 py-2 text-left">Jenis</th>
                                        <th className="px-3 py-2 text-left">Interface</th>
                                        <th className="px-3 py-2 text-left">Kapasitas (Mbps)</th>
                                        <th className="px-3 py-2 text-left">Aktif</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {links.map((link) => (
                                        <tr key={link.upstream_key}>
                                            <td className="px-3 py-2 font-mono text-xs">{link.upstream_key}</td>
                                            <td className="min-w-48 px-3 py-2">
                                                <Input
                                                    aria-label={`Nama ${link.upstream_key}`}
                                                    value={link.display_name}
                                                    onChange={(event) => updateLink(link.upstream_key, { display_name: event.target.value })}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <Select value={link.kind} onValueChange={(kind: UpstreamLink['kind']) => updateLink(link.upstream_key, { kind })}>
                                                    <SelectTrigger aria-label={`Jenis ${link.upstream_key}`} className="w-32">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="transit">Transit</SelectItem>
                                                        <SelectItem value="ix">IX</SelectItem>
                                                        <SelectItem value="aggregate">Agregat</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </td>
                                            <td className="min-w-64 px-3 py-2">
                                                <LabelPicker
                                                    id={`iface-${link.upstream_key}`}
                                                    ariaLabel={`Interface ${link.upstream_key}`}
                                                    value={link.interface_name}
                                                    options={edgeOptions}
                                                    onChange={(interface_name) => updateLink(link.upstream_key, { interface_name })}
                                                    placeholder="Nama interface"
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <Input
                                                    aria-label={`Kapasitas ${link.upstream_key}`}
                                                    type="number"
                                                    min={1}
                                                    max={400000}
                                                    className="w-28"
                                                    value={link.capacity_mbps ?? ''}
                                                    onChange={(event) => updateLink(link.upstream_key, { capacity_mbps: event.target.value === '' ? null : Number(event.target.value) })}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Aktif ${link.upstream_key}`}
                                                    checked={link.is_active}
                                                    onChange={(event) => updateLink(link.upstream_key, { is_active: event.target.checked })}
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                    {links.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="px-3 py-6 text-center text-sm text-muted-foreground">Belum ada upstream link.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Button type="button" onClick={saveLinks} disabled={saving === 'links' || links.length === 0}>
                            <Save className="mr-2 h-4 w-4" />
                            Simpan upstream
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <CardTitle className="text-base">Identitas Router di Grafana</CardTitle>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    Nilai <code>routerboard_name</code> tiap router. Saran otomatis hanya mengisi form; simpan untuk mengonfirmasi.
                                </p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={suggest} disabled={suggesting}>
                                <Sparkles className="mr-2 h-4 w-4" />
                                Saran otomatis
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full min-w-[560px] text-sm">
                                <thead className="bg-muted/50 text-xs uppercase text-muted-foreground">
                                    <tr>
                                        <th className="px-3 py-2 text-left">Router</th>
                                        <th className="px-3 py-2 text-left">Status</th>
                                        <th className="px-3 py-2 text-left">routerboard_name</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {routers.map((r) => (
                                        <tr key={r.id}>
                                            <td className="px-3 py-2">
                                                <div className="font-medium">{r.name}</div>
                                                <div className="text-xs text-muted-foreground">ID {r.id}</div>
                                            </td>
                                            <td className="px-3 py-2">
                                                <StatusBadge label={r.is_active ? 'Aktif' : 'Nonaktif'} tone={r.is_active ? 'up' : 'neutral'} />
                                            </td>
                                            <td className="min-w-64 px-3 py-2">
                                                <LabelPicker
                                                    id={`identity-${r.id}`}
                                                    ariaLabel={`routerboard_name ${r.name}`}
                                                    value={identities[r.id] ?? ''}
                                                    options={routerboardOptions}
                                                    onChange={(value) => setIdentities((current) => ({ ...current, [r.id]: value }))}
                                                    placeholder="Belum dipetakan"
                                                />
                                                {suggestionSource[r.id] && (
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        Saran: {suggestionSource[r.id] === 'router_id_label' ? 'dari label router_id' : 'dari kecocokan nama'}
                                                    </p>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {routers.length === 0 && (
                                        <tr>
                                            <td colSpan={3} className="px-3 py-6 text-center text-sm text-muted-foreground">Belum ada router.</td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        <Button type="button" onClick={saveIdentities} disabled={saving === 'routers' || routers.length === 0}>
                            <Save className="mr-2 h-4 w-4" />
                            Simpan identitas
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </EnterpriseIndexLayout>
    );
}
