import { ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

interface DetailSectionProps {
    title: string;
    description?: string;
    children: ReactNode;
}

export function DetailSection({ title, description, children }: DetailSectionProps) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && <CardDescription>{description}</CardDescription>}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}
