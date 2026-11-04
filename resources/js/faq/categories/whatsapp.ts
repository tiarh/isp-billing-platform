import { MessageCircle } from 'lucide-react';
import type { FaqCategory } from '../types';

export const whatsapp: FaqCategory = {
    slug: 'whatsapp',
    title: 'WA Gateway & Broadcast WA',
    description: 'Nomor WA yang dipakai aplikasi, notifikasi tagihan otomatis, kampanye Broadcast WA, dan cara membaca log pengiriman.',
    icon: MessageCircle,
    access: 'auth',
    routes: ['wa-gateway.pair', 'broadcasts.index'],
    items: [
        {
            id: 'tiga-nomor',
            question: 'Nomor WA mana yang dipakai aplikasi untuk apa?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Aplikasi memakai hingga tiga nomor WhatsApp yang dipasangkan di menu WA Gateway. Setiap kartu nomor menampilkan nama instance yang sebenarnya, status koneksi langsung, empat digit terakhir nomor, dan "Dipakai untuk: ..." yaitu fungsi yang benar-benar lewat nomor itu (PSB/#migrasi, /pppoe, OLT Alert, absensi, billing & broadcast, ticket & progress, report).',
                'Nomor 1 umumnya menangani operasional dan PSB; Nomor 2 untuk billing dan broadcast. Ticket & Progress memakai nomor yang dipilih di routing, bisa Nomor 1 atau Nomor 3. Bila ticket & progress lewat Nomor 1, kartu Nomor 1 menulis "juga menangani Ticket & Progress", dan kartu Nomor 3 bertanda abu-abu "TIDAK DIPAKAI" karena tidak ada fungsi yang lewat nomor itu.',
                'Superadmin dapat mengubah pilihan "Ticket & Progress memakai" di halaman WA Gateway. Perubahan meminta konfirmasi, mencatat log aktivitas, dan memberi peringatan (tidak memblokir) bila nomor terpilih belum terhubung atau bukan anggota grup Ticket/Report.',
                'Jika bot tidak menjawab atau notifikasi tiket berhenti, kemungkinan besar nomor yang bersangkutan terputus; minta superadmin memeriksa statusnya.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'broadcast-teknisi',
            question: 'Kenapa "Kampanye Baru" di Broadcast WA ditolak untuk akun saya?',
            audience: ['teknisi'],
            answer: [
                'Semua akun bisa melihat daftar dan detail kampanye, tetapi membuat kampanye dan mengulang pengiriman yang gagal hanya untuk Admin, NOC, dan Superadmin. Tombol "Kampanye Baru" dan "Ulang Gagal" tetap tampil, tetapi untuk akun Teknisi halaman pembuatan kampanye tidak bisa dibuka dan pengiriman ulang ditolak.',
            ],
            links: [{ label: 'Buka Broadcast WA', route: 'broadcasts.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'pairing',
            question: 'Bagaimana memasangkan (pairing) nomor WA, dan apa arti statusnya?',
            audience: ['admin'],
            access: 'superadmin',
            answer: [
                'Di menu WA Gateway ("WA Gateway - Pairing Multi-Nomor") tiap nomor punya badge CONNECTED atau UNPAIRED; panel "Status Koneksi" nomor yang dipilih menampilkan CONNECTED, WAITING SCAN, atau status lain dari gateway. Pilih nomornya, klik "Buat / Refresh QR", lalu di HP yang memegang nomor itu buka WhatsApp: "Setelan → Perangkat Tertaut → Tautkan Perangkat" dan pindai QR. Setelah CONNECTED, bagian "Test Kirim Pesan" muncul; "Kirim Test" mengirim pesan uji ke "Nomor Tujuan" (format 62…).',
                '"Pairing Ulang (Logout Device)" memutus sesi perangkat dan meminta konfirmasi "Saya memahami sesi perangkat akan dihentikan."; tombol ini hanya muncul saat nomor berstatus CONNECTED. Untuk Nomor 1, aplikasi mencoba memulihkan sesi sendiri tiap 5 menit antara pukul 05.00 dan 23.00 WIB dan memberi peringatan bila perlu scan QR ulang.',
            ],
            links: [{ label: 'Buka WA Gateway', route: 'wa-gateway.pair' }],
            updated: '2026-10-10',
        },
        {
            id: 'wa-otomatis-tagihan',
            question: 'WA apa saja yang dikirim otomatis ke pelanggan terkait tagihan?',
            audience: ['admin'],
            answer: [
                'Saat ini hanya dua: "Pembayaran lunas" (setelah invoice terbayar penuh, berisi tautan kuitansi) dan "Pelanggan baru" (ucapan selamat datang saat pelanggan ditambahkan dari form di menu Pelanggan). Belum ada WA otomatis saat invoice terbit, pengingat sebelum jatuh tempo, pemberitahuan terlambat, atau saat diisolir; template isolir tersedia di Settings tetapi berlabel "Belum aktif".',
                'Pengiriman hanya terjadi bila sakelar "Pembayaran lunas" atau "Pelanggan baru" di Settings, tab Broadcast Billing, menyala (bawaan mati), "Auto-payment WA" berstatus LIVE (tombol "Aktifkan Auto-payment" / "Jadikan Dry Run"), dan Nomor 2 terhubung. Dalam dry-run pesan dicatat sebagai dilewati, tidak dikirim. Pengaturan ini hanya bisa diubah Superadmin.',
            ],
            notes: [
                'Superadmin bisa mengubah template per lokasi lewat "Template per lokasi (Area)". Placeholder yang tersedia: {name} {code} {phone} {area} {package} {amount} {invoice_code} {due_date} {paid_at} {period} {payment_link} {receipt_link} {company_name}.',
                'Log pengiriman WA tagihan (terkirim/gagal/dilewati) belum punya halaman di aplikasi; minta tim pengembang bila perlu ditelusuri.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'buat-broadcast',
            question: 'Bagaimana membuat dan menjalankan kampanye Broadcast WA?',
            images: [
                { src: '/images/faq/broadcast-wa.webp', alt: 'Halaman Broadcast WA dengan tombol Kampanye Baru dan tabel kampanye', caption: '"Kampanye Baru" di kanan atas; tabel menampilkan Nama, Tanggal, Progres, Status.' },
            ],
            audience: ['admin'],
            access: 'admin',
            answer: [
                'Yang bisa membuat kampanye: Admin (termasuk Admin area), NOC, dan Superadmin. Di menu Broadcast WA klik "Kampanye Baru". Isi "Nama Kampanye", pilih "Target Penerima" (Semua Pelanggan, Pelanggan Aktif Saja, atau Pelanggan Terisolasi Saja), "Filter Area" bila perlu, dan "Template Pesan" (bisa memakai {name} dan {billing_amount}; {billing_amount} diisi harga paket pelanggan, bukan nominal invoice). Tombol "Mulai Kampanye" langsung mengantrekan pengiriman lewat Nomor 2 ke pelanggan yang punya nomor HP, dengan jeda 4 sampai 9 detik antar pesan.',
                'Status kampanye: Draf, Memproses, Selesai, Gagal, Dijeda. Status per penerima: Menunggu, Terkirim, Gagal. Progres dan alasan gagal per penerima tampil di detail kampanye, bagian "Log Penerima". Penerima yang gagal bisa diulang lewat "Ulang Gagal (n)" dengan dialog "Konfirmasi pengiriman ulang".',
            ],
            notes: [
                'Jika "Mode Mass Broadcast" di Settings (diatur Superadmin) masih DRY RUN, kampanye berhenti dengan status "Dijeda" dan tiap penerima bertanda "Dry-run: broadcast WA billing belum di-enable."; jika Nomor 2 terputus tandanya "Broadcast dijeda: WA billing state=…". Kampanye yang Dijeda tidak berjalan sendiri setelah masalahnya beres; "Ulang Gagal" (hanya tampil bila ada penerima Gagal) ikut mengantrekan ulang penerima yang tertahan, jika tidak ada buat kampanye baru.',
                'Admin area wajib memilih salah satu areanya di "Filter Area"; pilihan "-- Semua Area --" ditolak. Daftar kampanye untuk Admin area hanya memuat kampanye areanya.',
            ],
            updated: '2026-10-10',
        },
    ],
};
