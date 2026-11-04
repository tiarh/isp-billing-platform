import { Badge } from '@/Components/ui/badge';
import { formatDateTime } from '@/lib/format';
import { Link } from '@inertiajs/react';
import type { Customer } from './types';

type CustomerLike = Pick<Customer, 'id' | 'name' | 'code' | 'pppoe_user'> & Partial<Pick<Customer, 'onu_port' | 'rx_dbm' | 'rx_collected_at'>>;

/** One customer of an episode: name (link to the customer), codes, and the last Rx reading when known. */
export default function CustomerLine({ c, matched = false }: { c: CustomerLike; matched?: boolean }) {
    return (
        <li className="flex flex-wrap items-baseline gap-x-2" data-testid={matched ? 'matched-customer' : undefined}>
            <Link
                href={route('customers.show', c.id)}
                onClick={(e) => e.stopPropagation()}
                className="rounded-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
                {c.name}
            </Link>
            {matched && <Badge variant="secondary">Cocok</Badge>}
            <span className="font-mono text-xs text-muted-foreground">{[c.code, c.pppoe_user, c.onu_port].filter(Boolean).join(' · ')}</span>
            {c.rx_dbm != null && (
                <span className="font-mono text-xs text-muted-foreground" title={c.rx_collected_at ? `Diukur ${formatDateTime(c.rx_collected_at)}` : undefined}>
                    {c.rx_dbm.toFixed(1)} dBm
                </span>
            )}
        </li>
    );
}
