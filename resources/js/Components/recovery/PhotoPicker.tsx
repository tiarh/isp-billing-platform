import { compressImage } from '@/lib/compressImage';
import { Camera, X } from 'lucide-react';
import { useEffect, useState } from 'react';

export function PhotoPicker({ id, label, hint, files, max, required, onChange, onBusy }: {
    id: string;
    label: string;
    hint: string;
    files: File[];
    max: number;
    required: boolean;
    onChange: (files: File[]) => void;
    onBusy: (busy: boolean) => void;
}) {
    const [previews, setPreviews] = useState<string[]>([]);

    useEffect(() => {
        const urls = files.map((file) => URL.createObjectURL(file));
        setPreviews(urls);

        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, [files]);

    const addFiles = async (selected: FileList | null) => {
        const picked = Array.from(selected || []).slice(0, Math.max(0, max - files.length));
        if (picked.length === 0) return;
        onBusy(true);
        try {
            const compressed = await Promise.all(picked.map((file) => compressImage(file)));
            onChange([...files, ...compressed].slice(0, max));
        } finally {
            onBusy(false);
        }
    };

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex items-center justify-between gap-2"><label htmlFor={id} className="text-sm font-medium">{label}{required && <span className="text-status-down"> *</span>}</label><span className="text-xs text-muted-foreground">{files.length}/{max}</span></div>
            <p className="text-xs text-muted-foreground">{hint}</p>
            {previews.length > 0 && <div className="grid grid-cols-3 gap-2">{previews.map((url, index) => <div key={url} className="relative overflow-hidden rounded-md border"><img src={url} alt={label + ' ' + (index + 1)} className="aspect-square w-full object-cover" /><button type="button" aria-label={'Hapus ' + label + ' ' + (index + 1)} className="absolute right-1 top-1 rounded-full bg-background/90 p-1 shadow" onClick={() => onChange(files.filter((_, fileIndex) => fileIndex !== index))}><X className="size-3" aria-hidden /></button></div>)}</div>}
            {files.length < max && <label htmlFor={id} className="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-md border border-dashed border-input bg-background text-sm font-medium hover:bg-muted/40"><Camera className="size-4" aria-hidden />Ambil / pilih foto</label>}
            <input id={id} type="file" accept="image/*" capture="environment" multiple className="sr-only" onChange={(event) => { void addFiles(event.target.files); event.target.value = ''; }} />
        </div>
    );
}
