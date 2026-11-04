import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';

type ClientAreaLayoutProps = {
    children: ReactNode;
    companyName?: string;
    sandbox?: boolean;
    draft?: boolean;
};

export default function ClientAreaLayout({ children, companyName = 'Skynet Network', sandbox = false, draft = false }: ClientAreaLayoutProps) {
    return (
        <div className="flex min-h-screen flex-col bg-background">
            {draft && (
                <div role="status" className="border-b border-status-info bg-status-info-bg px-4 py-2 text-center text-sm font-semibold text-status-info">
                    DRAFT - menunggu persetujuan
                </div>
            )}
            {sandbox && (
                <div role="status" className="border-b border-status-degraded bg-status-degraded-bg px-4 py-2 text-center text-sm font-semibold text-status-degraded">
                    MODE SANDBOX - lingkungan uji coba, tidak ada uang sungguhan yang dipindahkan
                </div>
            )}
            <header className="border-b border-border bg-card">
                <div className="mx-auto flex h-16 max-w-3xl items-center gap-3 px-4">
                    <img
                        src="/images/skynet-mark-96.png"
                        alt=""
                        aria-hidden="true"
                        width={36}
                        height={36}
                        className="h-9 w-9 shrink-0 rounded-lg"
                    />
                    <div className="flex flex-col">
                        <span className="text-base font-bold leading-none text-foreground">{companyName}</span>
                        <span className="text-xs text-muted-foreground">Area Pelanggan</span>
                    </div>
                </div>
            </header>
            <main className="mx-auto w-full max-w-3xl flex-1 px-4 py-6">{children}</main>
            <footer className="border-t border-border py-4 text-center text-xs text-muted-foreground">
                <Link href="/syarat-ketentuan" className="underline">Syarat &amp; Ketentuan</Link>
                <span className="mx-2">|</span>
                <Link href="/kebijakan-refund" className="underline">Kebijakan Refund</Link>
            </footer>
        </div>
    );
}
