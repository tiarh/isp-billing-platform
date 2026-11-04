import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDestructive } from '@/Components/ConfirmDestructive';
import Checkbox from '@/Components/Checkbox';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { readMetaCsrfToken } from '@/lib/csrf';

interface SecretInfo {
    username: string;
    router: string;
    profile: string;
    disabled: boolean;
    online: boolean;
    uptime: string | null;
    address: string | null;
    last_logged_out: string | null;
    /** Pelanggan dengan username mirip (peringatan, bukan penolakan). */
    lookalikes: { id: number; username: string; router: string; text: string }[];
}

type Phase =
    | { name: 'loading' }
    | { name: 'refused'; message: string }
    | { name: 'ready'; detail: SecretInfo };

interface DeleteUnlinkedSecretDialogProps {
    routerId: number;
    /** Baris yang mau dihapus; null = dialog tertutup. */
    row: { id: number; pppoe_user: string } | null;
    onClose: () => void;
    /** Dipanggil setelah ada perubahan di router atau daftar (hapus berhasil, atau secret sudah hilang/berubah). */
    onChanged: () => void;
}

const newRequestId = () =>
    typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `r${Date.now()}${Math.random().toString(36).slice(2)}`;

async function readJson(res: Response): Promise<{ ok?: boolean; code?: string; message?: string; secret?: SecretInfo }> {
    try {
        return await res.json();
    } catch {
        return {};
    }
}

function fallbackMessage(status: number): string {
    if (status === 429) return 'Terlalu sering. Tunggu sebentar lalu coba lagi.';
    if (status === 403) return 'Hanya superadmin dan NOC yang boleh menghapus secret.';
    return 'Gagal memproses. Tidak ada yang dihapus.';
}

