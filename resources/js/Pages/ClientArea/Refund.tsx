import { Head } from '@inertiajs/react';
import ClientAreaLayout from '@/Layouts/ClientAreaLayout';

interface Props {
    company: { name: string; phone: string; email: string };
    legal: { draft: boolean; fee_bearer: string };
    sandbox?: boolean;
}

// DRAFT text - shows a visible marker until client_area.legal_approved is true.
export default function Refund({ company, legal, sandbox = false }: Props) {
    return (
        <ClientAreaLayout companyName={company.name} sandbox={sandbox} draft={legal.draft}>
            <Head title="Kebijakan Refund" />
            <article className="space-y-4 text-sm leading-relaxed">
                <h1 className="text-xl font-semibold">Kebijakan Refund</h1>
                <p>
                    Layanan {company.name} adalah langganan internet bulanan. Pembayaran yang sudah diterima tidak dapat dikembalikan (tidak ada pengembalian dana).
                </p>
                <h2 className="font-semibold">1. Pembayaran ganda atau berlebih</h2>
                <p>
                    Apabila pelanggan membayar lebih dari satu kali atau melebihi nominal tagihan (pembayaran ganda/berlebih), kelebihan pembayaran tersebut dimasukkan sebagai pengurang pada tagihan bulan berikutnya.
                </p>
                <h2 className="font-semibold">2. Cara melaporkan</h2>
                <p>
                    Laporkan pembayaran ganda atau berlebih melalui layanan pelanggan di {company.phone} (atau {company.email}) dengan menyertakan kode pelanggan, kode tagihan, dan bukti pembayaran. Setelah diverifikasi, kelebihan pembayaran akan diperhitungkan pada tagihan bulan berikutnya.
                </p>
                <h2 className="font-semibold">3. Ketentuan lain</h2>
                <p>
                    Pemakaian layanan yang sudah berjalan, kesalahan pengisian data oleh pelanggan, dan pembatalan langganan di tengah periode tidak menghasilkan pengembalian dana.
                </p>
            </article>
        </ClientAreaLayout>
    );
}
