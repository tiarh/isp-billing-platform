import { Label } from '@/Components/ui/label';
import { Textarea } from '@/Components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';

export interface DismantleReasonOption {
    value: string;
    label: string;
}

interface Props {
    options: DismantleReasonOption[];
    reason: string;
    note: string;
    onReasonChange: (value: string) => void;
    onNoteChange: (value: string) => void;
    reasonError?: string;
    noteError?: string;
}

export const DISMANTLE_NOTE_MAX = 500;

/** "Alasan dismantle" dropdown + "Keterangan" note, shown when type = Dismantle. */
export default function DismantleReasonFields({ options, reason, note, onReasonChange, onNoteChange, reasonError, noteError }: Props) {
    const noteRequired = reason === 'other';

    return (
        <div className="grid gap-4 rounded-md border border-dashed p-3 md:grid-cols-2">
            <div className="space-y-2">
                <Label htmlFor="dismantle_reason">Alasan dismantle <span className="text-destructive">*</span></Label>
                <Select value={reason} onValueChange={onReasonChange}>
                    <SelectTrigger id="dismantle_reason" className="h-9" aria-invalid={reasonError ? true : undefined}>
                        <SelectValue placeholder="Pilih alasan dismantle" />
                    </SelectTrigger>
                    <SelectContent>
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {reasonError && <p className="text-xs text-destructive">{reasonError}</p>}
            </div>
            <div className="space-y-2">
                <Label htmlFor="dismantle_reason_note">Keterangan{noteRequired ? <span className="text-destructive"> *</span> : ' (opsional)'}</Label>
                <Textarea
                    id="dismantle_reason_note"
                    value={note}
                    onChange={(e) => onNoteChange(e.target.value)}
                    maxLength={DISMANTLE_NOTE_MAX}
                    required={noteRequired}
                    rows={2}
                    placeholder={noteRequired ? 'Jelaskan alasan dismantle...' : 'Catatan alasan (opsional)'}
                />
                {noteError && <p className="text-xs text-destructive">{noteError}</p>}
            </div>
        </div>
    );
}
