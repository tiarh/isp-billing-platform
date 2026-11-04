import type { ReactNode } from 'react';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

export interface FormFieldProps {
    id: string;
    label: string;
    hint?: ReactNode;
    error?: string;
    required?: boolean;
    disabled?: boolean;
    children: ReactNode;
    className?: string;
}

export function FormField({ id, label, hint, error, required = false, disabled = false, children, className }: FormFieldProps) {
    return (
        <div className={cn('space-y-1.5', disabled && 'opacity-70', className)}>
            <Label htmlFor={id}>
                {label}
                {required && <span className="ml-1 text-status-down" aria-hidden>*</span>}
            </Label>
            {children}
            {hint && !error && <p id={`${id}-hint`} className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p id={`${id}-error`} className="text-sm text-status-down" role="alert">{error}</p>}
        </div>
    );
}
