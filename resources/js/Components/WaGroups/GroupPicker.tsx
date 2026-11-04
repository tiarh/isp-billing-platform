import { useEffect, useRef, useState } from 'react';
import { Check, ChevronsUpDown, Loader2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/Components/ui/command';
import { StatusBadge } from '@/Components/StatusBadge';
import { readMetaCsrfToken } from '@/lib/csrf';
import {
    BLOCK_REASON_TEXT,
    formatGroupLabel,
    type WaChannel,
    type WaGroupRow,
    type WaBlockKey,
    type WaMembership,
} from './types';

interface Props {
    id: string;
    section: WaBlockKey;
    /** The Input chat of Progres teknisi may also be a personal number (...@s.whatsapp.net). */
    allowPersonal?: boolean;
    value: string;
    onChange: (jid: string) => void;
    channel: WaChannel;
    groups: WaGroupRow[];
    /** Original saved value: membership is only checked for a changed JID. */
    savedValue: string;
    /** Reports the membership probe state to the parent (for the override checkbox). */
    onMembership?: (state: WaMembership | null) => void;
    error?: string;
}

const JID_RE = /^\d{8,}(-\d+)?@g\.us$|^\d{8,}$|^[\w-]+@g\.us$/;

/**
 * Group combobox + manual JID box + membership badge. Groups that the server marks as blocked for this role
 * (PSB, Report, Ops...) are shown greyed with the reason and cannot be picked; a manual JID is still
 * validated by the server (never trust the client list).
 */
export function GroupPicker({ id, section, allowPersonal = false, value, onChange, channel, groups, savedValue, onMembership, error }: Props) {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<WaMembership | null>(null);
    const [checking, setChecking] = useState(false);
    const seq = useRef(0);

    const selected = groups.find((g) => g.jid === value);
    // A membership probe (findGroupInfos) returns the real group subject: prefer it over the chat-list label.
    const probedSubject = state?.subject ?? null;
    const triggerText = selected
        ? `${formatGroupLabel(probedSubject ?? selected.name, selected.jid)} (${selected.members} anggota)`
        : value
          ? probedSubject
              ? formatGroupLabel(probedSubject, value.trim())
              : `JID manual ${formatGroupLabel(null, value.trim())}`.trim()
          : 'Pilih grup';

    useEffect(() => {
        const jid = value.trim();
        if (jid === '' || jid === savedValue || !JID_RE.test(jid)) {
            setState(null);
            onMembership?.(null);
            return;
        }
        const mine = ++seq.current;
        setChecking(true);
        const timer = window.setTimeout(async () => {
            try {
                const res = await fetch(route('api.settings.wa-groups.membership'), {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': readMetaCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ channel, jid }),
                });
                const body = (await res.json()) as WaMembership;
                if (mine === seq.current) {
                    const next = res.ok ? body : { member: null, subject: null, size: null, error: 'unverified' };
                    setState(next);
                    onMembership?.(next);
                }
            } catch {
                if (mine === seq.current) {
                    const next = { member: null, subject: null, size: null, error: 'unverified' };
                    setState(next);
                    onMembership?.(next);
                }
            } finally {
                if (mine === seq.current) setChecking(false);
            }
        }, 400);
        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [value, channel, savedValue]);

    return (
        <div className="space-y-2">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button type="button" variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
                        <span className="truncate">{triggerText}</span>
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" aria-hidden />
                    </Button>
                </PopoverTrigger>
                <PopoverContent className="w-[--radix-popover-trigger-width] p-0" align="start">
                    <Command>
                        <CommandInput placeholder="Cari nama grup..." />
                        <CommandList>
                            <CommandEmpty>Grup tidak ditemukan. Isi JID manual di bawah.</CommandEmpty>
                            <CommandGroup>
                                {groups.map((g) => {
                                    const reason = g.blocked[section];
                                    const blocked = reason !== null && reason !== undefined;
                                    return (
                                        <CommandItem
                                            key={g.jid}
                                            value={`${g.name} ${g.jid}`}
                                            disabled={blocked}
                                            onSelect={() => {
                                                if (blocked) return;
                                                onChange(g.jid);
                                                setOpen(false);
                                            }}
                                            className={blocked ? 'opacity-60' : undefined}
                                        >
                                            <Check className={`mr-2 h-4 w-4 ${value === g.jid ? 'opacity-100' : 'opacity-0'}`} aria-hidden />
                                            <span className="flex-1 truncate">{formatGroupLabel(g.name, g.jid)}</span>
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {blocked ? BLOCK_REASON_TEXT[reason as string] ?? 'Tidak boleh' : `${g.members} anggota`}
                                            </span>
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>
            <Input
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={allowPersonal ? 'JID grup (...@g.us) atau nomor pribadi (...@s.whatsapp.net)' : 'JID manual, contoh 120363000000000001@g.us'}
                className="font-mono text-xs"
                aria-invalid={error ? true : undefined}
            />
            <div className="flex flex-wrap items-center gap-2 text-xs" aria-live="polite">
                {checking && (
                    <span className="inline-flex items-center gap-1 text-muted-foreground">
                        <Loader2 className="h-3 w-3 animate-spin" aria-hidden /> Memeriksa keanggotaan nomor WA...
                    </span>
                )}
                {!checking && state?.member === true && <StatusBadge label="Nomor WA anggota grup" tone="up" />}
                {!checking && state?.member === false && <StatusBadge label="Nomor WA bukan anggota" tone="down" />}
                {!checking && state && state.member === null && <StatusBadge label="Keanggotaan belum terverifikasi" tone="degraded" />}
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}
