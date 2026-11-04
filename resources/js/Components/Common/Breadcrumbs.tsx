import { Link } from '@inertiajs/react';
import { ChevronRight, Home } from 'lucide-react';
import { usePage } from '@inertiajs/react';

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

interface Props {
    items?: BreadcrumbItem[];
}

const navClass = 'flex min-w-0 items-center text-[13px] text-muted-foreground';
const linkClass = 'block max-w-[24ch] truncate capitalize transition-colors hover:text-foreground';
// Item tengah tanpa href: gaya sama dengan tautan (muted), bukan seperti halaman aktif.
const plainClass = 'block max-w-[24ch] truncate capitalize';
const currentClass = 'block max-w-[24ch] truncate font-medium capitalize text-foreground';

function HomeLink() {
    return (
        <Link
            href="/dashboard"
            aria-label="Dashboard"
            title="Dashboard"
            className="flex shrink-0 items-center transition-colors hover:text-foreground"
        >
            <Home className="h-4 w-4" />
        </Link>
    );
}

function Separator() {
    return <ChevronRight aria-hidden="true" className="mx-1 h-3.5 w-3.5 shrink-0 text-muted-foreground/50" />;
}

export default function Breadcrumbs({ items }: Props) {
    const { url } = usePage();

    // If custom items are provided, use them
    if (items && items.length > 0) {
        return (
            <nav aria-label="Breadcrumb" className={navClass}>
                <HomeLink />

                {items.map((item, index) => {
                    const isLast = index === items.length - 1;

                    return (
                        <div key={index} className="flex min-w-0 items-center">
                            <Separator />
                            {item.href && !isLast ? (
                                <Link href={item.href} className={linkClass}>
                                    {item.label}
                                </Link>
                            ) : (
                                <span className={isLast ? currentClass : plainClass} aria-current={isLast ? 'page' : undefined}>
                                    {item.label}
                                </span>
                            )}
                        </div>
                    );
                })}
            </nav>
        );
    }

    // Default URL-based generation
    const pathSegments = url.split('?')[0].split('/').filter(Boolean);

    // Don't show on dashboard/home if empty or just dashboard
    if (pathSegments.length === 0 || (pathSegments.length === 1 && pathSegments[0] === 'dashboard')) {
        return (
            <nav aria-label="Breadcrumb" className={navClass}>
                <Home className="mr-2 h-4 w-4 shrink-0" aria-hidden="true" />
                <span className="font-medium text-foreground" aria-current="page">Dashboard</span>
            </nav>
        );
    }

    return (
        <nav aria-label="Breadcrumb" className={navClass}>
            <HomeLink />

            {pathSegments.map((segment, index) => {
                // Reconstruct path for this segment
                const path = `/${pathSegments.slice(0, index + 1).join('/')}`;
                const isLast = index === pathSegments.length - 1;

                // Format label: "customer-invoices" -> "Customer Invoices"
                const label = segment
                    .replace(/[-_]/g, ' ')
                    .replace(/^\w/, (c) => c.toUpperCase());

                // If segment is numeric, it's likely an ID.
                // In URL-mode, we can't know the name, so we just show the number or "Details"
                // Ideally, pages with IDs should stick to passing custom items.
                const displayLabel = /^\d+$/.test(label) ? `#${label}` : label;

                return (
                    <div key={path} className="flex min-w-0 items-center">
                        <Separator />
                        {isLast ? (
                            <span className={currentClass} aria-current="page">
                                {displayLabel}
                            </span>
                        ) : (
                            <Link href={path} className={linkClass}>
                                {displayLabel}
                            </Link>
                        )}
                    </div>
                );
            })}
        </nav>
    );
}
