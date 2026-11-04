import { useEffect, useState } from 'react';
import { Check, ChevronDown } from 'lucide-react';
import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

export interface RootCauseOption {
    value: string;
    label: string;
    group?: 'main' | 'more';
    /** One plain line under the label ("ONU merah / tidak ada sinyal"). */
    hint?: string | null;
}

export interface RootCauseSuggestion {
    code: string;
    label: string;
    confidence: string | null;
}

/** "Saran dari data": what the stored data says fixed this ticket (RX jump, ONU swap, isolir opened). */
export interface RepairSignature {
    code: string;
    label: string;
    text: string;
    signature: string;
}

export type RootCauseVia = 'suggestion' | 'manual' | 'signature';

interface Props {
    id: string;
    options: RootCauseOption[];
    value: string;
    onChange: (value: string, via: RootCauseVia) => void;
    note: string;
    onNoteChange: (value: string) => void;
    error?: string;
    noteError?: string;
    suggestion?: RootCauseSuggestion | null;
    /** Chip "Saran dari data" (setting-driven; null = hidden). Never selected for the person. */
    signature?: RepairSignature | null;
    /** false = the cause may be left as it is (plain edit, no close). Default true. */
    required?: boolean;
}

export const ROOT_CAUSE_NOTE_MAX = 255;

/**
 * "Penyebab sebenarnya": big one-tap buttons (one column on a phone). The rare causes sit under "Penyebab lain".
 * "Lainnya" asks for a short note. A system suggestion is shown on top but never selected for the person.
 */
export default function RootCausePicker({ id, options, value, onChange, note, onNoteChange, error, noteError, suggestion = null, signature = null, required = true }: Props) {
    const main = options.filter((o) => (o.group ?? 'main') === 'main');
    const more = options.filter((o) => o.group === 'more');
    const selectedInMore = more.some((o) => o.value === value);
    const [moreOpen, setMoreOpen] = useState(selectedInMore);
    useEffect(() => {
        if (selectedInMore) setMoreOpen(true);
    }, [selectedInMore]);

    const button = (option: RootCauseOption) => {
        const selected = option.value === value;
        return (
            <button
                key={option.value}
                type="button"
                aria-pressed={selected}
                data-testid={`root-cause-option-${option.value}`}
                onClick={() => onChange(option.value, 'manual')}
                className={cn(
                    'flex min-h-11 w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm transition-colors',
                    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                    selected ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background hover:bg-accent',
                )}
            >
                <span className="flex flex-col">
                    <span>{option.label}</span>
                    {option.hint && (
                        <span data-testid={`root-cause-hint-${option.value}`} className={cn('text-xs font-normal', selected ? 'text-primary-foreground/80' : 'text-muted-foreground')}>
                            {option.hint}
                        </span>
                    )}
                </span>
                {selected && <Check className="h-4 w-4 shrink-0" aria-hidden="true" />}
            </button>
        );
    };

    return (
        <div className="space-y-3" data-testid="root-cause-field" id={id}>
            <Label>
                Penyebab sebenarnya {required && <span className="text-destructive">*</span>}
            </Label>

            {suggestion && (
                <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm" data-testid="root-cause-suggestion">
                    <span>
                        Saran sistem: {suggestion.label}
                        {suggestion.confidence ? ` (${suggestion.confidence})` : ''}. Pilih kalau sesuai.
                    </span>
                    <button
                        type="button"
                        data-testid="root-cause-use-suggestion"
                        onClick={() => onChange(suggestion.code, 'suggestion')}
                        className="min-h-11 rounded-full border border-input bg-background px-3 text-xs font-semibold hover:bg-accent"
                    >
                        Pakai saran
                    </button>
                </div>
            )}

            {signature && (
                <div className="flex flex-wrap items-center gap-2 rounded-md bg-muted px-3 py-2 text-sm" data-testid="root-cause-signature">
                    <span>
                        Saran dari data: {signature.label} — {signature.text}. Pilih kalau sesuai.
                    </span>
                    <button
                        type="button"
                        data-testid="root-cause-use-signature"
                        onClick={() => onChange(signature.code, 'signature')}
                        className="min-h-11 rounded-full border border-input bg-background px-3 text-xs font-semibold hover:bg-accent"
                    >
                        Pakai saran
                    </button>
                </div>
            )}

            <div className="grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">{main.map(button)}</div>

            {more.length > 0 && (
                <div className="space-y-2">
                    <button
                        type="button"
                        aria-expanded={moreOpen}
                        data-testid="root-cause-more"
                        onClick={() => setMoreOpen((open) => !open)}
                        className="flex min-h-11 w-full items-center justify-between rounded-md border border-dashed border-input px-3 py-2 text-left text-sm text-muted-foreground hover:bg-accent"
                    >
                        <span>Penyebab lain…</span>
                        <ChevronDown className={cn('h-4 w-4 transition-transform', moreOpen && 'rotate-180')} aria-hidden="true" />
                    </button>
                    {moreOpen && <div className="grid grid-cols-1 gap-2 min-[380px]:grid-cols-2">{more.map(button)}</div>}
                </div>
            )}

            {value === 'other' && (
                <div className="space-y-1">
                    <Label htmlFor={`${id}-note`}>Tulis singkat penyebabnya</Label>
                    <Textarea
                        id={`${id}-note`}
                        data-testid="root-cause-note"
                        value={note}
                        maxLength={ROOT_CAUSE_NOTE_MAX}
                        rows={3}
                        onChange={(e) => onNoteChange(e.target.value)}
                        placeholder="Contoh: kabel LAN dalam rumah putus"
                    />
                    <p className="text-right text-xs text-muted-foreground">
                        {note.length}/{ROOT_CAUSE_NOTE_MAX}
                    </p>
                    {noteError && (
                        <p className="text-xs text-destructive" role="alert" data-testid="root-cause-note-error">
                            {noteError}
                        </p>
                    )}
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                {required ? 'Wajib diisi. Tidak yakin? Buka Penyebab lain, lalu pilih Tidak tahu.' : 'Boleh diganti kalau penyebab berubah.'}
            </p>
            {error && (
                <p className="text-xs text-destructive" role="alert" data-testid="root-cause-error">
                    {error}
                </p>
            )}
        </div>
    );
}
