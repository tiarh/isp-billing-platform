import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Boxes,
    FileText,
    LayoutDashboard,
    MapPin,
    Search,
    Server,
    TicketCheck,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { DialogTitle } from '@/Components/ui/dialog';
import {
    CommandDialog,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/Components/ui/command';
import { StatusBadge } from '@/Components/StatusBadge';
import { filterNavItemsForUser } from '@/Components/AppSidebar';
import { useGlobalSearch, GLOBAL_SEARCH_MIN_CHARS, type GlobalSearchItem } from '@/hooks/useGlobalSearch';
import type { PageProps } from '@/types';

const TYPE_ICONS: Record<GlobalSearchItem['type'], LucideIcon> = {
    customer: Users,
    invoice: FileText,
    ticket: TicketCheck,
    router: Server,
    olt: Boxes,
    area: MapPin,
};

/** Extra words that should find a menu page (matched like the page name). */
const MENU_ALIASES: Record<string, string> = {
    'invoices.index': 'tagihan faktur',
    'customers.index': 'pelanggan customer',
    'tickets.index': 'tiket gangguan ticket',
    'attendances.index': 'teknisi kehadiran',
    'routers.index': 'router mikrotik',
    'olts.index': 'olt onu',
    'faq.index': 'bantuan help panduan',
};

interface MenuEntry {
    name: string;
    href: string;
    haystack: string;
}

function routeHref(name: string): string | null {
    try {
        return route().has(name) ? route(name) : null;
    } catch {
        return null;
    }
}

function isEditable(target: EventTarget | null): boolean {
    const el = target as HTMLElement | null;
    if (!el || !el.tagName) {
        return false;
    }
    const tag = el.tagName.toLowerCase();
    return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
}

function isMac(): boolean {
    return typeof navigator !== 'undefined' && /mac|iphone|ipad/i.test(navigator.platform || '');
}

export function GlobalSearch() {
    const user = usePage<PageProps>().props.auth.user;
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [composing, setComposing] = useState(false);
    const [retryKey, setRetryKey] = useState(0);
    const newTabRef = useRef(false);
    const mac = useMemo(isMac, []);

    const { groups, loading, error, searchedFor } = useGlobalSearch(query, open && !composing, retryKey);

    // Ctrl/Cmd+K and "/" (not while typing in a field). Ignored during IME composition.
    useEffect(() => {
        const onKey = (e: globalThis.KeyboardEvent) => {
            if (e.isComposing || e.keyCode === 229) {
                return;
            }
            if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key?.toLowerCase() === 'k') {
                e.preventDefault();
                setOpen((v) => !v);
                return;
            }
            if (e.key === '/' && !e.ctrlKey && !e.metaKey && !e.altKey && !isEditable(e.target)) {
                e.preventDefault();
                setOpen(true);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Close after Inertia navigation (also covers links opened by Enter).
    useEffect(() => {
        const close = () => setOpen(false);
        document.addEventListener('inertia:navigate', close);
        return () => document.removeEventListener('inertia:navigate', close);
    }, []);

    const onOpenChange = (next: boolean) => {
        setOpen(next);
        if (!next) {
            setQuery('');
            setComposing(false);
        }
    };

    const menuEntries = useMemo<MenuEntry[]>(() => {
        const entries: MenuEntry[] = [];
        for (const item of filterNavItemsForUser(user)) {
            const href = routeHref(item.route);
            if (!href) {
                continue;
            }
            entries.push({
                name: item.name,
                href,
                haystack: `${item.name} ${MENU_ALIASES[item.route] ?? ''}`.toLowerCase(),
            });
        }
        return entries;
    }, [user]);

    const q = query.trim().toLowerCase();
    const menuMatches = useMemo(() => {
        if (q === '') {
            return menuEntries.slice(0, 6);
        }
        const terms = q.split(/\s+/);
        return menuEntries.filter((m) => terms.every((t) => m.haystack.includes(t))).slice(0, 5);
    }, [menuEntries, q]);

    const serverCount = groups.reduce((n, g) => n + g.items.length, 0);
    const hasQuery = q.length >= GLOBAL_SEARCH_MIN_CHARS;
    const total = hasQuery ? serverCount + menuMatches.length : 0;

    let liveText = '';
    if (hasQuery) {
        if (loading) {
            liveText = 'Mencari…';
        } else if (error) {
            liveText = 'Pencarian gagal';
        } else if (searchedFor !== '' && total === 0) {
            liveText = `Tidak ada hasil untuk ${query.trim()}`;
        } else if (searchedFor !== '') {
            liveText = `${total} hasil`;
        }
    }

    const go = (href: string) => {
        const newTab = newTabRef.current;
        newTabRef.current = false;
        if (newTab) {
            window.open(href, '_blank', 'noopener');
            return;
        }
        setOpen(false);
        router.visit(href);
    };

    const onInputKeyDown = (e: KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter') {
            newTabRef.current = e.ctrlKey || e.metaKey;
        }
    };

    return (
        <>
            {/* Desktop trigger: a button styled as a search field (not an input). */}
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="hidden h-9 w-64 shrink-0 items-center gap-2 rounded-[var(--radius-control)] border border-input bg-background px-3 text-sm text-muted-foreground outline-none transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring md:flex lg:w-80"
                aria-label="Buka pencarian"
                aria-haspopup="dialog"
            >
                <Search className="h-4 w-4 shrink-0" aria-hidden />
                <span className="min-w-0 flex-1 truncate text-left">Cari pelanggan, invoice, SN…</span>
                <kbd className="shrink-0 rounded border border-border bg-muted px-1.5 py-0.5 text-[10.5px] font-medium">
                    {mac ? '⌘K' : 'Ctrl K'}
                </kbd>
            </button>

            {/* Mobile trigger */}
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-10 w-10 shrink-0 md:hidden"
                onClick={() => setOpen(true)}
                aria-label="Cari"
                aria-haspopup="dialog"
            >
                <Search className="h-5 w-5" aria-hidden />
            </Button>

            <CommandDialog
                open={open}
                onOpenChange={onOpenChange}
                shouldFilter={false}
                contentClassName="top-0 h-[100dvh] max-w-none translate-x-[-50%] translate-y-0 rounded-none pb-[env(safe-area-inset-bottom)] data-[state=open]:slide-in-from-top-0 sm:top-[12vh] sm:h-auto sm:max-h-[75vh] sm:max-w-xl sm:rounded-lg"
            >
                <DialogTitle className="sr-only">Pencarian global</DialogTitle>
                <CommandInput
                    value={query}
                    onValueChange={setQuery}
                    onKeyDown={onInputKeyDown}
                    onCompositionStart={() => setComposing(true)}
                    onCompositionEnd={() => setComposing(false)}
                    placeholder="Cari pelanggan, invoice, SN ONU, tiket…"
                    className="h-12 pr-8 text-base sm:text-sm"
                    autoComplete="off"
                    inputMode="search"
                    enterKeyHint="search"
                />
                <div role="status" aria-live="polite" className="sr-only">
                    {liveText}
                </div>
                <CommandList className="max-h-none flex-1 overflow-y-auto sm:max-h-[60vh]">
                    {!hasQuery && (
                        <p className="px-4 py-2 text-xs text-muted-foreground">
                            Ketik nama, ID pelanggan, nomor HP, nomor invoice, SN ONU, atau kode tiket.
                        </p>
                    )}

                    {hasQuery && error === 'throttled' && (
                        <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                            Terlalu banyak pencarian, tunggu sebentar.
                        </p>
                    )}
                    {hasQuery && error === 'failed' && (
                        <div className="px-4 py-6 text-center text-sm">
                            <p className="text-muted-foreground">Pencarian gagal, coba lagi.</p>
                            <Button type="button" variant="outline" size="sm" className="mt-2" onClick={() => setRetryKey((k) => k + 1)}>
                                Coba lagi
                            </Button>
                        </div>
                    )}

                    {hasQuery && !error && loading && serverCount === 0 && (
                        <p className="px-4 py-6 text-center text-sm text-muted-foreground">Mencari…</p>
                    )}
                    {hasQuery && !error && !loading && searchedFor !== '' && total === 0 && (
                        <p className="px-4 py-6 text-center text-sm text-muted-foreground">
                            Tidak ada hasil untuk &ldquo;{query.trim()}&rdquo;
                        </p>
                    )}

                    {hasQuery && !error && groups.map((group) => (
                        <CommandGroup key={group.key} heading={group.label}>
                            {group.items.map((item) => {
                                const Icon = TYPE_ICONS[item.type] ?? Search;
                                return (
                                    <CommandItem
                                        key={item.id}
                                        value={item.id}
                                        onSelect={() => go(item.href)}
                                        className="gap-3"
                                    >
                                        <Icon className="shrink-0 text-muted-foreground" aria-hidden />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">{item.title}</span>
                                            {item.subtitle && (
                                                <span className="block truncate text-xs text-muted-foreground">{item.subtitle}</span>
                                            )}
                                        </span>
                                        {item.badge && <StatusBadge label={item.badge.label} tone={item.badge.tone} showIcon={false} />}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    ))}

                    {menuMatches.length > 0 && (q === '' || hasQuery) && (
                        <CommandGroup heading="Halaman">
                            {menuMatches.map((m) => (
                                <CommandItem key={`menu-${m.href}-${m.name}`} value={`menu-${m.href}-${m.name}`} onSelect={() => go(m.href)} className="gap-3">
                                    <LayoutDashboard className="shrink-0 text-muted-foreground" aria-hidden />
                                    <span className="truncate text-sm">{m.name}</span>
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                </CommandList>
            </CommandDialog>
        </>
    );
}

export default GlobalSearch;
