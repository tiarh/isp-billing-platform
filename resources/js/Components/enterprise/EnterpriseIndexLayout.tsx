import { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BreadcrumbItem } from '@/Components/Common/Breadcrumbs';
import { EnterprisePageShell } from '@/Components/enterprise/EnterprisePageShell';

type Tab = {
    id: string;
    label: string;
    badge?: string | number | null;
    group?: string;
};

type Props = {
    title: string;
    pageTitle?: string;
    subtitle?: string;
    icon: LucideIcon;
    backHref?: string;
    breadcrumbs?: BreadcrumbItem[];
    cachedAt?: string | null;
    cacheLabel?: string;
    freshMinutes?: number;
    actions?: ReactNode;
    statusStrip?: ReactNode;
    tabs?: Tab[];
    activeTab?: string;
    onTabChange?: (id: string) => void;
    children: ReactNode;
};

export function EnterpriseIndexLayout({
    title,
    pageTitle,
    subtitle,
    icon,
    backHref,
    breadcrumbs,
    cachedAt,
    cacheLabel,
    freshMinutes,
    actions,
    statusStrip,
    tabs,
    activeTab,
    onTabChange,
    children,
}: Props) {
    return (
        <AuthenticatedLayout breadcrumbs={breadcrumbs}>
            <Head title={pageTitle ?? title} />
            <div className="py-4 sm:py-6">
                <EnterprisePageShell
                    icon={icon}
                    title={title}
                    subtitle={subtitle}
                    backHref={backHref}
                    cachedAt={cachedAt}
                    cacheLabel={cacheLabel}
                    freshMinutes={freshMinutes}
                    actions={actions}
                    statusStrip={statusStrip}
                    tabs={tabs}
                    activeTab={activeTab}
                    onTabChange={onTabChange}
                >
                    {children}
                </EnterprisePageShell>
            </div>
        </AuthenticatedLayout>
    );
}
