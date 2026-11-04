import { Link } from '@inertiajs/react';
import { ReactNode } from 'react';
import { Shield } from 'lucide-react';

type PublicLayoutProps = {
    children: ReactNode;
    companyName?: string;
};

export default function PublicLayout({ children, companyName = 'Skynet Network' }: PublicLayoutProps) {
    return (
        <div className="min-h-screen bg-background">
            {/* Header */}
            <nav className="border-b border-border bg-card sticky top-0 z-50">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="flex h-16 items-center justify-between">
                        <Link href="/" className="flex items-center space-x-3 group">
                            <img
                                src="/images/skynet-mark-96.png"
                                alt=""
                                aria-hidden="true"
                                width={36}
                                height={36}
                                className="block h-9 w-9 shrink-0 rounded-lg"
                            />
                            <div className="flex flex-col">
                                <span className="text-base font-bold text-foreground leading-none">
                                    {companyName}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    Portal Pembayaran Aman
                                </span>
                            </div>
                        </Link>

                        {/* Trust Badge */}
                        <div className="hidden sm:flex items-center gap-2 text-xs text-muted-foreground">
                            <Shield className="w-4 h-4 text-status-up" />
                            <span>Pembayaran Aman</span>
                        </div>
                    </div>
                </div>
            </nav>

            {/* Main Content */}
            <main className="py-8 sm:py-12">
                <div className="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                    {children}
                </div>
            </main>

            {/* Footer */}
            <footer className="border-t border-border bg-card/30 mt-auto">
                <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-6">
                    <div className="flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-muted-foreground">
                        <p>© {new Date().getFullYear()} PT. Skynet Lintas Nusantara. Hak cipta dilindungi.</p>
                        <div className="flex items-center gap-4">
                            <span className="flex items-center gap-1">
                                <Shield className="w-3 h-3" />
                                SSL Terenkripsi
                            </span>
                            <span>•</span>
                            <span>Privasi Terjaga</span>
                        </div>
                    </div>
                </div>
            </footer>
        </div>
    );
}
