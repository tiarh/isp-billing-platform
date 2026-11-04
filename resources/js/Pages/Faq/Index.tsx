import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, CircleHelp, ExternalLink, Hash, ListOrdered, Search } from 'lucide-react';
import { navItems, type NavItem } from '@/Components/AppSidebar';
import { EnterpriseIndexLayout } from '@/Components/enterprise/EnterpriseIndexLayout';
import { EmptyState } from '@/Components/EmptyState';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { faqCategoriesFor, faqItemAnchor, type FaqAudience, type FaqCategory, type FaqItem } from '@/faq';
import { isAdminLikeUser } from '@/lib/navAccess';
import { PageProps } from '@/types';

type Props = PageProps<{
    kategori?: string | null;
}>;

type Section = NavItem['section'];

/** Kelompok daftar isi mengikuti kelompok menu di sidebar aplikasi. */
const sectionLabels: Record<Section, string> = {
    operasi: 'Operasi',
    radius: 'Radius',
    billing: 'Billing',
    network: 'Network',
    growth: 'Layanan',
    system: 'System',
};

const sectionOrder = Object.keys(sectionLabels) as Section[];

function sectionOf(category: FaqCategory): Section {
    for (const name of category.routes) {
        const item = navItems.find((nav) => nav.route === name);
        if (item) {
            return item.section;
        }
    }
    return 'growth';
}

function matchesQuery(item: FaqItem, query: string): boolean {
    if (!query) {
        return true;
    }
    const haystack = [
        item.question,
        ...item.answer,
        ...(item.steps ?? []),
        ...(item.notes ?? []),
    ]
        .join(' ')
        .toLowerCase();

    return query
        .toLowerCase()
        .split(/\s+/)
        .filter(Boolean)
        .every((word) => haystack.includes(word));
}

function safeRouteHref(name: string): string | null {
    try {
        return route().has(name) ? route(name) : null;
    } catch {
        return null;
    }
}

function formatUpdated(iso: string): string {
    const [year, month, day] = iso.split('-').map(Number);
    if (!year || !month || !day) {
        return iso;
    }
    return new Date(year, month - 1, day).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
}

/** Gulir ke elemen dan simpan anchor di URL tanpa kunjungan Inertia baru. */
function scrollToAnchor(anchor: string, behavior: ScrollBehavior = 'smooth'): void {
    const target = document.getElementById(anchor);
    if (!target) {
        return;
    }
    target.scrollIntoView({ block: 'start', behavior });
    if (window.location.hash !== `#${anchor}`) {
        window.history.replaceState(window.history.state, '', `${window.location.pathname}${window.location.search}#${anchor}`);
    }
}

