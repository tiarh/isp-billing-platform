import { canSeeNavAccess, type AuthUserLike } from '@/lib/navAccess';
import type { FaqAudience, FaqCategory, FaqItem } from './types';
import { tiket } from './categories/tiket';
import { pengambilanPerangkat } from './categories/pengambilanPerangkat';
import { pelanggan } from './categories/pelanggan';
import { invoice } from './categories/invoice';
import { psbWa } from './categories/psbWa';
import { migrasi } from './categories/migrasi';
import { jaringan } from './categories/jaringan';
import { genieacs } from './categories/genieacs';
import { whatsapp } from './categories/whatsapp';
import { keuangan } from './categories/keuangan';
import { absensi } from './categories/absensi';
import { monitoring } from './categories/monitoring';
import { sistem } from './categories/sistem';
import { akun } from './categories/akun';

export type { FaqAudience, FaqCategory, FaqItem } from './types';

/** Urutan di halaman FAQ: alur yang paling sering dipakai lebih dulu. */
export const faqCategories: FaqCategory[] = [
    tiket,
    pengambilanPerangkat,
    pelanggan,
    invoice,
    psbWa,
    migrasi,
    jaringan,
    genieacs,
    whatsapp,
    keuangan,
    absensi,
    monitoring,
    sistem,
    akun,
];

export function faqItemAnchor(category: FaqCategory, item: FaqItem): string {
    return `${category.slug}--${item.id}`;
}

export function canSeeFaqItem(user: AuthUserLike | null | undefined, category: FaqCategory, item: FaqItem): boolean {
    return canSeeNavAccess(user, category.access) && canSeeNavAccess(user, item.access ?? category.access);
}

/**
 * Kategori beserta pertanyaan yang boleh dilihat user pada tab tertentu.
 * Kategori yang tidak boleh dilihat user dihilangkan; kategori yang boleh dilihat
 * tetapi belum punya pertanyaan untuk tab itu tetap ditampilkan (kosong), supaya
 * user tahu fitur itu ada.
 */
export function faqCategoriesFor(user: AuthUserLike | null | undefined, audience: FaqAudience): FaqCategory[] {
    return faqCategories
        .filter((category) => canSeeNavAccess(user, category.access))
        .map((category) => ({
            ...category,
            items: category.items.filter(
                (item) => item.audience.includes(audience) && canSeeFaqItem(user, category, item),
            ),
        }));
}

/**
 * Kategori FAQ untuk nama route saat ini. `tickets.show` cocok dengan kategori yang
 * mencakup `tickets.index` karena awalan `tickets.` sama.
 */
export function resolveFaqCategoryForRoute(routeName: string | null | undefined): FaqCategory | null {
    if (!routeName) {
        return null;
    }

    const exact = faqCategories.find((category) => category.routes.includes(routeName));
    if (exact) {
        return exact;
    }

    const prefix = routeName.includes('.') ? routeName.slice(0, routeName.indexOf('.') + 1) : null;
    if (!prefix) {
        return null;
    }

    return (
        faqCategories.find((category) => category.routes.some((route) => route.startsWith(prefix))) ?? null
    );
}
