interface UnlinkedSecretsNoticeProps {
    count: number;
    onOpen: () => void;
}

/** Baris oranye di Ringkasan router + tautan ke daftar secret di tab Pelanggan. */
export default function UnlinkedSecretsNotice({ count, onOpen }: UnlinkedSecretsNoticeProps) {
    if (count <= 0) return null;

    return (
        <p className="mt-1 text-xs font-medium text-status-degraded">
            {count} secret belum terhubung ke eBilling
            {' · '}
            <button type="button" className="underline underline-offset-2 hover:opacity-80" onClick={onOpen}>
                Lihat daftar
            </button>
        </p>
    );
}
