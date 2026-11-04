import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function AuthShell({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center justify-center bg-background p-4">
            <Link href="/" className="mb-6 flex flex-col items-center gap-2">
                <img
                    src="/images/skynet-mark-96.png"
                    alt=""
                    aria-hidden="true"
                    width={40}
                    height={40}
                    className="h-10 w-10 shrink-0 rounded-lg"
                />
                <span className="text-base font-semibold text-foreground">Skynet E-Billing</span>
            </Link>

            <div className="w-full max-w-sm rounded-lg border bg-card p-6 shadow-[var(--shadow-card)]">{children}</div>

            <p className="mt-6 text-xs text-muted-foreground">Skynet Network</p>
        </div>
    );
}
