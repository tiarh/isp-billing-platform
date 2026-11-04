import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Boxes,
    Activity,
    CircleHelp,
    ClipboardList,
    CreditCard,
    FileText,
    Globe2,
    Handshake,
    LayoutDashboard,
    LogOut,
    LucideIcon,
    MapPin,
    MessageCircle,
    MoreVertical,
    Package,
    RefreshCw,
    Router,
    Send,
    Server,
    SlidersHorizontal,
    Cable,
    Settings,
    ShieldCheck,
    TicketCheck,
    UserPlus,
    Users,
    Wallet,
    Warehouse,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { PageProps } from '@/types';
import { canSeeNavAccess, roleDisplayLabel, type NavAccess } from '@/lib/navAccess';

export interface NavItem {
    name: string;
    route: string;
    icon: LucideIcon;
    section: 'operasi' | 'radius' | 'billing' | 'network' | 'growth' | 'system';
    /** Preferred: mirrors backend middleware */
    access?: NavAccess;
    /** @deprecated use access */
    globalOnly?: boolean;
    /** @deprecated use access */
    superOnly?: boolean;
    /** Keep routes for direct access while unfinished modules stay out of navigation. */
    hiddenFromNavigation?: boolean;
}

/**
 * access levels must match routes/web.php middleware groups.
 * teknisi → only `auth` items (no admin / global_admin / superadmin).
 */
export const navItems: NavItem[] = [
    { name: 'Dashboard', route: 'dashboard', icon: LayoutDashboard, section: 'operasi', access: 'auth' },
    { name: 'Pelanggan', route: 'customers.index', icon: Users, section: 'operasi', access: 'auth' },
    { name: 'PSB WA', route: 'psb-drafts.dashboard', icon: UserPlus, section: 'operasi', access: 'global_admin' },
    { name: 'Saleskit', route: 'saleskit.index', icon: ClipboardList, section: 'operasi', access: 'auth' },
    { name: 'Migrasi', route: 'customer-migrations.index', icon: RefreshCw, section: 'operasi', access: 'admin' },
    { name: 'Absensi', route: 'attendances.index', icon: LayoutDashboard, section: 'operasi', access: 'auth' },
    { name: 'Invoice', route: 'invoices.index', icon: FileText, section: 'billing', access: 'auth' },
    { name: 'Payment', route: 'modules.payment-gateway', icon: CreditCard, section: 'billing', access: 'superadmin' },
    { name: 'Transaksi Online', route: 'payment-gateway.transactions', icon: CreditCard, section: 'billing', access: 'admin_only' },
    { name: 'Kelebihan Bayar', route: 'customer-credits.index', icon: Wallet, section: 'billing', access: 'global_admin_only' },
    { name: 'Keuangan', route: 'finance.per-area.index', icon: BarChart3, section: 'billing', access: 'admin' },
    { name: 'Paket', route: 'packages.index', icon: Package, section: 'billing', access: 'global_admin' },
    { name: 'WA Gateway', route: 'wa-gateway.pair', icon: MessageCircle, section: 'network', access: 'superadmin' },
    { name: 'Broadcast WA', route: 'broadcasts.index', icon: Send, section: 'network', access: 'auth' },
    { name: 'Ticketing', route: 'tickets.index', icon: TicketCheck, section: 'network', access: 'auth' },
    { name: 'Monitoring', route: 'monitoring.index', icon: Activity, section: 'network', access: 'global_admin' },
    { name: 'Mapping', route: 'peta.index', icon: MapPin, section: 'network', access: 'auth' },
    { name: 'Inventaris', route: 'inventaris.index', icon: Warehouse, section: 'network', access: 'auth' },
    { name: 'MikroTik', route: 'routers.index', icon: Server, section: 'network', access: 'global_admin' },
    { name: 'OLT', route: 'olts.index', icon: Boxes, section: 'network', access: 'global_admin' },
    { name: 'Area', route: 'areas.index', icon: MapPin, section: 'network', access: 'global_admin' },
    { name: 'Aktivasi', route: 'oltc300.index', icon: Cable, section: 'network', access: 'global_admin' },
    { name: 'Kemitraan', route: 'modules.reseller', icon: Handshake, section: 'growth', access: 'admin' },
    { name: 'GenieACS', route: 'modules.genieacs', icon: Globe2, section: 'growth', access: 'admin' },
    { name: 'FAQ / Panduan', route: 'faq.index', icon: CircleHelp, section: 'growth', access: 'auth' },
    { name: 'Settings', route: 'settings.index', icon: Settings, section: 'system', access: 'superadmin' },
    { name: 'Grup WhatsApp', route: 'settings.wa-groups', icon: MessageCircle, section: 'system', access: 'global_admin_only' },
    { name: 'Users', route: 'users.index', icon: ShieldCheck, section: 'system', access: 'superadmin' },
    { name: 'Control Plane', route: 'production-control-plane.index', icon: SlidersHorizontal, section: 'system', access: 'superadmin' },
];

export function resolveNavAccess(item: NavItem): NavAccess {
    if (item.access) {
        return item.access;
    }
    if (item.superOnly) {
        return 'superadmin';
    }
    if (item.globalOnly) {
        return 'global_admin';
    }
    return 'auth';
}

