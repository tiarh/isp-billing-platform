import type { ReactNode } from 'react';
import { useSoftKeyboardOpen } from '@/hooks/useSoftKeyboardOpen';

interface BottomActionBarProps {
    children: ReactNode;
    label?: string;
}

/**
 * Phone-only sticky action bar. Render it LAST in the page. Children are buttons: use
 * `className="h-12 flex-1"` for secondary and `className="h-12 flex-[2]"` for primary.
 * Hidden on md+ and while the on-screen keyboard is open.
 */
export function BottomActionBar({ children, label = 'Aksi cepat' }: BottomActionBarProps) {
    const keyboardOpen = useSoftKeyboardOpen();

    return (
        <>
            <div aria-hidden className="h-[calc(4.5rem+env(safe-area-inset-bottom))] md:hidden" />
            {!keyboardOpen && (
                <div
                    role="region"
                    aria-label={label}
                    className="fixed inset-x-0 bottom-0 z-30 flex gap-3 border-t border-border bg-card px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] md:hidden"
                >
                    {children}
                </div>
            )}
        </>
    );
}
