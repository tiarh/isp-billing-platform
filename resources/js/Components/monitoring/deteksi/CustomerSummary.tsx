import { Link } from '@inertiajs/react';
import CustomerLine from './CustomerLine';
import type { Episode } from './types';

const NAMES_IN_ROW = 3;

function NameLink({ id, name }: { id: number; name: string }) {
    return (
        <Link
            href={route('customers.show', id)}
            onClick={(e) => e.stopPropagation()}
            className="rounded-sm font-medium text-primary hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
            {name}
        </Link>
    );
}

/**
 * Customers of one row in one glance: one customer = name + PPPoE; a mass row = "14 pelanggan: A, B, C +11".
 * The searched customers (when a search is on) come first with a "Cocok" mark. The full list is in the detail panel.
 */
export default function CustomerSummary({ row }: { row: Episode }) {
    const matched = row.matched_customers ?? [];
    const matchedIds = new Set(matched.map((m) => m.id));
    const rest = row.customers.filter((c) => !matchedIds.has(c.id));
    const single = row.affected_count <= 1;
    const shown = rest.slice(0, single ? 1 : NAMES_IN_ROW);
    const others = Math.max(0, row.affected_count - matched.length - shown.length);

    return (
        <div className="space-y-1 text-sm" data-testid="deteksi-customers">
            {!single && <div className="font-mono text-xs font-semibold">{row.affected_count} pelanggan</div>}
            {matched.length > 0 && (
                <ul className="space-y-0.5">
                    {matched.map((c) => (
                        <CustomerLine key={c.id} c={c} matched />
                    ))}
                </ul>
            )}
            {shown.length > 0 && (
                <div className="flex flex-wrap items-baseline gap-x-1.5 gap-y-0.5">
                    {shown.map((c, i) => (
                        <span key={c.id} className="inline-flex items-baseline">
                            <NameLink id={c.id} name={c.name} />
                            {single && c.pppoe_user && <span className="ml-1.5 font-mono text-xs text-muted-foreground">{c.pppoe_user}</span>}
                            {!single && i < shown.length - 1 && <span className="text-muted-foreground">,</span>}
                        </span>
                    ))}
                    {!single && others > 0 && <span className="text-xs text-muted-foreground">+{others} lainnya</span>}
                </div>
            )}
            {shown.length === 0 && matched.length === 0 && single && <div className="text-xs text-muted-foreground">1 pelanggan</div>}
            {single && others > 0 && shown.length > 0 && <div className="text-xs text-muted-foreground">+{others} lainnya</div>}
        </div>
    );
}
