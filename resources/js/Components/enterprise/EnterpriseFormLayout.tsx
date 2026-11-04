import { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { BreadcrumbItem } from '@/Components/Common/Breadcrumbs';
import { EnterprisePageShell } from '@/Components/enterprise/EnterprisePageShell';

type Props = {
    title: string;
    pageTitle?: string;
    subtitle?: string;
    icon: LucideIcon;
    backHref?: string;
    breadcrumbs?: BreadcrumbItem[];
    actions?: ReactNode;
    children: ReactNode;
    maxWidth?: 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '5xl' | 'full';
};

const widthClass: Record<NonNullable<Props['maxWidth']>, string> = {
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
    '3xl': 'max-w-3xl',
    '5xl': 'max-w-5xl',
    full: 'max-w-full',
};

export function EnterpriseFormLayout({
    title,
    pageTitle,
    subtitle,
    icon,
    backHref,
    breadcrumbs,
    actions,
    children,
    maxWidth = '3xl',
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
                    actions={actions}
                >
                    <div className={`mx-auto w-full ${widthClass[maxWidth]}`}>
                        {children}
                    </div>
                </EnterprisePageShell>
            </div>
        </AuthenticatedLayout>
    );
}