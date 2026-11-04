import type { LucideIcon } from 'lucide-react';
import type { NavAccess } from '@/lib/navAccess';

/** Tab tempat pertanyaan ditampilkan. Satu pertanyaan boleh tampil di keduanya. */
export type FaqAudience = 'teknisi' | 'admin';

export interface FaqImage {
    /** Path relatif dari public/, contoh: /images/faq/tiket-daftar.webp */
    src: string;
    alt: string;
    caption?: string;
}

export interface FaqLink {
    label: string;
    /** Nama route Laravel; harus ada di config/ziggy.php agar bisa dirender. */
    route: string;
}

export interface FaqItem {
    /** Unik di dalam kategori; dipakai untuk tautan #kategori--id. */
    id: string;
    question: string;
    audience: FaqAudience[];
    /** Hak akses minimum. Default: hak akses kategori. */
    access?: NavAccess;
    /** Paragraf jawaban, satu string per paragraf. */
    answer: string[];
    /** Langkah bernomor, opsional. */
    steps?: string[];
    /** Catatan "perlu diperhatikan" / "jika gagal", opsional. */
    notes?: string[];
    images?: FaqImage[];
    links?: FaqLink[];
    /** Tanggal perubahan terakhir, format YYYY-MM-DD. */
    updated: string;
}

export interface FaqCategory {
    /** Dipakai di URL: /faq?kategori=slug */
    slug: string;
    title: string;
    description: string;
    icon: LucideIcon;
    /** Hak akses minimum untuk melihat kategori; mengikuti aturan menu. */
    access: NavAccess;
    /**
     * Nama route halaman yang dicakup kategori ini. Tes FaqFrontendContractTest
     * memastikan setiap menu sidebar tercakup dan setiap route di sini ada.
     * Tombol "?" di header memakai daftar ini untuk membuka kategori yang tepat.
     */
    routes: string[];
    items: FaqItem[];
}
