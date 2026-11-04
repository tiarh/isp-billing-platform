import { formatDateTime } from '@/lib/format';
import { RecoveryEvent } from './types';

export function RecoveryTimeline({ events }: { events: RecoveryEvent[] }) {
    return (
        <section aria-labelledby="recovery-timeline-title"><h3 id="recovery-timeline-title" className="text-sm font-semibold">Timeline chain of custody</h3><div className="mt-3 space-y-3">{events.length === 0 ? <p className="text-sm text-muted-foreground">Belum ada event.</p> : events.map((event) => <div key={event.id} className="border-l-2 border-border pl-3 text-sm"><p className="font-medium">{event.event_type}</p><p className="text-xs text-muted-foreground">{formatDateTime(event.created_at)} · {event.from_status || '—'} → {event.to_status || '—'}</p>{event.reason && <p className="mt-1 text-xs">{event.reason}</p>}</div>)}</div></section>
    );
}