export function filterNavItemsForUser(user: { role?: string; scope?: string } | null | undefined, items: NavItem[] = navItems): NavItem[] {
    return items.filter((item) => !item.hiddenFromNavigation && canSeeNavAccess(user, resolveNavAccess(item)));
}

function routeExists(routeName: string): boolean {
    try {
        return route().has(routeName);
    } catch {
        return false;
    }
}

const sectionLabels: Record<NavItem['section'], string> = {
    operasi: 'Operasi',
    radius: 'Radius',
    billing: 'Billing',
    network: 'Network',
    growth: 'Layanan',
    system: 'System',
};

export interface NavGroup {
    section: string;
    label: string;
    items: NavItem[];
}

/** Visible nav items for the user, grouped by section (empty groups dropped). */
export function groupNavItems(user: { role?: string; scope?: string } | null | undefined): NavGroup[] {
    const visibleNavItems = filterNavItemsForUser(user).filter((item) => routeExists(item.route));

    return Object.entries(sectionLabels)
        .map(([section, label]) => ({
            section,
            label,
            items: visibleNavItems.filter((item) => item.section === section),
        }))
        .filter((group) => group.items.length > 0);
}

/** Active when the route matches, or (for *.index) any nested page under the same prefix. */
export function isNavItemActive(itemRoute: string): boolean {
    try {
        if (route().current(itemRoute)) {
            return true;
        }
        if (itemRoute.endsWith('.index')) {
            const prefix = itemRoute.replace(/\.index$/, '');
            return !!route().current(`${prefix}.*`);
        }
        return false;
    } catch {
        return false;
    }
}

export function BrandMark({ className = '' }: { className?: string }) {
    return (
        <img
            src="/images/skynet-mark-96.png"
            alt=""
            aria-hidden="true"
            width={24}
            height={24}
            className={`h-6 w-6 shrink-0 rounded-md ${className}`}
        />
    );
}

interface SidebarNavProps {
    /** Called after a link is activated (used to close the mobile drawer). */
    onNavigate?: () => void;
    /** Desktop density (h-8). Pass false for touch targets (min-h-11). */
    dense?: boolean;
}

export function SidebarNav({ onNavigate, dense = true }: SidebarNavProps) {
    const user = usePage<PageProps>().props.auth.user;
    const groups = groupNavItems(user);

    return (
        <nav aria-label="Menu utama" className="space-y-4">
            {groups.map((group) => (
                <div key={group.section}>
                    <p className="mb-1 px-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {group.label}
                    </p>
                    <div className="grid gap-0.5">
                        {group.items.map((item) => {
                            let href: string;
                            try {
                                href = route(item.route);
                            } catch {
                                return null;
                            }
                            const isActive = isNavItemActive(item.route);

                            return (
                                <Link
                                    key={`${group.section}-${item.route}-${item.name}`}
                                    href={href}
                                    onClick={onNavigate}
                                    aria-current={isActive ? 'page' : undefined}
                                    className={`flex items-center gap-2.5 rounded-md px-2 text-[13px] font-medium outline-none transition-colors focus-visible:ring-2 focus-visible:ring-ring ${
                                        dense ? 'h-8' : 'min-h-11'
                                    } ${
                                        isActive
                                            ? 'bg-primary-soft text-primary-soft-foreground'
                                            : 'text-foreground-2 hover:bg-accent'
                                    }`}
                                >
                                    <item.icon className="h-4 w-4 shrink-0" />
                                    <span className="truncate">{item.name}</span>
                                </Link>
                            );
                        })}
                    </div>
                </div>
            ))}
        </nav>
    );
}

export function SidebarUserCard() {
    const user = usePage<PageProps>().props.auth.user;
    const roleLabel = roleDisplayLabel(user);
    const initial = (user.name || 'U').trim().charAt(0).toUpperCase() || 'U';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button className="flex w-full items-center gap-2.5 rounded-md p-2 text-left outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring">
                    <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary-soft-foreground">
                        {initial}
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-[13px] font-medium text-foreground">{user.name}</p>
                        <p className="truncate text-xs text-muted-foreground">{roleLabel}</p>
                    </div>
                    <MoreVertical className="h-4 w-4 shrink-0 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="start" className="mb-2 w-56">
                <DropdownMenuLabel>My Account</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link href={route('logout')} method="post" as="button" className="flex w-full cursor-pointer items-center gap-2 text-destructive focus:text-destructive">
                        <LogOut className="h-4 w-4" />
                        <span>Log Out</span>
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

export function AppSidebar() {
    return (
        <aside className="z-30 hidden w-60 shrink-0 flex-col border-r border-border bg-sidebar text-foreground md:flex">
            <div className="flex h-14 shrink-0 items-center border-b border-border px-4">
                <Link href="/" className="flex items-center gap-2">
                    <BrandMark />
                    <span className="text-[15px] font-semibold tracking-tight">Skynet Admin</span>
                </Link>
            </div>
            <div className="scrollbar-thin flex-1 overflow-y-auto overflow-x-hidden px-3 py-4">
                <SidebarNav />
            </div>
            <div className="shrink-0 border-t border-border p-3">
                <SidebarUserCard />
            </div>
        </aside>
    );
}
