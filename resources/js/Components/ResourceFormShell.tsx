import { FormEvent, ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';

interface ResourceFormShellProps {
    title: string;
    description?: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    children: ReactNode;
    submitLabel: string;
    processingLabel?: string;
    processing?: boolean;
    cancelHref: string;
    maxWidthClassName?: string;
    beforeFields?: ReactNode;
}

export function ResourceFormShell({
    title,
    description,
    onSubmit,
    children,
    submitLabel,
    processingLabel = 'Saving...',
    processing = false,
    cancelHref,
    maxWidthClassName = 'max-w-2xl',
    beforeFields,
}: ResourceFormShellProps) {
    return (
        <div className={`py-8 ${maxWidthClassName} mx-auto`}>
            <Card>
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                    {description && <CardDescription>{description}</CardDescription>}
                </CardHeader>
                <CardContent>
                    {beforeFields}
                    <form onSubmit={onSubmit} className="space-y-6">
                        {children}
                        <div className="flex justify-end gap-3 pt-2">
                            <Button asChild type="button" variant="outline">
                                <Link href={cancelHref}>Cancel</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? processingLabel : submitLabel}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    );
}
