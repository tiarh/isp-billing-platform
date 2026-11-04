// Indonesian phone helpers for tel:/wa.me links (0812… → 62812…).
export function phoneDigits(phone?: string | null): string {
    let digits = String(phone ?? '').replace(/[^0-9]/g, '');
    if (digits.startsWith('0')) digits = `62${digits.slice(1)}`;

    return digits.length >= 8 ? digits : '';
}

export function waLink(phone?: string | null): string | null {
    const digits = phoneDigits(phone);

    return digits ? `https://wa.me/${digits}` : null;
}

export function telLink(phone?: string | null): string | null {
    const digits = phoneDigits(phone);

    return digits ? `tel:+${digits}` : null;
}
