import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/Components/ui/dialog';
import { Switch } from '@/Components/ui/switch';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';
import { Loader2, MessageCircle, Settings } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { formatGroupLabel } from '@/Components/WaGroups/types';

export type ConnectivityAlertSettings = {
    group_id: string;
    group_label?: string | null;
    enabled: boolean;
    dry_run: boolean;
    /** Server decision: superadmin / admin without area scope may open Pengaturan > Grup WhatsApp. */
    can_manage_wa_groups?: boolean;
};

function canManageAlerts(role?: string): boolean {
    return role === 'admin' || role === 'superadmin' || role === 'noc';
}

export function NocConnectivityAlertSettings({ initial }: { initial: ConnectivityAlertSettings }) {
    const userRole = usePage().props.auth?.user?.role as string | undefined;
    const manageable = canManageAlerts(userRole);

    const [open, setOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [settings, setSettings] = useState<ConnectivityAlertSettings>(initial);
    const [enabled, setEnabled] = useState(initial.enabled);
    const [dryRun, setDryRun] = useState(initial.dry_run);

    useEffect(() => {
        setSettings(initial);
        setEnabled(initial.enabled);
        setDryRun(initial.dry_run);
    }, [initial]);

    const groupText = settings.group_id ? formatGroupLabel(settings.group_label || null, settings.group_id) : 'Grup belum dipilih';

    // Only the switches are saved here; the group is chosen on Pengaturan > Grup WhatsApp.
    const handleSave = async () => {
        setSaving(true);
        try {
            const res = await axios.post(route('api.monitoring.connectivity.settings.save'), {
                enabled,
                dry_run: dryRun,
            });
            const next = res.data.settings as ConnectivityAlertSettings;
            setSettings(next);
            setOpen(false);
            toast.success('Pengaturan alert connectivity disimpan.');
        } catch {
            toast.error('Gagal menyimpan pengaturan alert connectivity.');
        } finally {
            setSaving(false);
        }
    };

    const statusBadge = () => {
        if (!settings.enabled) {
            return <Badge variant="secondary">Nonaktif</Badge>;
        }
        if (settings.dry_run) {
            return <Badge variant="outline" className="border-status-degraded/30 text-status-degraded">Dry-run</Badge>;
        }
        return <Badge className="bg-status-up hover:bg-status-up">WA Aktif</Badge>;
    };

    return (
        <Card>
            <CardHeader className="pb-2">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <CardTitle className="text-base flex items-center gap-2">
                            <MessageCircle className="h-4 w-4" />
                            Alert WA Connectivity
                        </CardTitle>
                        <CardDescription className="mt-1">
                            PON mati · PPPoE aktif — notifikasi ke grup NOC.
                        </CardDescription>
                    </div>
                    {manageable && (
                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button variant="outline" size="sm">
                                    <Settings className="mr-2 h-4 w-4" />
                                    Pengaturan
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Alert WA Monitoring</DialogTitle>
                                    <DialogDescription>
                                        Mulai dengan dry-run sebelum kirim WA nyata.
                                    </DialogDescription>
                                </DialogHeader>

                                <div className="space-y-4 py-2">
                                    <div className="space-y-1 rounded-md border p-3">
                                        <div className="text-sm font-medium">Grup WhatsApp</div>
                                        <div className="font-mono text-xs break-all">{groupText}</div>
                                        {settings.can_manage_wa_groups ? (
                                            <Link href={route('settings.wa-groups')} className="text-xs text-primary underline">
                                                Ubah di Pengaturan › Grup WhatsApp
                                            </Link>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">Minta admin mengubah di Pengaturan › Grup WhatsApp.</p>
                                        )}
                                    </div>

                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div>
                                            <div className="text-sm font-medium">Aktifkan alert</div>
                                            <div className="text-xs text-muted-foreground">Scan &amp; siapkan event connectivity</div>
                                        </div>
                                        <Switch checked={enabled} onCheckedChange={setEnabled} />
                                    </div>

                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div>
                                            <div className="text-sm font-medium">Dry-run</div>
                                            <div className="text-xs text-muted-foreground">Log saja, belum kirim WA (disarankan awal)</div>
                                        </div>
                                        <Switch checked={dryRun} onCheckedChange={setDryRun} />
                                    </div>
                                </div>

                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setOpen(false)}>Batal</Button>
                                    <Button onClick={handleSave} disabled={saving}>
                                        {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                                        Simpan
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-2 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    {statusBadge()}
                    <span className="text-muted-foreground">{settings.group_id ? `→ ${groupText}` : groupText}</span>
                </div>
                <p className="text-xs text-muted-foreground">
                    Mode default: digest per ODP saja (anti-spam) · cek 15 menit · ulang tiap 2 jam · max 5 ODP/run.
                    {manageable ? '' : ' Hanya admin/NOC yang bisa mengubah pengaturan.'}
                </p>
            </CardContent>
        </Card>
    );
}
