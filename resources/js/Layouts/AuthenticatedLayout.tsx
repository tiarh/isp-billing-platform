import { PropsWithChildren, ReactNode, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { PageProps } from '@/types';
import {
    LogOut,
    Menu,
    X,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Button } from '@/Components/ui/button';
import { ThemeToggle } from '@/Components/ThemeToggle';
import { ContextualHelpButton } from '@/Components/ContextualHelpButton';
import { GlobalSearch } from '@/Components/search/GlobalSearch';
import { AppSidebar, BrandMark, SidebarNav } from '@/Components/AppSidebar';
import Breadcrumbs, { BreadcrumbItem } from '@/Components/Common/Breadcrumbs';
import { Toaster } from '@/Components/ui/sonner';
import { toast } from 'sonner';
import { roleDisplayLabel } from '@/lib/navAccess';

export default function Authenticated({
    header,
    children,
    breadcrumbs,
}: PropsWithChildren<{ header?: ReactNode; breadcrumbs?: BreadcrumbItem[] }>) {
    const user = usePage<PageProps>().props.auth.user;
    const { flash } = usePage<PageProps>().props;
    const [mobileNavOpen, setMobileNavOpen] = useState(false);

    const roleLabel = roleDisplayLabel(user);
    const initial = (user?.name || 'U').trim().charAt(0).toUpperCase() || 'U';

    useEffect(() => {
        if (flash?.success) toast.success(flash.success);
        if (flash?.warning) toast.warning(flash.warning);
        if (flash?.error) toast.error(flash.error);
    }, [flash]);

    // Drawer hanya untuk HP (md:hidden). Radix Dialog bersifat modal, jadi tutup saat viewport
    // melewati breakpoint md (mis. rotasi ke landscape); kalau tidak, aplikasi terkunci.
    useEffect(() => {
        const mq = window.matchMedia('(min-width: 768px)');
        const onChange = (event: MediaQueryListEvent) => {
            if (event.matches) setMobileNavOpen(false);
        };
        mq.addEventListener('change', onChange);
        return () => mq.removeEventListener('change', onChange);
    }, []);

    // Close drawer after Inertia navigation completes (safety if onClick missed)
    useEffect(() => {
        const close = () => setMobileNavOpen(false);
        document.addEventListener('inertia:navigate', close);
        return () => document.removeEventListener('inertia:navigate', close);
    }, []);

    return (
        <div className="flex h-[100dvh] max-h-[100dvh] overflow-hidden bg-background font-sans">
            <Toaster />

            {/* Desktop sidebar only */}
            <AppSidebar />

            {/* Mobile drawer */}
            {/* Mobile drawer: Radix Dialog menangani focus trap, Esc/overlay close, kunci scroll body, dan fokus kembali ke tombol menu. */}
            <DialogPrimitive.Root open={mobileNavOpen} onOpenChange={setMobileNavOpen}>
                <DialogPrimitive.Portal>
                    {/* Backdrop — tap to close */}
                    <DialogPrimitive.Overlay className="fixed inset-0 z-[100] bg-black/50 md:hidden" />

                    {/* Panel */}
                    <DialogPrimitive.Content
                        id="mobile-nav"
                        aria-describedby={undefined}
                        className="fixed inset-y-0 left-0 z-[101] flex h-full w-[min(100%,20rem)] max-w-[85vw] flex-col border-r border-border bg-sidebar shadow-2xl animate-in slide-in-from-left-4 duration-200 focus:outline-none md:hidden"
                    >
                        <DialogPrimitive.Title className="sr-only">Navigation menu</DialogPrimitive.Title>
                        <div className="flex h-14 shrink-0 items-center justify-between gap-2 border-b border-border px-4">
                            <Link
                                href="/dashboard"
                                className="flex min-w-0 items-center gap-2 font-semibold"
                                onClick={() => setMobileNavOpen(false)}
                            >
                                <BrandMark />
                                <span className="truncate text-[15px]">Skynet</span>
                            </Link>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="shrink-0"
                                onClick={() => setMobileNavOpen(false)}
                            >
                                <X className="h-5 w-5" />
                                <span className="sr-only">Tutup</span>
                            </Button>
                        </div>

                        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 py-4">
                            <SidebarNav dense={false} onNavigate={() => setMobileNavOpen(false)} />
                        </div>

                        {/* User + logout */}
                        <div className="safe-area-pb shrink-0 border-t border-border p-3">
                            <div className="mb-2 flex items-center gap-3 rounded-md border border-border px-3 py-2.5">
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary-soft-foreground">
                                    {initial}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{user?.name}</p>
                                    <p className="truncate text-xs text-muted-foreground">{roleLabel}</p>
                                </div>
                            </div>
                            <Link
                                href={route('logout')}
                                method="post"
                                as="button"
                                onClick={() => setMobileNavOpen(false)}
                                className="flex min-h-11 w-full items-center justify-center gap-2 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2.5 text-sm font-medium text-destructive active:bg-destructive/10"
                            >
                                <LogOut className="h-4 w-4" />
                                Log Out
                            </Link>
                        </div>
                    </DialogPrimitive.Content>
                </DialogPrimitive.Portal>
            </DialogPrimitive.Root>

            {/* Main */}
            <div className="relative z-10 flex w-full min-w-0 flex-1 flex-col overflow-hidden">
                <header className="z-20 flex h-14 shrink-0 items-center gap-2 border-b border-border bg-card px-4 sm:px-6">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="-ml-2 h-8 w-8 shrink-0 md:hidden"
                        onClick={() => setMobileNavOpen(true)}
                        aria-expanded={mobileNavOpen}
                        aria-controls="mobile-nav"
                    >
                        <Menu className="h-5 w-5" />
                        <span className="sr-only">Buka menu</span>
                    </Button>

                    <div className="flex min-w-0 flex-1 items-center gap-3">
                        <div className="hidden min-w-0 md:block">
                            <Breadcrumbs items={breadcrumbs} />
                        </div>
                        {/* Mobile: brand instead of breadcrumbs */}
                        <div className="flex min-w-0 items-center gap-2 md:hidden">
                            <BrandMark />
                            <span className="truncate text-sm font-semibold">Skynet</span>
                        </div>
                    </div>

                    <GlobalSearch />

                    <div className="flex shrink-0 items-center gap-1">
                        <ContextualHelpButton />
                        <ThemeToggle />

                        {/* Compact user on mobile header */}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8 rounded-full md:hidden"
                                    aria-label="Akun"
                                >
                                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary-soft text-xs font-semibold text-primary-soft-foreground">
                                        {initial}
                                    </div>
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-52">
                                <DropdownMenuLabel className="font-normal">
                                    <div className="flex flex-col space-y-0.5">
                                        <span className="text-sm font-medium">{user?.name}</span>
                                        <span className="text-xs text-muted-foreground">{roleLabel}</span>
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="flex w-full cursor-pointer items-center gap-2 text-destructive"
                                    >
                                        <LogOut className="h-4 w-4" />
                                        Log Out
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </header>

                <main className="min-h-0 flex-1 overflow-y-auto overflow-x-hidden p-3 sm:p-4 lg:p-6 scroll-smooth">
                    {header && (
                        <div className="mb-4 px-1 sm:mb-6 sm:px-2">
                            <h1 className="text-xl font-bold tracking-tight text-foreground sm:text-2xl">{header}</h1>
                        </div>
                    )}
                    <div className="mx-auto max-w-7xl animate-in fade-in slide-in-from-bottom-4 duration-500 pb-10">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
