import { RefreshCw } from 'lucide-react';
import { Button } from '@/Components/ui/button';

interface PollStoppedNoticeProps {
    show: boolean;
    onRestart: () => void;
    message?: string;
}

/**
 * Shown when `usePollWhileVisible` hit its `maxMs` limit (audit R1): the
 * status may still be "running", but automatic refresh has stopped.
 */
export function PollStoppedNotice({
    show,
    onRestart,
    message = 'Pembaruan otomatis dihentikan setelah 5 menit. Status sinkronisasi mungkin sudah berubah.',
}: PollStoppedNoticeProps) {
    if (!show) return null;

    return (
        <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-status-degraded/35 bg-status-degraded/10 px-4 py-3 text-sm">
            <span>{message}</span>
            <Button type="button" variant="outline" size="sm" className="gap-2" onClick={onRestart}>
                <RefreshCw className="h-4 w-4" aria-hidden="true" />
                Pantau lagi
            </Button>
        </div>
    );
}
