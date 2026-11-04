import { InputHTMLAttributes } from 'react';

export default function Checkbox({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-border bg-background text-primary shadow-sm focus:ring-ring ' +
                className
            }
        />
    );
}
