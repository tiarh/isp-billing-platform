import { Users } from 'lucide-react';
import type { FaqCategory } from '../types';

export const pelanggan: FaqCategory = {
    slug: 'pelanggan',
    title: 'Pelanggan, Saleskit & Isolir',
    description: 'Data pelanggan, status aktif/isolir/terminasi, isolir otomatis dan buka isolir, serta calon pelanggan di Saleskit.',
    icon: Users,
    access: 'auth',
    routes: ['customers.index', 'saleskit.index'],
    items: [
        {
            id: 'status-pelanggan',
            question: 'Apa arti status pelanggan?',
            images: [
                { src: '/images/faq/pelanggan-daftar.webp', alt: 'Daftar pelanggan dengan kolom ID, Nama Pelanggan, Area, Paket, Status, Gabung, Jatuh Tempo', caption: 'Kolom Status menampilkan Aktif, Isolir, dan status lainnya.' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                '"Menunggu Pasang": sudah terdaftar, belum dipasang. "Aktif": berlangganan dan boleh online. "Isolir": koneksi diputus karena tagihan belum lunas; profil PPPoE-nya diganti ke profil isolir di MikroTik. "Cuti": berhenti sementara. "Berhenti": berhenti berlangganan, dipakai saat perangkat sudah diterima gudang atau pelanggan diarsip. "Berhenti (lama)": label untuk data lama berstatus tidak aktif yang belum dirapikan; bukan status yang dipilih untuk pelanggan baru.',
                'Filter Status di daftar pelanggan memakai lima status resmi: Menunggu Pasang, Aktif, Isolir, Cuti, dan Berhenti. Kolom per area di Dashboard memakai nama "Berhenti".',
                'Titik warna di samping nama pelanggan menunjukkan sinkron MikroTik (arahkan kursor untuk melihat labelnya): "Tersinkron", "Tidak Ada" (secret PPPoE tidak ditemukan di router), atau "Belum Dicek". Filter daftar juga bisa memakai label ini.',
            ],
            links: [{ label: 'Buka Pelanggan', route: 'customers.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'teknisi-bisa-apa',
            question: 'Sebagai teknisi, apa yang bisa saya lakukan di menu Pelanggan?',
            audience: ['teknisi'],
            answer: [
                'Melihat daftar dan detail pelanggan: status, paket, area, router, PPPoE, alamat, koordinat (kartu "Location"), tab "Invoices" dan "Payment History". Mengubah data, ganti paket, isolir, buka isolir, arsip, NIK, dan foto KTP hanya untuk admin. Tombol "Export XLSX" tampil tetapi unduhan ditolak untuk akun Teknisi.',
                'Pelanggan baru tidak dibuat dari menu ini oleh teknisi, melainkan lewat PSB WA (bot WhatsApp) atau Aktivasi oleh admin.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'isolir-otomatis',
            question: 'Kapan pelanggan diisolir otomatis, dan siapa yang dikecualikan?',
            audience: ['admin'],
            answer: [
                'Pengecekan tunggakan berjalan tiap hari pukul 15.00 WIB (putaran utama) dan tiap 2 jam. Pelanggan Aktif diisolir bila invoice terakhirnya belum lunas dan sudah lewat jatuh tempo sekurang-kurangnya N hari, atau bila ada invoice bulan sebelumnya yang belum lunas dan sudah lewat N hari. N diatur di Settings, tab Enforcement, "Isolir setelah jatuh tempo (hari)" (bawaan H+3).',
                'Tidak diisolir: paket Rp0, invoice untuk bulan bergabung atau periode mendatang, invoice terakhir sudah lunas, atau pelanggan sudah membayar invoice apa pun dalam bulan kalender berjalan. Tunggakan bulan-bulan lama juga tidak memicu isolir bila invoice bulan tepat sebelumnya sudah lunas; yang dinilai tetap invoice terakhir.',
            ],
            notes: [
                'Selama Settings menampilkan badge "DRY RUN AKTIF", putaran otomatis hanya mencatat kandidat tanpa memutus siapa pun. Isolir nyata hanya terjadi pada mode "LIVE — isolir nyata".',
                'Tidak ada WA otomatis ke pelanggan saat diisolir.',
            ],
            links: [{ label: 'Buka Settings', route: 'settings.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'buka-isolir-otomatis',
            question: 'Pelanggan sudah bayar. Kapan koneksinya kembali?',
            audience: ['admin'],
            answer: [
                'Begitu sebuah invoice tercatat lunas penuh (manual, massal, atau lewat payment gateway) dan pelanggan memenuhi syarat, aplikasi langsung mengantrekan buka isolir ke MikroTik; pesan yang tampil: "Reconnect dijadwalkan." Jika tidak memenuhi syarat muncul "Reconnect tidak dijadwalkan: …" beserta alasannya.',
                'Membayar salah satu dari beberapa invoice yang belum lunas, misalnya bulan ini sementara bulan lalu masih menunggak, tetap membuka isolir. Kasus yang terlewat ditangani putaran 2 jam berikutnya.',
            ],
            steps: [
                'Masih isolir setelah bayar? Cek urutan ini: pembayaran benar-benar tercatat, yaitu invoice berstatus "Lunas" di menu Invoice (di tab "Invoices" detail pelanggan statusnya tertulis "paid").',
                'Lihat status sinkron MikroTik: titik di daftar pelanggan berlabel "Tidak Ada", atau di detail pelanggan "Sync Status" bernilai "missing" dengan peringatan "Last sync did not find this PPPoE user on MikroTik.", berarti secret PPPoE tidak ditemukan di router.',
                'Jika pelanggan tidak punya router atau PPPoE user di datanya, buka isolir otomatis tidak bisa jalan. Lengkapi lewat "Edit Network Info" lalu klik "Buka isolir".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'isolir-manual',
            question: 'Bagaimana isolir atau buka isolir secara manual?',
            images: [
                { src: '/images/faq/pelanggan-detail.webp', alt: 'Panel ringkasan pelanggan yang terbuka setelah baris di daftar diklik, dengan status layanan, area, router, PPPoE, paket, dan tombol Detail lengkap', caption: 'Klik baris pelanggan membuka ringkasan; "Detail lengkap" membuka halaman dengan tombol "Isolir layanan" / "Buka isolir".' },
            ],
            audience: ['admin'],
            answer: [
                'Di detail pelanggan, kartu "Network Enforcement", gunakan "Isolir layanan" (pelanggan Aktif) atau "Buka isolir" (pelanggan Isolir), lalu konfirmasi. Untuk status lain tombol nonaktif dengan keterangan "Buka isolir hanya untuk pelanggan berstatus Isolir. Status sekarang: …".',
                'Isolir mengganti profil secret PPPoE di MikroTik ke profil isolir router (bawaan "isolirebilling"), menyimpan profil lama, dan memutus sesi aktif, lalu menyinkronkan RADIUS. Buka isolir mengembalikan profil sebelumnya dan memutus sesi agar pelanggan tersambung ulang dengan profil normal.',
            ],
            notes: [
                'Konfirmasi buka isolir mengingatkan: "Aksi ini tidak mencatat pembayaran; pastikan tagihan sudah lunas atau ada alasan yang sah." Pembayaran tetap harus dicatat di Invoice.',
                'Butuh router dan PPPoE user terisi. Jika belum, kartu berlabel "Needs setup", tombol nonaktif dengan keterangan "Assign a MikroTik router." / "Set a PPPoE username.", dan tersedia tombol "Edit Network Info".',
                'Berbeda dengan ini, "Lifecycle Pelanggan" di tiket hanya mengubah status di database tanpa menyentuh router.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'portal-isolir',
            question: 'Apa yang dilihat pelanggan yang diisolir saat membuka internet?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Pada area yang portalnya diaktifkan, browser pelanggan dialihkan ke halaman "Belum Lunas" berisi nama dan ID pelanggan, "Tagihan <bulan>" beserta nominal, tombol "Bayar Sekarang" (tautan bayar berlaku 15 menit, dibuat ulang otomatis), keterangan bisa bayar tunai di kantor dengan menunjukkan halaman itu, tombol "WhatsApp Admin", dan catatan "Setelah bayar aktif otomatis paling lambat 5 menit." Halaman memeriksa status tiap 30 detik; setelah lunas tampil "Pembayaran Diterima".',
                'Jika portal diatur ke halaman umum, pelanggan hanya melihat "Internet Anda sedang diisolir" dengan kontak CS dan alamat kantor, tanpa data tagihan. Pengaturannya di Settings, tab Portal Isolir ("Aktifkan portal isolir", "Halaman yang dibuka pelanggan", "Nomor WhatsApp CS", "Alamat kantor", "Jam buka kantor", "Area yang boleh memakai portal").',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'arsip-restore',
            question: 'Bagaimana mengarsipkan atau mengembalikan pelanggan?',
            audience: ['admin'],
            answer: [
                'Pelanggan Aktif tidak bisa langsung diarsip: "Pelanggan aktif tidak bisa diarsip. Isolir/dismantle dulu, atau nonaktifkan status." Setelah statusnya bukan Aktif, tombol arsip memindahkannya ke Arsip dengan status "Berhenti" ("Pelanggan dipindah ke Arsip (bisa di-restore)."). Secret PPPoE di MikroTik tidak dihapus.',
                'Buka tampilan "Arsip" di daftar pelanggan lalu klik "Restore". Pelanggan kembali ke daftar dengan status tetap "Berhenti"; ubah statusnya lewat Edit bila perlu.',
            ],
            notes: [
                'Mengubah status ke Aktif dari status selain Aktif/Isolir membuat invoice bulan berjalan otomatis, kecuali pada bulan bergabung, paket Rp0, atau data tanggal bergabung/paket kosong.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'ganti-paket-massal',
            question: 'Bagaimana mengganti paket banyak pelanggan sekaligus, dan apa efeknya ke tagihan?',
            audience: ['admin'],
            answer: [
                'Centang pelanggan di daftar (maksimal 100), pilih paket baru di "Pilih paket", lalu klik "Ganti paket" dan konfirmasi. Invoice yang belum lunas dan belum punya pembayaran langsung disesuaikan ke harga paket baru; invoice yang sudah ada pembayaran tidak disentuh. Mengubah paket satu pelanggan dari form edit berlaku sama.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'saleskit-apa',
            question: 'Untuk apa menu Saleskit, dan apa yang bisa saya isi?',
            audience: ['teknisi', 'admin'],
            answer: [
                '"Saleskit — Calon Pelanggan" mencatat calon pelanggan per daerah dan RT/RW sebelum dipasang, supaya tim tahu di mana permintaan menumpuk dan kenapa belum terpasang. Semua akun, termasuk Teknisi, bisa menekan "Tambah calon".',
                'Isian: "Nama" (wajib), "No HP", "Daerah" (wajib), "Area", "RT", "RW", "ODP (opsional)", "Status" (Calon, Terpasang, Batal), "Alasan belum terpasang" ("Belum ada ODP", "ODP penuh", "Jarak jauh", "Belum bayar", "Rumah kosong", "Tidak jadi", "Tunggu tiang/FO", "Lainnya"), "Alamat", dan "Catatan".',
            ],
            notes: [
                'Data baru selalu tersimpan dengan status Calon. Mengubah hanya bisa oleh admin atau pembuat data itu sendiri; tombol Edit tampil untuk semua tetapi ditolak bila bukan milik Anda. Tombol Hapus hanya tampil untuk admin.',
                'Calon otomatis ditandai Terpasang bila saat ditambahkan sudah ada pelanggan dengan nomor HP atau nama yang sama. Untuk data lama, admin menekan "Sinkron terpasang".',
            ],
            links: [{ label: 'Buka Saleskit', route: 'saleskit.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'saleskit-import',
            question: 'Bagaimana mengimpor daftar calon pelanggan dari CSV?',
            audience: ['admin'],
            answer: [
                'Klik "Import CSV" (maksimal 10 MB). Kolom nama dan daerah wajib terisi. Duplikat dikenali dari 8 digit terakhir nomor HP, atau dari gabungan nama, daerah, RT, dan RW. Hasilnya dilaporkan sebagai "Import selesai: X ditambahkan, Y duplikat, Z dilewati (nama/daerah kosong)."',
                '"Sinkron terpasang" menandai calon yang sudah menjadi pelanggan. "Export" mengunduh CSV dengan kolom kode, nama, hp, alamat, area, daerah, rt, rw, odp, status, alasan, catatan.',
            ],
            updated: '2026-10-10',
        },
    ],
};
