import { FileText } from 'lucide-react';
import type { FaqCategory } from '../types';

export const invoice: FaqCategory = {
    slug: 'invoice',
    title: 'Invoice & Payment',
    description: 'Tagihan bulanan, pembayaran manual dan via payment gateway, void, pembatalan pembayaran, dan kuitansi.',
    icon: FileText,
    access: 'auth',
    routes: ['invoices.index', 'modules.payment-gateway', 'payment-gateway.transactions', 'customer-credits.index'],
    items: [
        {
            id: 'status-invoice',
            question: 'Apa arti status invoice?',
            images: [
                { src: '/images/faq/invoice-daftar.webp', alt: 'Daftar invoice dengan kolom Kode, Pelanggan, Periode, Paket Aktif, Nominal, Status, Jatuh Tempo', caption: 'Status "Lunas" dan "Belum Lunas"; invoice lewat jatuh tempo diberi tanda "Terlambat".' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                '"Belum Lunas": belum ada pembayaran penuh. "Lunas": sudah dibayar penuh. "Batal": invoice dibatalkan (void) dan tidak ditagihkan. Invoice belum lunas yang lewat tanggal jatuh tempo diberi tanda merah "Terlambat" di kolom Jatuh Tempo.',
                'Pada invoice Belum Lunas, label "Nominal perlu sync" berarti nominal invoice berbeda dengan harga paket pelanggan saat ini; "Sesuai paket" berarti sama.',
            ],
            links: [{ label: 'Buka Invoice', route: 'invoices.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'teknisi-invoice',
            question: 'Sebagai teknisi, apa yang bisa saya lakukan di menu Invoice?',
            audience: ['teknisi'],
            answer: [
                'Melihat daftar dan detail tagihan, mengunduh PDF (ikon "Unduh PDF" di daftar atau "Download PDF" di detail), dan menyalin "Link Pelanggan" untuk dikirim ke pelanggan. Mencatat pembayaran, membatalkan, dan menghapus invoice hanya untuk admin. Tombol "Export XLSX" tampil tetapi unduhan ditolak untuk akun Teknisi.',
                'Jika pelanggan membayar tunai kepada Anda, serahkan ke admin untuk dicatat; koneksi pelanggan yang diisolir baru dibuka setelah pembayaran tercatat di aplikasi.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'link-pelanggan',
            question: 'Bagaimana mengirim tagihan atau kuitansi ke pelanggan?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Di detail invoice, "Link Pelanggan" adalah tautan PDF bertanda tangan digital yang berlaku 30 hari: sebelum lunas berisi tagihan, setelah lunas berisi kuitansi. Salin dan kirim lewat WA. Tautan tidak tersedia untuk invoice Batal.',
                'Halaman bayar publik yang dibuka pelanggan punya tab "Bayar Instan" (QRIS, virtual account, e-wallet, kartu melalui Midtrans, bila kuncinya sudah diatur) dan "Transfer Bank" (daftar rekening dari Settings plus "Konfirmasi via WhatsApp").',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'kapan-invoice-dibuat',
            question: 'Kapan invoice bulanan dibuat, dan dari mana tanggal jatuh temponya?',
            audience: ['admin'],
            answer: [
                'Invoice dibuat otomatis tiap tanggal 1 pukul 07.00 WIB untuk pelanggan berstatus Aktif atau Isolir yang punya paket; putaran susulan berjalan tiap hari pukul 09.10 WIB untuk pelanggan yang terlewat. Putaran susulan tidak membuat invoice untuk paket Rp0 atau pelanggan tanpa tanggal bergabung. Bulan bergabung tidak pernah ditagih; invoice pertama adalah bulan berikutnya.',
                'Nominal mengikuti harga paket. Tanggal jatuh tempo diambil dari kolom jatuh tempo pelanggan; jika kosong memakai tanggal bergabung, jika masih kosong tanggal 20. Untuk router/area tertentu yang diatur di Settings ("Jatuh tempo tetap pelanggan baru"), pelanggan baru mendapat tanggal tetap (bawaan 25); pengaturannya di Settings, tab Enforcement. Kode invoice: INV-tahunbulan-kodepelanggan-xxxx.',
            ],
            notes: [
                'Jika invoice otomatis dibuat setelah tanggal jatuh tempo normalnya, jatuh temponya menjadi tanggal pembuatan.',
                '"Buat Tagihan" manual mengisi Customer, Billing Period, Amount (harus sama dengan harga paket), dan Due Date.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'catat-pembayaran',
            question: 'Bagaimana mencatat pembayaran satu invoice?',
            audience: ['admin'],
            answer: [
                'Buka detail invoice dan klik "Record Payment".',
            ],
            steps: [
                'Isi "Amount (IDR)"; batas maksimal tampil sebagai "Max payable". Pembayaran sebagian diperbolehkan; invoice menjadi Lunas saat terbayar penuh.',
                'Isi "Payment Date" (tanggal dan jam) dan pilih "Payment Method": Cash, Bank Transfer, QRIS, atau Other. Memilih transfer menampilkan rekening dari Settings.',
                'Lampirkan "Proof of Payment (Image)" bila ada: JPG atau PNG, maksimal 2 MB.',
                'Klik "Save Payment". Muncul "Payment recorded successfully." disertai "Reconnect dijadwalkan." atau "Reconnect tidak dijadwalkan: …".',
            ],
            notes: [
                'Setelah lunas, WA "Pembayaran lunas" berisi tautan kuitansi dikirim ke pelanggan bila Auto-payment WA berstatus LIVE di Settings.',
                'Pesan penolakan: "Nominal pembayaran melebihi sisa invoice: …", "Invoice sudah lunas.", "Invoice yang sudah void tidak dapat menerima pembayaran."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'pembayaran-massal',
            question: 'Bagaimana mencatat pembayaran banyak invoice sekaligus?',
            audience: ['admin'],
            answer: [
                'Di daftar Invoice, centang invoice belum lunas (maksimal 100), pilih "Metode bayar" (Tunai atau Transfer), lalu klik "Catat pembayaran". Dialog "Konfirmasi pembayaran massal" meminta centang "Saya sudah memeriksa pembayaran dan bukti untuk invoice yang dipilih."',
                'Invoice yang sudah punya transaksi dilewati. Hasilnya dilaporkan: jumlah yang dicatat lunas, yang dilewati, dan "Reconnect dijadwalkan: N pelanggan".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'void-vs-delete',
            question: 'Apa beda Void dan Delete pada invoice?',
            audience: ['admin'],
            answer: [
                '"Void" membatalkan invoice yang belum lunas tetapi tetap menyimpannya sebagai riwayat berstatus Batal; alasan boleh diisi. Halaman bayar publik pelanggan menampilkan "Tagihan ini dibatalkan". Hanya invoice Belum Lunas yang bisa di-void ("Only unpaid invoices can be voided.").',
                '"Delete" menghapus permanen dan hanya untuk invoice Belum Lunas atau Batal yang tidak punya pembayaran sama sekali; jika sudah ada pembayaran muncul "Cannot delete an invoice with recorded payments. Void it instead." Mengubah nominal/tanggal (Edit) juga hanya untuk invoice belum lunas tanpa pembayaran.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'koreksi-pembayaran',
            question: 'Pembayaran salah dicatat. Bagaimana membatalkannya?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Di tabel pembayaran pada detail invoice, klik "Koreksi". Dialog "Koreksi payment" meminta Anda mengetik kode invoice, kode atau nama pelanggan, dan "Alasan koreksi" minimal 10 karakter, lalu "Batalkan payment".',
                'Transaksi asli tidak dihapus; aplikasi membuat catatan pembatalan (REV-…) dan menghitung ulang status invoice. Baris pembayaran kemudian berlabel "Dibatalkan". Satu transaksi hanya bisa dikoreksi sekali ("Transaksi ini sudah memiliki reversal."), dan invoice Batal tidak bisa dikoreksi.',
            ],
            notes: [
                'Hanya Admin global, NOC, dan Superadmin yang bisa melakukan koreksi; Admin area tidak.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'bayar-midtrans',
            question: 'Bagaimana pembayaran online lewat Midtrans bekerja, dan di mana melihat kejadian dari payment gateway?',
            audience: ['admin'],
            answer: [
                'Tombol "Bayar Midtrans" di detail invoice membuat tautan pembayaran Snap; pelanggan juga bisa membayar dari halaman bayar publik. Pembayaran dikreditkan otomatis hanya bila Midtrans melaporkan status settlement (atau capture yang lolos pemeriksaan fraud); invoice menjadi Lunas dan buka isolir dijadwalkan.',
                'Menu Payment (khusus Superadmin) membuka halaman pengaturan Payment Gateway: provider aktif, kunci provider, dan kill switch "Payment write". Status yang menandakan pembayaran tidak dikreditkan: rejected_amount (nominal kosong, melebihi sisa tagihan, atau invoice sudah lunas), rejected_void (invoice Batal; dana perlu dikembalikan), rejected_unsigned_status (status dari Midtrans tidak sah), dan unmatched_invoice (invoice tidak ditemukan). duplicate_credit berarti pembayaran yang sama sudah pernah dikreditkan.',
            ],
            links: [{ label: 'Buka Payment', route: 'modules.payment-gateway' }],
            updated: '2026-10-10',
        },
        {
            id: 'bayar-duitku',
            access: 'admin_only',
            question: 'Bagaimana pelanggan membayar online lewat Duitku, dan di mana mengecek transaksinya?',
            audience: ['admin'],
            answer: [
                'Bila Duitku menjadi provider aktif dan kill switch "Payment write" ON, halaman bayar publik (juga tombol "Bayar sekarang" di portal isolir) menampilkan pilihan QRIS, Virtual Account, E-Wallet dan Minimarket beserta biaya layanan. Pelanggan mendapat nomor VA, kode bayar, atau QR; status diperbarui otomatis dan invoice langsung Lunas setelah Duitku mengirim notifikasi. Buka isolir dan kuitansi WA berjalan seperti pembayaran manual.',
                'Invoice dikreditkan sebesar nilai tagihan saja; biaya layanan tidak masuk ke invoice. Bila notifikasi terlewat, sistem menanyakan status ke Duitku secara berkala (jarang, sesuai batas Duitku).',
                'Menu Transaksi Online menampilkan semua percobaan bayar online: Menunggu bayar, Lunas, Perlu dicek, Ditahan (kill switch OFF), Gagal, Kedaluwarsa, dengan tombol "Cek status" untuk transaksi yang masih terbuka. Kotak "Perlu tindakan ops" berisi pembayaran yang sudah diterima Duitku tetapi tidak dikreditkan otomatis (nominal tidak cocok, invoice batal, order tidak dikenal); cek di dashboard Duitku lalu catat manual. Pembayaran ganda Duitku (invoice sudah lunas) dan kelebihan bayar tidak masuk kotak ini, melainkan menu Kelebihan Bayar.',
            ],
            notes: [
                'Kredensial Duitku, metode, penanggung biaya, dan whitelist IP callback diatur Superadmin di Settings, Payment Gateway. Menyimpan pengaturan butuh verifikasi TOTP.',
            ],
            links: [{ label: 'Buka Transaksi Online', route: 'payment-gateway.transactions' }],
            updated: '2026-10-10',
        },
        {
            id: 'kelebihan-bayar',
            access: 'admin_only',
            question: 'Pelanggan membayar dua kali lewat Duitku atau membayar lebih dari sisa tagihan. Apa yang terjadi dan apa tindakan admin?',
            audience: ['admin'],
            answer: [
                'Pembayaran pertama melunasi tagihan seperti biasa dan kuitansi tidak berubah. Pembayaran kedua penuh untuk tagihan yang sudah lunas dicatat otomatis sebagai saldo pelanggan (status Tersedia) di menu Kelebihan Bayar. Tidak ada refund dan pelanggan tidak mendapat WA khusus untuk pembayaran kedua.',
                'Bila kode bayar lama bernilai lebih besar dari sisa tagihan (misalnya setelah ada bayar sebagian manual), tagihan tetap dilunasi supaya pelanggan tidak terisolir, dan kelebihannya dicatat dengan status Perlu cek admin. Admin memilih: Jadikan saldo, Sudah direfund manual, atau Abaikan (alasan wajib).',
                'Untuk saldo Tersedia, tombol Terapkan memotong tagihan belum lunas milik pelanggan yang sama sampai sisa tagihan. Terapan sebagian menyisakan saldo. Semua tindakan tercatat di log aktivitas. Menu ini hanya untuk Superadmin dan admin tanpa batas area.',
                'Rekonsiliasi dengan dashboard Duitku: total dana masuk Duitku = transaksi gateway di eBilling + saldo di Kelebihan Bayar (pembayaran ganda dan kelebihan). Saldo yang diterapkan muncul sebagai pembayaran berlabel "Saldo" pada tagihan tujuan (dicatat sebagai pembayaran manual oleh admin yang menerapkan). Jika pembayaran saldo itu dibatalkan lewat Batalkan pembayaran, nominalnya kembali menjadi saldo Tersedia.',
            ],
            links: [{ label: 'Buka Kelebihan Bayar', route: 'customer-credits.index' }],
            updated: '2026-10-16',
        },
        {
            id: 'saldo-potong-otomatis',
            access: 'admin_only',
            question: 'Pelanggan punya saldo kelebihan bayar. Apakah tagihan bulanan otomatis terpotong?',
            audience: ['admin'],
            answer: [
                'Ya, bila pengaturan potong saldo otomatis aktif (saat ini aktif; efek pertama pada tagihan otomatis 1 November). Hanya pelanggan berstatus Aktif atau Isolir yang terkena. Saat invoice bulanan dibuat otomatis, saldo Tersedia milik pelanggan dipotong sendiri, saldo paling lama lebih dulu. Bila saldo cukup, invoice langsung Lunas; bila kurang, invoice menampilkan sisa yang masih harus dibayar.',
                'Proses catch-up harian juga memotong saldo yang baru muncul setelah invoice terbit, hanya untuk invoice otomatis bulan berjalan. Invoice buatan admin, hasil impor, dan tunggakan lama tidak disentuh; untuk invoice itu gunakan tombol Terapkan di halaman Kelebihan Bayar.',
                'Kuitansi PDF memuat baris "Saldo (kelebihan bayar): Rp X". Pelanggan tidak menerima WA untuk pemotongan ini.',
            ],
            notes: [
                'Bila admin membatalkan pembayaran saldo, saldo kembali menjadi Tersedia dan tidak dipotongkan lagi otomatis ke invoice yang sama; terapkan ulang manual bila perlu.',
            ],
            links: [{ label: 'Buka Kelebihan Bayar', route: 'customer-credits.index' }],
            updated: '2026-10-16',
        },
    ],
};
