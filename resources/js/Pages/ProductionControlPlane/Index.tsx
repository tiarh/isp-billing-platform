import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { ShieldCheck } from 'lucide-react';

type Row = Record<string, any>;

interface Props {
    control_plane: {
        source_of_truth: Row;
        state_machines: Record<string, string[]>;
        wa_command_contracts: Row[];
        device_action_policy: Row;
        rbac_matrix: Row[];
        feature_flags: Row[];
        kill_switches: Row[];
        monitoring_severity: Record<string, string>;
        data_quality: Row;
        observability: Row;
        ml_guard: Row;
    };
}

const valueText = (value: any): string => {
    if (Array.isArray(value)) return value.join(', ');
    if (value && typeof value === 'object') return JSON.stringify(value);
    if (typeof value === 'boolean') return value ? 'ON' : 'OFF';
    return value ?? '-';
};

const FlagBadge = ({ value }: { value: boolean }) => (
    <Badge variant={value ? 'up' : 'neutral'}>
        {value ? 'ON' : 'OFF'}
    </Badge>
);

const PolicyList = ({ rows }: { rows: Row[] }) => (
    <div className="divide-y rounded-md border">
        {rows.map((row) => (
            <div key={row.key || row.action || row.command} className="grid gap-2 p-3 text-sm sm:grid-cols-[1fr_auto]">
                <div>
                    <p className="font-medium">{row.label || row.action || row.command}</p>
                    <p className="text-xs text-muted-foreground">{row.domain || row.role || row.ambiguity || row.version}</p>
                </div>
                {'value' in row ? <FlagBadge value={!!row.value} /> : <Badge variant="outline">{valueText(row.approval ?? row.version)}</Badge>}
            </div>
        ))}
    </div>
);

export default function Index({ control_plane }: Props) {
    const readOnly = control_plane.device_action_policy.default_mode === 'read_only';

    return (
        <EnterpriseIndexLayout
            title="Production Control Plane"
            subtitle="Source of truth, approval guard, kill switch, dan kontrak command sebelum HA/V2 automation."
            icon={ShieldCheck}
        >
            <div className="space-y-5">
                <div className="grid gap-3 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Device Mode</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Badge variant={readOnly ? 'secondary' : 'destructive'}>{control_plane.device_action_policy.default_mode}</Badge>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Failed Jobs</CardTitle>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold">{valueText(control_plane.observability.failed_jobs)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm">Generated</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">{valueText(control_plane.observability.generated_at)}</CardContent>
                    </Card>
                </div>

                <div className="grid gap-5 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Kill Switch</CardTitle>
                        </CardHeader>
                        <CardContent><PolicyList rows={control_plane.kill_switches} /></CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Feature Flag</CardTitle>
                        </CardHeader>
                        <CardContent><PolicyList rows={control_plane.feature_flags} /></CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>State Machine</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {Object.entries(control_plane.state_machines).map(([name, states]) => (
                                <div key={name}>
                                    <p className="mb-2 text-sm font-medium capitalize">{name.replaceAll('_', ' ')}</p>
                                    <div className="flex flex-wrap gap-2">
                                        {states.map((state) => <Badge key={state} variant="outline">{state}</Badge>)}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>WA Contract</CardTitle>
                        </CardHeader>
                        <CardContent><PolicyList rows={control_plane.wa_command_contracts} /></CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>RBAC Approval</CardTitle>
                        </CardHeader>
                        <CardContent><PolicyList rows={control_plane.rbac_matrix} /></CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Observability</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm sm:grid-cols-2">
                            {Object.entries(control_plane.observability).map(([key, value]) => (
                                <div key={key} className="rounded-md border p-3">
                                    <p className="text-xs text-muted-foreground">{key}</p>
                                    <p className="font-medium break-words">{valueText(value)}</p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Source of Truth</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {Object.entries(control_plane.source_of_truth).map(([key, value]) => (
                                <div key={key} className="rounded-md border p-3">
                                    <p className="text-xs text-muted-foreground">{key}</p>
                                    <p className="break-words font-medium">{valueText(value)}</p>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Data Quality & ML</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="rounded-md border p-3">
                                <p className="text-xs text-muted-foreground">Excel/KMZ</p>
                                <p>{valueText(control_plane.data_quality.excel_kmz_import)}</p>
                            </div>
                            <div className="rounded-md border p-3">
                                <p className="text-xs text-muted-foreground">ML Guard</p>
                                <p>{valueText(control_plane.ml_guard)}</p>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </EnterpriseIndexLayout>
    );
}