function FaqEntry({ category, item, number, open }: { category: FaqCategory; item: FaqItem; number: number; open: boolean }) {
    const anchor = faqItemAnchor(category, item);

    return (
        <details
            id={anchor}
            open={open}
            className="group scroll-mt-4 rounded-lg border border-border/70 bg-background open:border-primary/40 open:shadow-sm"
        >
            <summary className="flex cursor-pointer list-none items-start gap-3 px-4 py-3 text-left text-sm font-medium [&::-webkit-details-marker]:hidden">
                <span className="mt-px flex h-5 min-w-5 shrink-0 items-center justify-center rounded bg-muted px-1 text-xs font-semibold tabular-nums text-muted-foreground group-open:bg-primary/10 group-open:text-primary">
                    {number}
                </span>
                <span className="min-w-0 flex-1 break-words">{item.question}</span>
                <ChevronDown className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <div className="min-w-0 space-y-4 break-words border-t border-border/60 px-4 py-4 text-sm leading-relaxed sm:pl-12">
                {item.answer.map((paragraph, index) => (
                    <p key={index} className={paragraph.includes('\n') ? 'whitespace-pre-line rounded-md bg-muted px-3 py-2 font-data text-xs' : undefined}>
                        {paragraph}
                    </p>
                ))}

                {item.steps && item.steps.length > 0 && (
                    <div>
                        <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Langkah</p>
                        <ol className="list-decimal space-y-1.5 pl-5 marker:font-semibold marker:text-primary">
                            {item.steps.map((step, index) => (
                                <li key={index}>{step}</li>
                            ))}
                        </ol>
                    </div>
                )}

                {item.notes && item.notes.length > 0 && (
                    <div className="rounded-md border border-status-degraded/30 bg-status-degraded-bg px-3 py-2.5 text-status-degraded">
                        <p className="text-xs font-semibold uppercase tracking-wide">Perlu diperhatikan</p>
                        <ul className="mt-1.5 list-disc space-y-1 pl-5">
                            {item.notes.map((note, index) => (
                                <li key={index}>{note}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {item.images && item.images.length > 0 && (
                    <div className="grid gap-3 sm:grid-cols-2">
                        {item.images.map((image) => (
                            <figure key={image.src} className="min-w-0 overflow-hidden rounded-lg border border-border/70 bg-muted/30">
                                <img src={image.src} alt={image.alt} loading="lazy" className="h-auto w-full max-w-full" />
                                {image.caption && (
                                    <figcaption className="px-3 py-2 text-xs text-muted-foreground">{image.caption}</figcaption>
                                )}
                            </figure>
                        ))}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2 pt-1">
                    <div className="flex flex-wrap gap-2">
                        {item.links?.map((link) => {
                            const href = safeRouteHref(link.route);
                            if (!href) {
                                return null;
                            }
                            return (
                                <Button key={link.route} asChild size="sm" variant="outline">
                                    <Link href={href}>
                                        <ExternalLink className="h-3.5 w-3.5" />
                                        {link.label}
                                    </Link>
                                </Button>
                            );
                        })}
                    </div>
                    <div className="flex items-center gap-3 text-xs text-muted-foreground">
                        <a
                            href={`#${anchor}`}
                            onClick={(event) => {
                                event.preventDefault();
                                scrollToAnchor(anchor);
                            }}
                            className="inline-flex items-center gap-1 hover:text-foreground"
                            title="Tautan langsung ke pertanyaan ini"
                        >
                            <Hash className="h-3 w-3" aria-hidden />
                            Tautan
                        </a>
                        <span>Diperbarui {formatUpdated(item.updated)}</span>
                    </div>
                </div>
            </div>
        </details>
    );
}

type TocGroup = { section: Section; label: string; categories: FaqCategory[] };

function TocList({ groups, activeSlug, onSelect }: { groups: TocGroup[]; activeSlug: string | null; onSelect: (slug: string) => void }) {
    return (
        <nav aria-label="Daftar isi" className="space-y-4">
            {groups.map((group) => (
                <div key={group.section} className="space-y-1">
                    <p className="px-2 text-xs font-medium uppercase tracking-wide text-muted-foreground">{group.label}</p>
                    <ul className="space-y-0.5">
                        {group.categories.map((category) => {
                            const active = activeSlug === category.slug;
                            return (
                                <li key={category.slug}>
                                    <a
                                        href={`#${category.slug}`}
                                        aria-current={active ? 'location' : undefined}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            onSelect(category.slug);
                                        }}
                                        className={`flex items-center gap-2 rounded-md border-l-2 px-2 py-1.5 text-sm transition-colors ${
                                            active
                                                ? 'border-primary bg-primary/10 font-medium text-primary'
                                                : 'border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                                        }`}
                                    >
                                        <category.icon className="h-4 w-4 shrink-0" aria-hidden />
                                        <span className="min-w-0 flex-1 truncate">{category.title}</span>
                                        <span className="shrink-0 text-xs tabular-nums opacity-70">{category.items.length}</span>
                                    </a>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </nav>
    );
}

export default function FaqIndex({ auth, kategori }: Props) {
    const user = auth.user;
    const canSwitchAudience = isAdminLikeUser(user);
    const [audience, setAudience] = useState<FaqAudience>(canSwitchAudience ? 'admin' : 'teknisi');
    const [query, setQuery] = useState('');
    const [activeSlug, setActiveSlug] = useState<string | null>(kategori ?? null);
    const [openAnchor, setOpenAnchor] = useState<string | null>(null);
    const [missingDismissed, setMissingDismissed] = useState(false);
    const sectionRefs = useRef(new Map<string, HTMLElement>());

    const { url } = usePage();

    const categories = useMemo(() => faqCategoriesFor(user, audience), [user, audience]);
    const trimmedQuery = query.trim();

    const visible = useMemo(
        () =>
            categories
                .map((category) => ({
                    ...category,
                    items: category.items.filter((item) => matchesQuery(item, trimmedQuery)),
                }))
                .filter((category) => !trimmedQuery || category.items.length > 0),
        [categories, trimmedQuery],
    );

    const groups = useMemo<TocGroup[]>(
        () =>
            sectionOrder
                .map((section) => ({
                    section,
                    label: sectionLabels[section],
                    categories: visible.filter((category) => sectionOf(category) === section),
                }))
                .filter((group) => group.categories.length > 0),
        [visible],
    );

    /** Urutan di halaman sama dengan urutan daftar isi. */
    const ordered = useMemo(() => groups.flatMap((group) => group.categories), [groups]);

    const matchCount = visible.reduce((sum, category) => sum + category.items.length, 0);
    const requestedMissing = !!kategori && !categories.some((category) => category.slug === kategori);

    const selectSection = useCallback((slug: string) => {
        setActiveSlug(slug);
        scrollToAnchor(slug);
    }, []);

    // Tautan dari tombol "?" (?kategori=slug) dan anchor #slug atau #slug--id.
    useEffect(() => {
        const hash = typeof window !== 'undefined' ? decodeURIComponent(window.location.hash.replace(/^#/, '')) : '';
        const target = hash || kategori || '';
        if (!target) {
            return;
        }
        if (hash.includes('--')) {
            setOpenAnchor(hash);
        }
        const slug = target.split('--')[0];
        if (slug) {
            setActiveSlug(slug);
        }
        const frame = window.requestAnimationFrame(() => {
            document.getElementById(target)?.scrollIntoView({ block: 'start', behavior: 'auto' });
        });
        return () => window.cancelAnimationFrame(frame);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [url]);

    // Sorot bagian yang sedang dibaca di daftar isi.
    useEffect(() => {
        if (typeof IntersectionObserver === 'undefined') {
            return;
        }
        const intersecting = new Set<string>();
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    const slug = (entry.target as HTMLElement).dataset.faqSection;
                    if (!slug) {
                        continue;
                    }
                    if (entry.isIntersecting) {
                        intersecting.add(slug);
                    } else {
                        intersecting.delete(slug);
                    }
                }
                const first = ordered.find((category) => intersecting.has(category.slug));
                if (first) {
                    setActiveSlug(first.slug);
                }
            },
            { rootMargin: '-15% 0px -70% 0px' },
        );
        sectionRefs.current.forEach((element) => observer.observe(element));
        return () => observer.disconnect();
    }, [ordered]);

    return (
        <EnterpriseIndexLayout
            title="FAQ / Panduan"
            subtitle="Runbook tiap menu: fungsi, langkah kerja, dan jawaban pertanyaan yang sering muncul, disesuaikan dengan peran Anda."
            icon={CircleHelp}
            breadcrumbs={[{ label: 'FAQ / Panduan' }]}
            actions={
                canSwitchAudience ? (
                    <Tabs value={audience} onValueChange={(value) => setAudience(value as FaqAudience)}>
                        <TabsList aria-label="Pilih panduan untuk peran">
                            <TabsTrigger value="admin">Admin</TabsTrigger>
                            <TabsTrigger value="teknisi">Teknisi</TabsTrigger>
                        </TabsList>
                    </Tabs>
                ) : undefined
            }
        >
            <div className="grid min-w-0 gap-6 lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[16rem_minmax(0,1fr)]">
                <aside className="hidden lg:block">
                    <div className="sticky top-0 max-h-[calc(100vh-8rem)] overflow-y-auto overflow-x-hidden rounded-xl border border-border/80 bg-card p-3 shadow-sm dark:border-border/60">
                        <p className="mb-3 flex items-center gap-2 px-2 text-sm font-semibold">
                            <ListOrdered className="h-4 w-4 text-primary" aria-hidden />
                            Daftar isi
                        </p>
                        {groups.length > 0 ? (
                            <TocList groups={groups} activeSlug={activeSlug} onSelect={selectSection} />
                        ) : (
                            <p className="px-2 text-sm text-muted-foreground">Tidak ada bagian yang cocok.</p>
                        )}
                    </div>
                </aside>

                <div className="min-w-0 space-y-5">
                    <div className="space-y-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => setQuery(event.target.value)}
                                placeholder="Cari pertanyaan, contoh: foto SN, isolir, void invoice"
                                aria-label="Cari pertanyaan"
                                className="h-11 pl-9 text-base"
                            />
                        </div>

                        {groups.length > 0 && (
                            <div className="lg:hidden">
                                <label htmlFor="faq-toc" className="mb-1 block text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                    Daftar isi
                                </label>
                                <select
                                    id="faq-toc"
                                    value={activeSlug && ordered.some((category) => category.slug === activeSlug) ? activeSlug : ''}
                                    onChange={(event) => {
                                        if (event.target.value) {
                                            selectSection(event.target.value);
                                        }
                                    }}
                                    className="h-11 w-full min-w-0 rounded-md border border-input bg-background px-3 text-base text-foreground shadow-sm focus:outline-none focus:ring-1 focus:ring-ring"
                                >
                                    <option value="">Lompat ke menu...</option>
                                    {groups.map((group) => (
                                        <optgroup key={group.section} label={group.label}>
                                            {group.categories.map((category) => (
                                                <option key={category.slug} value={category.slug}>
                                                    {category.title} ({category.items.length})
                                                </option>
                                            ))}
                                        </optgroup>
                                    ))}
                                </select>
                            </div>
                        )}

                        {trimmedQuery && (
                            <p className="text-sm text-muted-foreground" aria-live="polite">
                                {matchCount === 0
                                    ? `Tidak ada jawaban yang cocok dengan "${trimmedQuery}".`
                                    : `${matchCount} jawaban cocok dengan "${trimmedQuery}".`}
                            </p>
                        )}
                    </div>

                    {requestedMissing && !missingDismissed && (
                        <EmptyState
                            icon={CircleHelp}
                            title="Kategori tidak tersedia untuk peran Anda"
                            description="Tautan yang Anda buka menuju panduan fitur yang tidak ada di menu akun Anda."
                            action={
                                <Button type="button" variant="outline" onClick={() => setMissingDismissed(true)}>
                                    Lihat semua kategori
                                </Button>
                            }
                        />
                    )}

                    {visible.length === 0 && (
                        <EmptyState
                            icon={Search}
                            title="Tidak ada jawaban yang cocok"
                            description="Coba kata yang lebih umum, misalnya nama menu seperti Ticketing atau Invoice."
                            action={
                                <Button type="button" variant="outline" onClick={() => setQuery('')}>
                                    Hapus pencarian
                                </Button>
                            }
                        />
                    )}

                    {ordered.map((category) => (
                        <Card
                            key={category.slug}
                            id={category.slug}
                            data-faq-section={category.slug}
                            ref={(element: HTMLDivElement | null) => {
                                if (element) {
                                    sectionRefs.current.set(category.slug, element);
                                } else {
                                    sectionRefs.current.delete(category.slug);
                                }
                            }}
                            className="min-w-0 scroll-mt-4"
                        >
                            <CardHeader className="border-b border-border/60 pb-4">
                                <div className="flex items-start gap-3">
                                    <div className="shrink-0 rounded-lg bg-primary/10 p-2 text-primary">
                                        <category.icon className="h-5 w-5" aria-hidden />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            {sectionLabels[sectionOf(category)]}
                                        </p>
                                        <CardTitle className="mt-0.5 flex flex-wrap items-center gap-2 text-base">
                                            <span className="break-words">{category.title}</span>
                                            <Badge variant="secondary" className="font-normal">
                                                {category.items.length} pertanyaan
                                            </Badge>
                                        </CardTitle>
                                        <p className="mt-1.5 text-sm text-muted-foreground">
                                            <span className="font-medium text-foreground">Fungsi: </span>
                                            {category.description}
                                        </p>
                                    </div>
                                    <a
                                        href={`#${category.slug}`}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            selectSection(category.slug);
                                        }}
                                        className="shrink-0 rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                                        aria-label={`Tautan langsung ke ${category.title}`}
                                        title="Tautan langsung ke bagian ini"
                                    >
                                        <Hash className="h-4 w-4" aria-hidden />
                                    </a>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-2 pt-4">
                                {category.items.length === 0 ? (
                                    <p className="rounded-lg border border-dashed border-border px-4 py-5 text-center text-sm text-muted-foreground">
                                        Panduan untuk bagian ini sedang disusun.
                                    </p>
                                ) : (
                                    category.items.map((item, index) => (
                                        <FaqEntry
                                            key={item.id}
                                            category={category}
                                            item={item}
                                            number={index + 1}
                                            open={openAnchor === faqItemAnchor(category, item)}
                                        />
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </EnterpriseIndexLayout>
    );
}
