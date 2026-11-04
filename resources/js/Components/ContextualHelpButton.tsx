import { Link } from '@inertiajs/react';
import { CircleHelp } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { resolveFaqCategoryForRoute } from '@/faq';

function currentRouteName(): string | null {
    try {
        return route().current() ?? null;
    } catch {
        return null;
    }
}

function faqHref(categorySlug: string | null): string | null {
    try {
        if (!route().has('faq.index')) {
            return null;
        }
        return categorySlug ? route('faq.index', { kategori: categorySlug }) : route('faq.index');
    } catch {
        return null;
    }
}

/**
 * Tombol "?" di header: membuka FAQ / Panduan langsung pada kategori halaman yang
 * sedang dibuka. Disembunyikan di halaman FAQ sendiri.
 */
export function ContextualHelpButton() {
    const current = currentRouteName();
    if (current === 'faq.index') {
        return null;
    }

    const category = resolveFaqCategoryForRoute(current);
    const href = faqHref(category?.slug ?? null);
    if (!href) {
        return null;
    }

    const label = category ? `Panduan ${category.title}` : 'FAQ / Panduan';

    return (
        <Button asChild variant="ghost" size="icon" className="h-8 w-8 rounded-md text-muted-foreground hover:text-foreground" data-faq-category={category?.slug ?? ''}>
            <Link href={href} aria-label={label} title={label}>
                <CircleHelp className="h-[18px] w-[18px]" />
            </Link>
        </Button>
    );
}
