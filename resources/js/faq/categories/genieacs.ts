import { Globe2 } from 'lucide-react';
import type { FaqCategory } from '../types';

export const genieacs: FaqCategory = {
    slug: 'genieacs',
    title: 'GenieACS',
    description: 'Kelola perangkat pelanggan (CPE) lewat TR-069: cek status online, reboot, dan lihat parameter seperti SSID atau IP.',
    icon: Globe2,
    access: 'admin',
    routes: ['modules.genieacs'],
    items: [
        {
            id: 'cara-pakai',
            question: 'Bagaimana memakai halaman GenieACS?',
            images: [
                { src: '/images/faq/genieacs.webp', alt: 'Halaman GenieACS dengan tombol Sync Sekarang, blok Cara pakai (NOC), kartu Device, Link eBilling, FiberHome, Internet OK, dan chip filter', caption: 'Blok "Cara pakai (NOC)" dan kartu KPI yang sekaligus menjadi filter.' },
            ],
            audience: ['admin'],
            answer: [
                'Halaman ini mengelola CPE/ONU tanpa membuka panel GenieACS terpisah. Urutan pakainya: klik "Sync Sekarang" untuk menarik data TR-069 terbaru dari ACS; klik kartu KPI (Device, Link eBilling, FiberHome, Internet OK) atau chip ("Semua", "Online", "Offline", "Sudah link", "Belum link", "FiberHome"; angka di dalam kurung adalah jumlahnya) untuk menyaring; klik baris atau "Detail" untuk melihat parameter, tag, tombol reboot, dan tautan ke pelanggan.',
                'Kolom tabel: Pelanggan / Device, PPPoE, PON, WiFi, Optik, Uptime, IP, Status. Jika sync gagal atau masih berjalan, muncul pesan bahwa dashboard tetap memakai snapshot terakhir; data lama tetap ditampilkan. Coba lagi beberapa saat kemudian.',
            ],
            links: [{ label: 'Buka GenieACS', route: 'modules.genieacs' }],
            updated: '2026-10-10',
        },
        {
            id: 'reboot-cpe',
            question: 'Bagaimana me-reboot perangkat pelanggan dari jarak jauh?',
            audience: ['admin'],
            answer: [
                'Buka detail perangkat, klik tombol "Reboot CPE". Dialog "Reboot CPE?" meminta centang "Saya memahami perangkat akan offline sementara." lalu tombol "Reboot CPE". Jika berhasil muncul "Perintah reboot dikirim ke GenieACS". Tombol tetap bisa diklik walau perangkat berstatus Offline, jadi pastikan statusnya Online dulu agar reboot langsung dijalankan.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'ubah-ssid',
            question: 'Bisakah mengubah SSID atau password WiFi pelanggan dari aplikasi?',
            audience: ['admin'],
            answer: [
                'Belum ada form ubah SSID/password di halaman ini; detail perangkat menampilkan parameter WiFi untuk dibaca saja. Perubahan dilakukan lewat panel GenieACS atau langsung di perangkat. Jika fitur ini dibutuhkan, sampaikan ke tim pengembang.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'belum-link',
            question: 'Perangkat berstatus "Belum link ke pelanggan". Bagaimana menghubungkannya?',
            audience: ['admin'],
            answer: [
                'Saat sync, aplikasi mencocokkan perangkat ke pelanggan berurutan: SN perangkat dengan SN ONU di data pelanggan, lalu PPPoE user perangkat (dibaca dari parameter perangkat, atau dari tag ACS bila parameternya kosong) dengan PPPoE pelanggan, lalu nama pelanggan dari ACS (parameter atau tag) yang sama persis dengan nama atau PPPoE pelanggan. Pastikan SN ONU di data pelanggan terisi benar, atau beri nama ONU sesuai format lewat "Rename ONU + ACS" di halaman OLT (tag dipakai sebagai nama cadangan di tab FiberHome). Setelah itu klik "Sync Sekarang".',
                'Admin area hanya melihat perangkat yang sudah terhubung ke pelanggan di areanya; perangkat yang belum link tidak tampil untuk Admin area.',
            ],
            updated: '2026-10-10',
        },
    ],
};
