import type { ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';

interface DrawerDetailProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    className?: string;
}

export function DrawerDetail({ open, onOpenChange, title, description, children, footer, className }: DrawerDetailProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className={cn('inset-y-0 right-0 left-auto top-0 flex h-full max-w-xl translate-x-0 translate-y-0 flex-col rounded-none border-y-0 border-r-0 border-l p-0 sm:max-w-xl', className)}>
                <DialogHeader className="border-b border-border px-6 py-5 pr-14">
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5">{children}</div>
                {footer && <DialogFooter className="border-t border-border px-6 py-4">{footer}</DialogFooter>}
            </DialogContent>
        </Dialog>
    );
}
