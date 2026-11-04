export const CAMPAIGN_STATUS_LABELS: Record<string, string> = {
    draft: 'Draf',
    processing: 'Memproses',
    completed: 'Selesai',
    failed: 'Gagal',
    paused: 'Dijeda',
};

export const RECIPIENT_STATUS_LABELS: Record<string, string> = {
    pending: 'Menunggu',
    sending: 'Mengirim',
    retrying: 'Mengulang',
    sent: 'Terkirim',
    failed: 'Gagal',
    unconfirmed: 'Mungkin terkirim',
};

export const RECIPIENT_STATUS_HINTS: Record<string, string> = {
    unconfirmed: 'Mungkin terkirim — cek chat sebelum kirim ulang',
};

export const TARGET_TYPE_LABELS: Record<string, string> = {
    all: 'Semua Pelanggan',
    active: 'Pelanggan Aktif',
    isolated: 'Terisolasi',
    area: 'Per Area',
    custom: 'Kustom',
};

export function campaignStatusLabel(status: string): string {
    return CAMPAIGN_STATUS_LABELS[status] || status;
}

export function recipientStatusLabel(status: string): string {
    return RECIPIENT_STATUS_LABELS[status] || status;
}

export function targetTypeLabel(type: string): string {
    return TARGET_TYPE_LABELS[type] || type.replaceAll('_', ' ');
}