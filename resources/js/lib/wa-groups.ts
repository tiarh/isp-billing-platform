export type WaGroup = { id: string; subject?: string; name?: string };

export function parseWaGroups(data: unknown): WaGroup[] {
    if (Array.isArray(data)) {
        return data as WaGroup[];
    }

    if (data && typeof data === 'object') {
        const payload = data as Record<string, unknown>;
        if (Array.isArray(payload.groups)) {
            return payload.groups as WaGroup[];
        }
        if (Array.isArray(payload.data)) {
            return payload.data as WaGroup[];
        }
        if (Array.isArray(payload.response)) {
            return payload.response as WaGroup[];
        }
    }

    return [];
}

export function waGroupLabel(group: WaGroup): string {
    return group.subject || group.name || group.id;
}

export function resolveWaGroupFetchHint(
    payload: Record<string, unknown> | null,
    parsedLength: number,
    fetchError: boolean,
    status?: number | null,
): string | null {
    if (fetchError) {
        return status === 403
            ? 'Akses ditolak. Masukkan JID grup secara manual.'
            : 'Gagal memuat grup otomatis. Masukkan JID grup secara manual.';
    }

    if (parsedLength > 0) {
        return null;
    }

    if (!payload) {
        return 'Daftar grup kosong. Masukkan JID grup secara manual.';
    }

    if (payload.configured === false) {
        return 'Evolution API belum dikonfigurasi. Masukkan JID grup secara manual.';
    }

    if (payload.connected === false) {
        return `WhatsApp gateway belum terhubung (state: ${String(payload.state ?? 'unknown')}). Masukkan JID grup secara manual.`;
    }

    return 'Daftar grup kosong. Pastikan bot sudah join grup, atau masukkan JID manual.';
}