/** Dialog Hapus secret. Periksa router dulu, tampilkan data langsung, baru minta konfirmasi. */
export default function DeleteUnlinkedSecretDialog({ routerId, row, onClose, onChanged }: DeleteUnlinkedSecretDialogProps) {
    const [phase, setPhase] = useState<Phase>({ name: 'loading' });
    const [tick, setTick] = useState(false);
    const [lookalikeAck, setLookalikeAck] = useState(false);
    const [busy, setBusy] = useState(false);
    const requestId = useRef(newRequestId());
    const base = row ? `/api/routers/${routerId}/unlinked-secrets/${row.id}` : '';

    useEffect(() => {
        if (!row) return;
        let cancelled = false;
        requestId.current = newRequestId();
        setPhase({ name: 'loading' });
        setTick(false);
        setLookalikeAck(false);
        setBusy(false);
        fetch(`${base}/delete-check`, { headers: { Accept: 'application/json' } })
            .then(async (res) => {
                const body = await readJson(res);
                if (cancelled) return;
                if (res.ok && body.secret) {
                    setPhase({ name: 'ready', detail: body.secret });
                    return;
                }
                setPhase({ name: 'refused', message: body.message || fallbackMessage(res.status) });
                // "Secret sudah tidak ada" memperbarui daftar di server; muat ulang tampilan.
                if (body.code === 'gone' || body.code === 'not_unmatched') onChanged();
            })
            .catch(() => {
                if (!cancelled) setPhase({ name: 'refused', message: 'Router tidak bisa dihubungi. Tidak ada yang dihapus.' });
            });
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [row?.id]);

    async function submit(typed: string) {
        if (phase.name !== 'ready') return;
        setBusy(true);
        const headers = new Headers({ Accept: 'application/json', 'Content-Type': 'application/json' });
        headers.set('X-CSRF-TOKEN', readMetaCsrfToken());
        try {
            const res = await fetch(base, {
                method: 'DELETE',
                headers,
                body: JSON.stringify({
                    confirm_username: typed,
                    disconnect_online: tick,
                    lookalike_ack: lookalikeAck,
                    expected_profile: phase.detail.profile,
                    expected_disabled: phase.detail.disabled,
                    request_id: requestId.current,
                }),
            });
            const body = await readJson(res);
            if (res.ok && body.ok) {
                toast.success(body.message || 'Secret dihapus.');
                onChanged();
                onClose();
                return;
            }
            setPhase({ name: 'refused', message: body.message || fallbackMessage(res.status) });
            onChanged();
        } catch {
            setPhase({ name: 'refused', message: 'Router tidak bisa dihubungi. Tidak ada yang dihapus.' });
        } finally {
            setBusy(false);
        }
    }

    if (!row) return null;

    if (phase.name !== 'ready') {
        return (
            <Dialog open onOpenChange={(open) => !open && onClose()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Hapus secret</DialogTitle>
                        <DialogDescription className="font-mono">{row.pppoe_user}</DialogDescription>
                    </DialogHeader>
                    <p role={phase.name === 'refused' ? 'alert' : 'status'} className="text-sm text-foreground">
                        {phase.name === 'loading' ? 'Memeriksa router...' : phase.message}
                    </p>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Tutup
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        );
    }

    const { detail: secret } = phase;
    const lookalikes = secret.lookalikes ?? [];

    return (
        <ConfirmDestructive
            open
            onOpenChange={(open) => !open && !busy && onClose()}
            title="Hapus secret"
            description="Secret dihapus dari router. Cadangan terenkripsi disimpan dulu."
            requireConfirmation={false}
            typedConfirmation={secret.username}
            typedConfirmationLabel="Ketik username secret untuk melanjutkan."
            confirmText="Hapus secret"
            confirmDisabled={(secret.online && !tick) || (lookalikes.length > 0 && !lookalikeAck)}
            isLoading={busy}
            onConfirm={(typed) => void submit(typed)}
        >
            <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1 rounded-md border border-border bg-muted/30 p-3 text-sm">
                <dt className="text-muted-foreground">Router</dt>
                <dd>{secret.router}</dd>
                <dt className="text-muted-foreground">Username</dt>
                <dd className="font-mono">{secret.username}</dd>
                <dt className="text-muted-foreground">Profil</dt>
                <dd>{secret.profile || '-'}</dd>
                <dt className="text-muted-foreground">Status</dt>
                <dd>{secret.disabled ? 'Nonaktif' : 'Aktif'}</dd>
                <dt className="text-muted-foreground">Online</dt>
                <dd>
                    {secret.online ? `Ya${secret.uptime ? ` (${secret.uptime})` : ''}${secret.address ? `, ${secret.address}` : ''}` : 'Tidak'}
                </dd>
                {secret.last_logged_out && (
                    <>
                        <dt className="text-muted-foreground">Terakhir putus</dt>
                        <dd>{secret.last_logged_out}</dd>
                    </>
                )}
            </dl>
            {lookalikes.length > 0 && (
                <>
                    <ul className="space-y-1 text-sm font-medium text-status-degraded">
                        {lookalikes.map((item) => (
                            <li key={item.id}>
                                {item.text} <span className="font-mono font-normal text-muted-foreground">{item.username}</span>
                            </li>
                        ))}
                    </ul>
                    <label className="flex items-start gap-3 text-sm text-foreground">
                        <Checkbox checked={lookalikeAck} onChange={(event) => setLookalikeAck(event.target.checked)} />
                        <span>Saya sudah cek, ini bukan pelanggan itu</span>
                    </label>
                </>
            )}
            {secret.online && (
                <>
                    <p className="text-sm font-medium text-status-degraded">
                        Secret sedang online. Centang putus koneksi kalau tetap mau hapus.
                    </p>
                    <label className="flex items-start gap-3 text-sm text-foreground">
                        <Checkbox checked={tick} onChange={(event) => setTick(event.target.checked)} />
                        <span>Koneksi yang sedang aktif akan diputus</span>
                    </label>
                </>
            )}
        </ConfirmDestructive>
    );
}
