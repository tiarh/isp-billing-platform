import { useEffect, useId, useState, type ReactNode } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Button } from '@/Components/ui/button';
import Checkbox from '@/Components/Checkbox';
import { Input } from '@/Components/ui/input';

interface ConfirmDestructiveProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmationLabel?: string;
    /** false = tanpa kotak centang bawaan (pemanggil memasang centang sendiri lewat confirmDisabled). */
    requireConfirmation?: boolean;
    typedConfirmation?: string;
    typedConfirmationLabel?: string;
    typedConfirmationPlaceholder?: string;
    confirmText?: string;
    cancelText?: string;
    /**
     * Receives the exact text the operator typed (S4): device-write callers
     * forward it as `live_confirm` so the server can match the per-action
     * phrase instead of trusting a hardcoded client constant.
     */
    onConfirm: (typedValue: string) => void;
    isLoading?: boolean;
    /** Extra gate from the caller, e.g. a mandatory reason that is still too short. */
    confirmDisabled?: boolean;
    children?: ReactNode;
}

export function ConfirmDestructive({
    open,
    onOpenChange,
    title,
    description,
    confirmationLabel = 'Saya memahami tindakan ini tidak dapat dibatalkan.',
    requireConfirmation = true,
    typedConfirmation,
    typedConfirmationLabel = 'Ketik teks konfirmasi untuk melanjutkan.',
    typedConfirmationPlaceholder,
    confirmText = 'Lanjutkan',
    cancelText = 'Batal',
    onConfirm,
    isLoading = false,
    confirmDisabled = false,
    children,
}: ConfirmDestructiveProps) {
    const [confirmed, setConfirmed] = useState(false);
    const [typedValue, setTypedValue] = useState('');
    const typedConfirmationId = useId();

    useEffect(() => {
        if (!open) {
            setConfirmed(false);
            setTypedValue('');
        }
    }, [open]);

    const typedConfirmationValid = !typedConfirmation || typedValue === typedConfirmation;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {children}
                {typedConfirmation && (
                    <div className="space-y-1.5">
                        <label htmlFor={typedConfirmationId} className="text-sm font-medium">
                            {typedConfirmationLabel}
                        </label>
                        <Input
                            id={typedConfirmationId}
                            value={typedValue}
                            onChange={(event) => setTypedValue(event.target.value)}
                            placeholder={typedConfirmationPlaceholder || typedConfirmation}
                            autoComplete="off"
                            disabled={isLoading}
                        />
                        <p className="text-xs text-muted-foreground">
                            Konfirmasi harus sama persis: <span className="font-mono">{typedConfirmation}</span>
                        </p>
                    </div>
                )}
                {requireConfirmation && (
                    <label className="flex items-start gap-3 text-sm text-foreground">
                        <Checkbox checked={confirmed} onChange={(event) => setConfirmed(event.target.checked)} />
                        <span>{confirmationLabel}</span>
                    </label>
                )}
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isLoading}>
                        {cancelText}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={() => onConfirm(typedValue)}
                        disabled={(requireConfirmation && !confirmed) || !typedConfirmationValid || confirmDisabled || isLoading}
                    >
                        {isLoading ? 'Memproses...' : confirmText}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
