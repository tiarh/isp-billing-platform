import { CalendarCheck } from 'lucide-react';
import type { FaqCategory } from '../types';

export const absensi: FaqCategory = {
    slug: 'absensi',
    title: 'Absensi',
    description: 'Absensi teknisi lewat grup WhatsApp dan rekapnya di aplikasi.',
    icon: CalendarCheck,
    access: 'auth',
    routes: ['attendances.index'],
    items: [
        {
            id: 'cara-absen',
            question: 'Bagaimana cara absen?',
            audience: ['teknisi'],
            answer: [
                'Kirim foto ke grup WhatsApp absensi yang ditentukan admin. Yang dihitung hanya pesan foto biasa; teks, stiker, dan foto yang dikirim sebagai dokumen diabaikan. Caption boleh kosong atau diisi bebas, tidak ada kata kunci khusus dan tidak perlu kirim lokasi.',
                'Waktu absen diambil dari jam pesan WhatsApp (WIB). Caption dan nama tampilan WA pengirim dicocokkan dengan daftar teknisi aktif; setiap nama yang cocok tercatat sendiri, jadi satu foto bisa mencatat beberapa teknisi. Jika tidak ada nama yang cocok, absen tercatat atas nama tampilan WA pengirim (atau nomornya bila nama kosong).',
            ],
            notes: [
                'Jika tidak ada nama teknisi yang cocok dan caption memuat kata seperti "berdua", "bareng", "bersama", "tim", atau "rombongan", catatan diberi tanda perlu ditinjau. Tanda ini belum tampil di halaman Absensi, jadi sebaiknya tulis nama setiap teknisi di caption.',
                'Foto absensi disimpan ke Drive kantor (atau ke penyimpanan aplikasi bila Drive sedang tidak tersedia). Lokasi filenya tampil sebagai teks di kolom "Lokasi Foto Drive", bukan tautan.',
            ],
            links: [{ label: 'Buka Absensi', route: 'attendances.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'absen-tidak-muncul',
            question: 'Absen saya tidak muncul di rekap. Kenapa?',
            audience: ['teknisi'],
            answer: [
                'Urutan pengecekan: pastikan yang dikirim adalah foto biasa (bukan teks, stiker, atau file dokumen) dan dikirim ke grup absensi yang benar. Jika nama Anda di caption tidak cocok dengan daftar teknisi, absen tercatat atas nama tampilan WA Anda; cari di kolom "Karyawan".',
                'Saat koneksi WhatsApp aplikasi sempat terputus, foto absensi dari 3 hari terakhir diambil ulang otomatis secara berkala. Jika tetap tidak muncul, laporkan ke admin beserta jam kirimnya.',
                'Rekap menampilkan semua catatan dari yang terbaru, 50 baris per halaman, tanpa filter tanggal; gulir atau pindah halaman untuk melihat hari sebelumnya.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'isi-halaman',
            question: 'Apa yang ditampilkan halaman "Rekap Absensi Harian"?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Kartu "Log Absensi WA Group" dengan kolom Waktu, Karyawan, Caption, dan "Lokasi Foto Drive". Data diurutkan dari yang terbaru, 50 per halaman. Jika belum ada data muncul "Belum ada data absensi hari ini".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'atur-grup',
            question: 'Bagaimana menentukan grup WA yang dipakai untuk absensi?',
            images: [
                { src: '/images/faq/absensi.webp', alt: 'Halaman Rekap Absensi Harian dengan tombol Pengaturan di kanan atas dan tabel Log Absensi WA Group', caption: 'Tombol di kanan atas membuka Pengaturan › Grup WhatsApp untuk memilih grup.' },
            ],
            audience: ['admin'],
            answer: [
                'Grup absensi (beserta labelnya) diatur di Pengaturan › Grup WhatsApp, kartu "Absensi". Di halaman Absensi tombol "Ubah di Pengaturan › Grup WhatsApp" hanya muncul untuk Superadmin dan Admin tanpa batas area; NOC dan admin area hanya melihat grup yang aktif.',
            ],
            notes: [
                'Jika memilih "(Semua Grup / Kosongkan)", foto dari grup WhatsApp mana pun yang diterima aplikasi akan dihitung sebagai absen.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'export',
            question: 'Bagaimana mengekspor data absensi?',
            audience: ['admin'],
            answer: [
                'Tidak ada tombol export di halaman. Ekspor dilakukan lewat API khusus yang memakai token rahasia di header permintaan (token di URL ditolak), dengan parameter tanggal atau rentang dari-sampai (WIB), 200 baris per halaman secara bawaan (maksimal 500) dan 60 permintaan per menit. Token dan pembatasan aksesnya diatur di konfigurasi server, bukan di aplikasi; hubungi pengelola sistem untuk mengambil datanya.',
            ],
            updated: '2026-10-10',
        },
    ],
};
