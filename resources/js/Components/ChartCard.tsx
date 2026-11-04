import type { ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { cn } from '@/lib/utils';

interface ChartCardProps {
    title: string;
    description?: string;
    children: ReactNode;
    className?: string;
}

export function ChartCard({ title, description, children, className }: ChartCardProps) {
    return (
        <Card className={cn('border-border/80 shadow-sm', className)}>
            <CardHeader className="pb-3">
                <CardTitle>{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}
