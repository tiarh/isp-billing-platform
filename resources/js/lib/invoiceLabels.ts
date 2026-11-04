export const INVOICE_STATUS_LABELS: Record<string, string> = {
    paid: 'Lunas',
    unpaid: 'Belum Lunas',
    overdue: 'Lewat Jatuh Tempo',
    void: 'Batal',
};

export function invoiceStatusLabel(status: string): string {
    return INVOICE_STATUS_LABELS[status] || status;
}