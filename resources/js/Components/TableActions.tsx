import { Button } from '@/Components/ui/button';
import { Pencil, Trash2 } from 'lucide-react';

interface ActionProps {
    onClick: () => void;
    title?: string;
    className?: string;
}

export function EditAction({ onClick, title = "Ubah", className = "" }: ActionProps) {
    return (
        <Button
            variant="ghost"
            size="icon"
            className={`h-8 w-8 text-primary hover:bg-primary-soft hover:text-primary-soft-foreground transition-colors duration-200 ${className}`}
            title={title}
            aria-label={title}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
        >
            <Pencil className="h-4 w-4" aria-hidden />
        </Button>
    );
}

export function DeleteAction({ onClick, title = "Hapus", className = "" }: ActionProps) {
    return (
        <Button
            variant="ghost"
            size="icon"
            className={`h-8 w-8 text-destructive hover:bg-status-down-bg hover:text-destructive transition-colors duration-200 ${className}`}
            title={title}
            aria-label={title}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
        >
            <Trash2 className="h-4 w-4" aria-hidden />
        </Button>
    );
}
