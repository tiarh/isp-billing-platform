import { KeyRound } from 'lucide-react';
import type { FaqCategory } from '../types';

export const akun: FaqCategory = {
    slug: 'akun',
    title: 'Akun, Login & Dashboard',
    description: 'Masuk ke aplikasi, lupa dan ganti password, kode TOTP, keluar dan sesi, peran dan menu yang terlihat, serta isi Dashboard.',
    icon: KeyRound,
    access: 'auth',
    routes: ['dashboard', 'faq.index'],
    items: [
        {
            id: 'login',
            question: 'Bagaimana cara masuk (login) ke aplikasi?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Di halaman login isi "Email" dan "Password" akun staf Anda, lalu klik "Sign In". Akun dibuat oleh superadmin lewat menu Users; tidak ada pendaftaran mandiri.',
                'Setelah 5 kali salah password untuk email yang sama dari perangkat yang sama, login dikunci sementara (sekitar 15 menit). Tunggu sampai waktu yang disebut di pesan habis, lalu coba lagi, atau gunakan "Forgot password?".',
            ],
            notes: [
                'Akun superadmin yang sudah mengaktifkan TOTP diarahkan ke halaman "Verifikasi login" setelah password benar; masukkan kode dari authenticator atau salah satu recovery code.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'lupa-password',
            question: 'Saya lupa password. Apa yang harus dilakukan?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Di halaman login klik "Forgot password?", isi "Email" akun Anda, lalu klik "Email Password Reset Link". Jika email terkirim, buka tautan di email tersebut dan buat password baru.',
                'Email reset hanya terkirim bila server email aplikasi sudah dikonfigurasi. Jika dalam beberapa menit email tidak masuk (cek juga folder spam), minta superadmin mengisi password baru untuk akun Anda dari menu Users, lalu segera ganti password itu di halaman Profile.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'ganti-password',
            question: 'Bagaimana cara mengganti password saya?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Halaman Profile belum ada di menu; buka lewat tombol "Buka Profil" di bawah. Di kartu "Update Password" isi "Current Password", "New Password" (minimal 8 karakter), dan "Confirm Password", lalu klik "Save". Muncul tulisan "Saved" dan password baru berlaku untuk login berikutnya.',
            ],
            notes: [
                'Kartu "Delete Account" di halaman yang sama menghapus akun Anda sendiri secara permanen setelah password dimasukkan. Jangan dipakai; minta superadmin bila akun memang perlu dinonaktifkan.',
            ],
            links: [{ label: 'Buka Profil', route: 'profile.edit' }],
            updated: '2026-10-10',
        },
        {
            id: 'logout-sesi',
            question: 'Bagaimana cara keluar (logout), dan kenapa saya tiba-tiba diminta login lagi?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Klik nama Anda di pojok kiri bawah sidebar (di HP: tombol inisial di kanan atas), lalu pilih "Log Out". Selalu logout bila memakai HP atau komputer bersama.',
                'Sesi login berakhir otomatis bila aplikasi tidak dipakai beberapa lama. Setelah itu Anda kembali ke halaman login; masuk lagi seperti biasa. Data yang belum disimpan di formulir sebelum sesi habis perlu diisi ulang.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'menu-berbeda',
            question: 'Mengapa menu di akun saya lebih sedikit daripada rekan lain?',
            images: [
                { src: '/images/faq/menu-sidebar.webp', alt: 'Sidebar akun Superadmin dengan kelompok menu Operasi, Billing, dan Network', caption: 'Sidebar akun Superadmin: semua kelompok menu tampil.' },
                { src: '/images/faq/hp-menu.webp', alt: 'Menu geser di HP untuk akun Teknisi yang hanya menampilkan menu yang boleh dibuka teknisi', caption: 'Menu di HP untuk akun Teknisi: hanya menu yang boleh dibuka.' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                'Menu mengikuti peran akun. Ada empat peran: Superadmin, Admin, NOC, dan Teknisi. Admin yang diberi area tertentu di menu Users disebut Admin area; data pelanggan dan tagihannya dibatasi ke area itu, dan menu Paket, MikroTik, OLT, Area, dan Aktivasi tidak tampil.',
                'Teknisi melihat Dashboard (tanpa angka billing), Pelanggan (hanya lihat), Saleskit, Absensi, Invoice (hanya lihat), Broadcast WA (hanya lihat), Ticketing (hanya tiket yang ditugaskan kepadanya), Mapping, Inventaris, dan FAQ / Panduan.',
                'Peran tampil di bawah nama Anda pada pojok kiri bawah sidebar (di HP: tombol inisial di kanan atas): Superadmin, NOC, Teknisi, "Global Admin" untuk Admin tanpa batas area, atau "Scoped Admin" untuk Admin area. Perubahan peran atau area hanya bisa dilakukan superadmin lewat menu Users.',
            ],
            links: [{ label: 'Buka Dashboard', route: 'dashboard' }],
            updated: '2026-10-10',
        },
        {
            id: 'dashboard',
            question: 'Apa saja yang ditampilkan Dashboard?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Untuk Superadmin, Admin, dan NOC, bagian atas berisi empat kartu: "Tagihan bulan ini", "Pelanggan aktif", "Piutang berjalan", dan "Jatuh tempo". Di pojok kanan atas ada penanda bulan, penanda area (Admin area hanya melihat areanya), dan tombol "Buat Invoice".',
                'Di bawahnya: kolom kiri berisi "Recent Payments" (5 pembayaran terakhir; "Lihat semua" membuka daftar invoice berstatus lunas) dan "Dashboard cabang (per lokasi)". Kolom kanan berisi "Billing Health" (Collection Rate serta jumlah invoice Lunas, Belum bayar, dan Tanpa invoice bulan berjalan) dan ringkasan "Operasional Area".',
                'Ringkasan "Operasional Area" hanya menampilkan empat angka (Target Migrasi, Sudah Migrasi, PSB Bulan Ini, Progress PSB). Filter Project, Sub area, dan RW serta tabel rincian ada di Keuangan, tab "Operasional", lewat tautan "Lihat detail" (untuk Admin, NOC, dan Superadmin).',
                'Teknisi tidak menerima angka billing; akun teknisi hanya melihat ringkasan "Operasional Area" tanpa filter dan tabel rincian.',
            ],
            links: [{ label: 'Buka Dashboard', route: 'dashboard' }],
            updated: '2026-10-11',
        },
        {
            id: 'pencarian-global',
            question: 'Bagaimana memakai pencarian cepat Ctrl+K untuk mencari pelanggan, invoice, atau tiket?',
            images: [
                { src: '/images/faq/ctrlk-pencarian.webp', alt: 'Kotak pencarian Ctrl+K di atas Dashboard dengan hasil dikelompokkan Pelanggan, Invoice, dan Jaringan', caption: 'Ctrl+K: ketik kata kunci, hasil dikelompokkan per jenis.' },
                { src: '/images/faq/hp-ctrlk-pencarian.webp', alt: 'Pencarian layar penuh di HP dengan hasil Pelanggan dan Invoice', caption: 'Di HP, ketuk ikon kaca pembesar di bagian atas.' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                'Pencarian global bisa dibuka dari halaman mana pun setelah login. Tekan Ctrl+K (di Mac: ⌘K), tekan "/" saat kursor tidak sedang di kolom isian, atau klik kolom "Cari pelanggan, invoice, SN…" di bagian atas halaman. Di HP, ketuk ikon kaca pembesar di bagian atas kanan, di sebelah kiri ikon tanda tanya. Kolom pencarian akan terbuka di atas halaman yang sedang Anda lihat.',
                'Ketik minimal 2 huruf. Hasil dikelompokkan: "Pelanggan" (dicari lewat nama, kode pelanggan, akun PPPoE, awalan SN ONU, atau nomor HP), "Invoice" (lewat nomor invoice; minimal 4 karakter dan berisi angka atau tanda "-" atau diawali "INV", misalnya INV-202610), "Tiket" (lewat kode atau judul), "Jaringan" (router, OLT, dan area), serta "Halaman" untuk lompat ke menu, misalnya ketik "tagihan" untuk membuka Invoice. Tiap kelompok menampilkan maksimal 5 hasil teratas (Jaringan: maksimal 5 per jenis, yaitu router, OLT, dan area).',
                'Hasil pelanggan menampilkan nama, kode, area, nomor HP, dan lencana status (Aktif, Isolir, dan seterusnya). Pelanggan yang sudah diarsipkan tetap muncul dengan lencana "Arsip"; memilihnya membuka daftar Pelanggan bagian arsip yang sudah terisi pencariannya. NIK, alamat, dan alamat IP tidak pernah ditampilkan di sini.',
            ],
            steps: [
                'Tekan Ctrl+K (atau ⌘K di Mac), atau klik kolom pencarian di atas.',
                'Ketik kata kunci, misalnya nama pelanggan, kode, nomor HP, SN ONU, atau nomor invoice.',
                'Pilih hasil dengan tombol panah atas/bawah lalu Enter, atau klik/ketuk hasilnya. Ctrl+Enter (⌘+Enter di Mac) membukanya di tab baru.',
                'Tekan Esc untuk menutup tanpa memilih.',
            ],
            notes: [
                'Siapa melihat apa: semua peran bisa memakai pencarian ini, tetapi hasilnya mengikuti hak akses akun. Admin area hanya mendapat pelanggan, invoice, dan tiket di areanya. Teknisi mendapat tiket yang ditugaskan kepadanya, sedangkan pelanggan dan invoice mengikuti halaman Pelanggan dan Invoice yang memang bisa dibuka teknisi. Kelompok "Jaringan" hanya muncul untuk Superadmin, NOC, dan Admin global.',
                'Pencarian ini tidak menyimpan riwayat. Bila muncul "Terlalu banyak pencarian, tunggu sebentar", tunggu beberapa detik lalu ketik lagi. Ctrl+K sengaja menggantikan fungsi Ctrl+K bawaan browser selama Anda berada di eBilling.',
            ],
            updated: '2026-10-12',
        },
        {
            id: 'totp',
            question: 'Apa itu kode TOTP dan kapan diminta?',
            audience: ['admin'],
            access: 'superadmin',
            answer: [
                'TOTP adalah kode 6 digit dari aplikasi authenticator (Google Authenticator, Authy, dan sejenisnya) yang berganti tiap 30 detik. Fitur ini khusus akun superadmin. Kode diminta saat login bila TOTP sudah aktif di akun Anda, dan sebagai verifikasi tambahan setiap kali menyimpan Pengaturan Monitoring, menyimpan Pengaturan Payment Gateway, atau mengubah kill switch pembayaran.',
                'Mengaktifkan: halaman "Keamanan TOTP" terbuka otomatis saat Anda menyimpan Pengaturan Monitoring, menyimpan Pengaturan Payment Gateway, atau mengubah kill switch pembayaran dan TOTP belum aktif. Klik "Mulai enrollment", masukkan "Secret" (atau "Provisioning URI") ke aplikasi authenticator, simpan 8 recovery code di tempat aman, lalu isi "Kode dari authenticator" untuk konfirmasi.',
                'Jika ponsel hilang, login memakai salah satu recovery code (tiap code hanya sekali pakai). Bila recovery code juga hilang, superadmin lain dapat mereset TOTP Anda dari menu Users (tombol "Reset TOTP", dengan memasukkan "Password superadmin saat ini"). Setelah reset, lakukan enrollment ulang.',
            ],
            links: [{ label: 'Buka Users', route: 'users.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'faq-usang',
            question: 'Jawaban di FAQ ini tidak sesuai dengan tampilan aplikasi. Harus lapor ke mana?',
            audience: ['teknisi', 'admin'],
            answer: [
                'FAQ ini ikut diperbarui setiap kali fitur berubah, tetapi bisa saja ada yang terlewat. Sampaikan ke admin atau NOC dengan menyebut judul pertanyaannya dan tampilan yang Anda lihat; tim pengembang memperbaikinya bersama rilis berikutnya.',
                'Tanggal "Diperbarui" di bawah tiap jawaban menunjukkan kapan jawaban itu terakhir diperiksa.',
            ],
            updated: '2026-10-10',
        },
    ],
};
