import { BarChart3 } from 'lucide-react';
import type { FaqCategory } from '../types';

export const keuangan: FaqCategory = {
    slug: 'keuangan',
    title: 'Keuangan, Paket & Kemitraan',
    description: 'Laporan keuangan per area, export, penagihan massal via WA, daftar Paket internet, dan komisi mitra.',
    icon: BarChart3,
    access: 'admin',
    routes: ['finance.per-area.index', 'packages.index', 'modules.reseller'],
    items: [
        {
            id: 'keuangan-isi',
            question: 'Apa yang ditampilkan menu Keuangan?',
            images: [
                { src: '/images/faq/keuangan-per-area.webp', alt: 'Halaman Keuangan per Area dengan filter Periode invoice, Area, Tanggal acuan laporan dan kartu Tagihan, Pemasukan tercatat, Pemasukan terverifikasi, Lunas legacy, Outstanding', caption: 'Tab "Ringkasan": filter laporan dan kartu angka utama.' },
            ],
            audience: ['admin'],
            answer: [
                'Halaman "Keuangan per Area" punya tab "Ringkasan", "Detail Area", dan "Operasional". Saring dengan "Periode invoice", "Area", dan "Tanggal acuan laporan". Angka utama: Tagihan, Pemasukan tercatat, Pemasukan terverifikasi, Lunas legacy, Outstanding, dan Collection rate, ditambah grafik 10 area teratas, "Performa area", dan "Data quality".',
                'Tab "Detail Area" bisa dicari per invoice atau pelanggan dan disaring per "Status" (Lunas, Belum lunas, Jatuh tempo, Lunas legacy, Perlu review) dan "Jatuh tempo" (Belum jatuh tempo, Sudah jatuh tempo, Tanggal kosong). Tombol "XLSX" di kanan atas mengunduh data sesuai filter yang dipilih. Admin area hanya melihat areanya sendiri.',
                'Tab "Operasional" menampilkan Target Migrasi, Sudah Migrasi, PSB Bulan Ini, dan Progress PSB, dengan filter "Project", "Sub area", "RW", tautan ke Migrasi dan PSB WA, serta tabel rincian per project, area, sub area, dan RW. Ini rincian dari ringkasan "Operasional Area" di Dashboard.',
                'Laporan tren 12 bulan (MRR, tingkat koleksi, umur piutang, pendapatan per area, performa paket, distribusi metode bayar) ada di halaman "Laporan Keuangan".',
            ],
            links: [
                { label: 'Buka Keuangan', route: 'finance.per-area.index' },
                { label: 'Buka Laporan Keuangan', route: 'analytics.index' },
            ],
            updated: '2026-10-11',
        },
        {
            id: 'wa-tagihan-massal',
            question: 'Bagaimana mengirim WA penagihan massal, dan apa batasannya?',
            audience: ['admin'],
            access: 'superadmin',
            answer: [
                'Di tab "Detail Area" klik "Preview penerima": hanya invoice outstanding yang masuk, dengan nomor disamarkan. Lalu ketik "KIRIM WA TAGIHAN" dan klik "Antrekan pengiriman". Setiap invoice dicek ulang sebelum pesan dikirim.',
                'Pesannya tetap (tidak bisa diubah dari layar): "Halo {name}, Tagihan internet Anda untuk invoice {invoice_code} periode {period} sebesar {amount} belum lunas. Jatuh tempo: {due_date}. Silakan lakukan pembayaran melalui {payment_link}." Nominal yang dikirim adalah sisa tagihan.',
            ],
            notes: [
                'Bawaan: nonaktif dan dry-run. Pengaturan ada di Settings, tab Broadcast Billing, bagian "WA tagihan massal": "Enabled", "Dry-run", "Maksimal penerima" (bawaan 100), "Rate limit per menit" (bawaan 10). Menyimpan mode nyata (Enabled menyala dan Dry-run mati) meminta mengetik "KIRIM WA TAGIHAN".',
                'Jika masih nonaktif atau dry-run muncul "WA tagihan massal masih disabled atau dry-run." Dalam praktik hanya Superadmin yang bisa mengeksekusi.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'paket',
            question: 'Bagaimana menambah atau mengubah Paket, dan apa efeknya ke tagihan yang sudah ada?',
            images: [
                { src: '/images/faq/paket.webp', alt: 'Halaman Paket Internet dengan kolom Nama Paket, Profil Teknis, Batas Kecepatan, Router Asal, Harga, Pelanggan Aktif', caption: 'Daftar paket: kolom "Pelanggan Aktif" menunjukkan paket yang masih dipakai.' },
            ],
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Isian paket: "Package Name", "MikroTik Profile Name", "Router asal" ("Global / belum dipetakan" bila berlaku untuk semua), dan "Monthly Price (IDR)". Di halaman edit paket ada sakelar "Aktifkan paket": paket aktif dapat dipilih oleh bot PSB dan proses pelanggan baru. Di daftar, paket tanpa harga ditandai "Perlu harga" dan yang belum aktif ditandai "Draft".',
                'Setiap kali paket disimpan dari form edit, termasuk saat harga diganti, aplikasi langsung menyamakan nominal semua invoice Belum Lunas tanpa pembayaran milik pelanggan paket itu dengan harga paket; invoice yang sudah lunas atau punya pembayaran tidak disentuh.',
            ],
            notes: [
                'Syarat saat menyimpan dengan "Aktifkan paket" menyala: "Paket harus memiliki harga lebih dari Rp0 sebelum diaktifkan.", "Paket aktif wajib memiliki MikroTik Profile.", dan satu profile hanya boleh dipakai satu paket aktif per router ("Profile ini sudah memiliki paket aktif pada router tersebut.").',
                'Paket yang masih dipakai pelanggan tidak bisa dihapus ("Cannot delete package with active customers.").',
            ],
            links: [{ label: 'Buka Paket', route: 'packages.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'kemitraan',
            question: 'Untuk apa menu Kemitraan?',
            audience: ['admin'],
            answer: [
                'Mencatat komisi mitra/reseller. Form "Input Komisi Mitra" berisi Mitra (akun pengguna), Periode (bulan), Dasar (nilai dasar), dan Komisi, lalu "Simpan". Tabelnya menampilkan Mitra, Periode, Dasar, Komisi, dan Status (pending, approved, paid, void). Penandaan sudah dibayar belum tersedia dari layar ini.',
            ],
            links: [{ label: 'Buka Kemitraan', route: 'modules.reseller' }],
            updated: '2026-10-10',
        },
    ],
};
