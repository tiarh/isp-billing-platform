import { Settings } from 'lucide-react';
import type { FaqCategory } from '../types';

export const sistem: FaqCategory = {
    slug: 'sistem',
    title: 'Settings, Users & Control Plane',
    description: 'Pengaturan perusahaan dan billing, akun staf beserta peran dan area, dan kontrol produksi untuk superadmin.',
    icon: Settings,
    access: 'superadmin',
    routes: ['settings.index', 'settings.wa-groups', 'users.index', 'production-control-plane.index'],
    items: [
        {
            id: 'tab-settings',
            question: 'Apa isi tiap tab di Settings?',
            images: [
                { src: '/images/faq/settings.webp', alt: 'Halaman Settings dengan tab Company, Portal Isolir, WhatsApp Gateway, Broadcast Billing, Enforcement, Midtrans dan kartu Company Details', caption: 'Tab Settings dikelompokkan Umum, Billing, dan WA.' },
            ],
            audience: ['admin'],
            answer: [
                'Company: "Company Details" (data perusahaan yang dipakai di invoice dan kuitansi) dan "Kontak Pelanggan" (Email CS, Telepon CS, "WhatsApp CS (cadangan)"). Portal Isolir: halaman yang dilihat pelanggan terisolir, kontak CS, alamat dan jam kantor, serta "Area yang boleh memakai portal" (area kosong berarti portal tetap tertutup); tidak mengubah proses isolir di MikroTik. WhatsApp Gateway: ringkasan tiga nomor WA (Ops, Billing, Ticket) dengan tombol "Buka Pairing 3 Nomor", ringkasan grup tiket dan laporan (hanya tampil; diubah di Pengaturan › Grup WhatsApp), notifikasi tiket (Tiket Baru, Status Berubah, Laporan), "Kirim Test", dan "Progress Harian Teknisi". Broadcast Billing: "WA tagihan massal", "Auto-payment WA" dan "Mode Mass Broadcast" (masing-masing LIVE/DRY RUN), template pesan global, dan template per area. Enforcement: Aktif → Isolir (H+N) → Dismantle (H+M) dengan tombol "Simpan threshold H+N", mode dry-run, dan "Jatuh tempo tetap pelanggan baru". Midtrans: Merchant ID, "Production mode" (live/sandbox), Client Key dan Server Key (kosongkan bila tidak diubah) untuk pembayaran online.',
                'Perubahan yang belum disimpan ditandai banner "Belum tersimpan". Aksi berisiko meminta konfirmasi: "Matikan dry-run?" (tombol "Aktifkan isolir nyata"), "Aktifkan WA tagihan massal?" (mengetik frasa konfirmasi, tombol "Simpan mode LIVE"), "Aktifkan live broadcast?", dan "Aktifkan WA pembayaran?".',
            ],
            links: [{ label: 'Buka Settings', route: 'settings.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'progress-harian',
            question: 'Apa itu "Progress Harian Teknisi" di Settings?',
            audience: ['admin'],
            answer: [
                'Template harian dari tiket maintenance dan PSB yang disusun menjadi pesan WA ke grup teknisi. Di Settings, tab WhatsApp Gateway, Anda bisa melihat pratinjau dan menyalinnya, mengatur jadwal dan template, lalu "Simpan Progress Harian". Grup Share dan Grup Input (beserta labelnya) hanya tampil di sini; ubah di Pengaturan › Grup WhatsApp.',
                'Ada tiga sakelar: "Dry Run" (bawaan aktif; selama aktif tidak ada pesan yang dikirim), "Live Jadwal 08:00" (mengirim jadwal teknisi tiap pagi), dan "Live Rekap" (mengirim rekap progress siang dan sore). Pesan baru benar-benar terkirim bila Dry Run dimatikan dan sakelar Live yang bersangkutan dinyalakan.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'tambah-user',
            question: 'Bagaimana menambah akun staf dan menentukan menunya?',
            images: [
                { src: '/images/faq/users.webp', alt: 'Halaman Pengguna dengan tabel Nama, Peran, TOTP, Area, Telepon dan tombol Tambah Pengguna', caption: 'Kolom Area menunjukkan "Semua area" atau area tertentu untuk Admin area.' },
            ],
            audience: ['admin'],
            answer: [
                'Di menu Users klik "Tambah Pengguna", isi Nama, "Email Login", Telepon, Peran (Superadmin, Admin, NOC, atau Teknisi; bawaan Teknisi), Password dan "Ulangi Password", lalu "Buat Pengguna". Saat mengedit, kosongkan "Password Baru" agar password tidak berubah.',
                '"Area Admin" hanya berlaku untuk peran Admin: admin yang diberi area menjadi Admin area dan hanya melihat data area itu, serta kehilangan menu Paket, MikroTik, OLT, Area, dan Aktivasi. Peran lain selalu berakses global; area yang dipilih untuk peran lain akan dikosongkan otomatis. Pilih NOC untuk staf jaringan yang perlu OLT/MikroTik tanpa akses Settings dan Users.',
            ],
            notes: [
                'Akun sendiri dan superadmin terakhir tidak bisa dihapus. Menghapus akun meminta centang "Saya memahami akun ini akan dihapus dan tindakan ini tidak dapat dibatalkan."',
            ],
            links: [{ label: 'Buka Users', route: 'users.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'reset-totp',
            question: 'Bagaimana mereset TOTP pengguna yang kehilangan HP?',
            audience: ['admin'],
            answer: [
                'Di menu Users, klik ikon perisai "Reset TOTP" pada kolom Aksi baris pengguna itu. Di dialog "Reset TOTP pengguna" isi "Password superadmin saat ini", centang "Saya memahami pengguna akan diminta enroll ulang.", lalu klik "Reset TOTP". Kolom TOTP pengguna itu berubah menjadi "Belum aktif".',
                'TOTP hanya dipakai akun Superadmin. Setelah direset, login tidak lagi meminta kode sampai pengguna itu mengaktifkan TOTP lagi dengan memindai QR baru; aplikasi akan mengarahkannya ke halaman aktivasi TOTP saat ia menyimpan Pengaturan Monitoring, menyimpan Pengaturan Payment Gateway, atau mengubah kill switch pembayaran.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'control-plane',
            question: 'Apa yang ditampilkan Control Plane?',
            images: [
                { src: '/images/faq/control-plane.webp', alt: 'Halaman Production Control Plane dengan kartu Device Mode, Failed Jobs, Generated, Kill Switch, dan Feature Flag', caption: 'Bagian "Kill Switch": "Semua perangkat read-only" ON berarti tulis perangkat untuk migrasi ditahan (PSB punya saklar sendiri).' },
            ],
            audience: ['admin'],
            answer: [
                'Papan status produksi yang hanya untuk dibaca (tidak ada sakelar di halaman ini): Device Mode, Failed Jobs, Generated, Kill Switch, Feature Flag, State Machine, WA Contract, RBAC Approval, Observability, Source of Truth, dan "Data Quality & ML". Bagian Kill Switch menunjukkan "Semua perangkat read-only" (bawaan ON) serta "MikroTik write", "OLT write", "GenieACS write", "Payment write", dan "WA auto-reply global". Saklar ini dipakai sebagai pengaman eksekusi perangkat di Migrasi; pengaman lain, misalnya gerbang pengaman tulis OLT di halaman OLT/Aktivasi, punya pengaturan sendiri. Jika sebuah aksi ditolak karena saklar pengaman, koordinasikan dengan pemilik sebelum dibuka.',
            ],
            links: [{ label: 'Buka Control Plane', route: 'production-control-plane.index' }],
            updated: '2026-11-01',
        },
    ],
};
