import { Badge } from '@/Components/ui/badge';
import { Loader2, CheckCircle2, Clock, XCircle } from 'lucide-react';

export type SyncStatus = 'idle' | 'queued' | 'running' | 'syncing' | 'success' | 'failed';

interface RouterStatusBadgeProps {
    connectionStatus: 'unknown' | 'online' | 'offline';
    syncStatus?: SyncStatus;
    syncMessage?: string | null;
    cpuLoad?: number | null;
}

export function RouterStatusBadge({
    connectionStatus,
    syncStatus = 'idle',
    syncMessage,
    cpuLoad
}: RouterStatusBadgeProps) {

    // 1. Handle Syncing State
    if (syncStatus === 'queued') {
        return (
            <Badge variant="outline" className="h-6 px-3 text-xs whitespace-nowrap text-status-info border-status-info/30 bg-status-info-bg">
                <Clock className="mr-1.5 h-3 w-3" />
                Queued
            </Badge>
        );
    }

    if (syncStatus === 'running' || syncStatus === 'syncing') {
        return (
            <Badge variant="outline" className="h-6 px-3 text-xs whitespace-nowrap text-status-info border-status-info/30 bg-status-info-bg">
                <Loader2 className="mr-1.5 h-3 w-3 animate-spin" />
                Syncing...
            </Badge>
        );
    }

    // 2. Handle Success State
    if (syncStatus === 'success') {
        return (
            <Badge variant="outline" className="h-6 px-3 text-xs whitespace-nowrap text-status-up border-status-up/30 bg-status-up-bg">
                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                Success
            </Badge>
        );
    }

    // 3. Handle Failed State
    if (syncStatus === 'failed') {
        const reachable = connectionStatus === 'online';
        return (
            <div className="flex flex-col items-start gap-1">
                <Badge
                    variant="outline"
                    title={syncMessage || undefined}
                    className={`h-6 px-3 text-xs whitespace-nowrap ${reachable
                        ? 'text-status-degraded border-status-degraded/30 bg-status-degraded-bg'
                        : 'bg-status-down-bg text-status-down border-status-down/30'}`}
                >
                    <XCircle className="mr-1.5 h-3.5 w-3.5" />
                    {reachable ? 'Sync gagal' : 'Failed'}
                </Badge>
                {syncMessage && (
                    <span className="max-w-[180px] truncate text-[10px] text-muted-foreground" title={syncMessage}>
                        {syncMessage.replace(/^Sync failed:\s*/i, '')}
                    </span>
                )}
            </div>
        );
    }

    // 4. Default State (Based on Connection Status)
    const getConnectionBadge = () => {
        switch (connectionStatus) {
            case 'online':
                return (
                    <Badge
                        variant="outline"
                        className="h-6 px-3 text-xs whitespace-nowrap text-status-up border-status-up/30 bg-status-up-bg"
                    >
                        Online
                    </Badge>
                );
            case 'offline':
                return (
                    <Badge
                        variant="outline"
                        className="h-6 px-3 text-xs whitespace-nowrap text-status-down border-status-down/30 bg-status-down-bg"
                    >
                        Offline
                    </Badge>
                );
            default: // 'unknown'
                return (
                    <Badge
                        variant="secondary"
                        className="h-6 px-3 text-xs whitespace-nowrap text-muted-foreground"
                    >
                        Unknown
                    </Badge>
                );
        }
    };

    return (
        <div className="flex items-center gap-2">
            {getConnectionBadge()}

            {/* Show CPU Load only if Online */}
            {connectionStatus === 'online' && cpuLoad !== null && cpuLoad !== undefined && (
                <Badge variant="secondary" className="h-6 px-3 text-xs whitespace-nowrap bg-muted/50">
                    CPU: {cpuLoad}%
                </Badge>
            )}
        </div>
    );
}
