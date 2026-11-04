import { ReactNode } from 'react';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

interface TextFieldProps {
    id: string;
    label: string;
    value: string | number;
    onChange: (value: string) => void;
    type?: string;
    placeholder?: string;
    required?: boolean;
    autoFocus?: boolean;
    step?: string;
    min?: string | number;
    max?: string | number;
    maxLength?: number;
    accept?: string;
    help?: ReactNode;
    error?: string;
}

export function TextField({
    id,
    label,
    value,
    onChange,
    type = 'text',
    placeholder,
    required = false,
    autoFocus = false,
    step,
    min,
    max,
    maxLength,
    accept,
    help,
    error,
}: TextFieldProps) {
    return (
        <div className="space-y-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type={type}
                step={step}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                required={required}
                autoFocus={autoFocus}
                min={min}
                max={max}
                maxLength={maxLength}
                accept={accept}
            />
            {help && <p className="text-sm text-muted-foreground">{help}</p>}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
