import { Head } from '@inertiajs/react';
import ClientAreaLayout from '@/Layouts/ClientAreaLayout';

interface Props {
    company: { name: string; phone: string; email: string };
    legal: { draft: boolean; fee_bearer: string };
    sandbox?: boolean;
}

// DRAFT text - shows a visible marker until client_area.legal_approved is true.
export default function Terms({ company, legal, sandbox = false }: Props) {
    return (
        <ClientAreaLayout companyName={company.name} sandbox={sandbox} draft={legal.draft}>
            <Head title="Syarat & Ketentuan" />
            <article className="space-y-4 text-sm leading-relaxed">
                <h1 className="text-xl font-semibold">Syarat &amp; Ketentuan Layanan dan Pembayaran</h1>
                <p>
                    Dengan berlangganan dan melakukan pembayaran melalui {company.name}, pelanggan menyetujui ketentuan berikut.
                </p>
                <h2 className="font-semibold">1. Layanan</h2>
                <p>
                    {company.name} menyediakan layanan akses internet berlangganan bulanan sesuai paket yang dipilih pelanggan.
                    Tagihan diterbitkan setiap bulan dan wajib dibayar sebelum tanggal jatuh tempo.
                </p>
                <h2 className="font-semibold">2. Metode pembayaran</h2>
                <p>
                    Pembayaran online dapat dilakukan melalui virtual account bank, QRIS, atau dompet digital (e-wallet), yang diproses oleh penyedia pembayaran Duitku.
                </p>
                <h2 className="font-semibold">3. Biaya layanan pembayaran</h2>
                {legal.fee_bearer === 'merchant' ? (
                    <p>
                        Biaya layanan metode pembayaran online ditanggung oleh {company.name}; pelanggan membayar sebesar
                        nominal tagihan.
                    </p>
                ) : (
                    <p>
                        Biaya layanan metode pembayaran online (jika ada) ditanggung oleh pelanggan dan ditampilkan sebelum
                        pembayaran dikonfirmasi.
                    </p>
                )}
                <h2 className="font-semibold">4. Konfirmasi pembayaran</h2>
                <p>
                    Pembayaran online dikonfirmasi secara otomatis oleh sistem setelah notifikasi dari penyedia pembayaran
                    diterima. Umumnya proses berlangsung beberapa menit. Bila status belum berubah setelah 1 x 24 jam,
                    hubungi layanan pelanggan dengan menyertakan bukti pembayaran.
                </p>
                <h2 className="font-semibold">5. Keterlambatan</h2>
                <p>
                    Tagihan yang melewati jatuh tempo dapat menyebabkan layanan dibatasi (isolir) sampai tagihan dilunasi.
                    Layanan akan diaktifkan kembali setelah pembayaran terkonfirmasi.
                </p>
                <h2 className="font-semibold">6. Keamanan akun</h2>
                <p>
                    Pelanggan bertanggung jawab menjaga kerahasiaan kata sandi Area Pelanggan dan tidak membagikannya kepada
                    pihak lain.
                </p>
                <h2 className="font-semibold">7. Kontak</h2>
                <p>
                    Layanan pelanggan: {company.phone} / {company.email}.
                </p>
                <p className="text-xs text-muted-foreground">Ketentuan pembayaran ganda dan tidak adanya pengembalian dana dijelaskan pada halaman Kebijakan Refund.</p>
            </article>
        </ClientAreaLayout>
    );
}
