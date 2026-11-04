import { TicketCheck } from 'lucide-react';
import type { FaqCategory } from '../types';

export const tiket: FaqCategory = {
    slug: 'tiket',
    title: 'Ticketing',
    description: 'Tiket Gangguan, Pasang Baru, Tagihan, Request, Dismantle, dan Lainnya: dari dibuat, ditugaskan, dikerjakan teknisi, sampai ditutup.',
    icon: TicketCheck,
    access: 'auth',
    routes: ['tickets.index'],
    items: [
        {
            id: 'hanya-tiket-saya',
            question: 'Mengapa saya hanya melihat sebagian tiket?',
            images: [
                { src: '/images/faq/hp-tiket-daftar.webp', alt: 'Halaman Ticketing di HP untuk akun Teknisi dengan tombol Aktif dan Arsip serta hitungan status', caption: 'Ticketing di HP: tombol "Aktif" / "Arsip" dan ringkasan status tiket Anda.' },
            ],
            audience: ['teknisi'],
            answer: [
                'Akun Teknisi hanya menampilkan tiket yang ditugaskan kepada Anda. Tiket milik teknisi lain tidak muncul di daftar, dan jika dibuka lewat tautan langsung akan ditolak (403).',
                'Daftar punya dua tampilan: "Aktif" untuk tiket yang masih berjalan dan "Arsip" untuk tiket berstatus Resolved, Closed, atau dibatalkan.',
                'Jika ada tiket yang seharusnya Anda kerjakan tetapi tidak muncul, minta admin menugaskannya ke akun Anda lewat tombol Assign di tiket tersebut.',
            ],
            links: [{ label: 'Buka Ticketing', route: 'tickets.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'update-progres',
            question: 'Bagaimana cara mengisi update pekerjaan di tiket?',
            images: [
                { src: '/images/faq/hp-tiket-detail.webp', alt: 'Bagian atas halaman detail tiket di HP untuk akun Teknisi: kode tiket, status, sisa waktu deadline, info pelanggan, teknisi, dan timeline', caption: 'Detail tiket di HP. Gulir ke bawah untuk kartu "Input Teknisi".' },
            ],
            audience: ['teknisi'],
            answer: [
                'Buka tiketnya, lalu isi kartu "Input Teknisi". Satu kali simpan boleh berisi catatan saja, foto saja, atau keduanya.',
            ],
            steps: [
                'Isi "Kendala": apa yang Anda temukan di lokasi.',
                'Isi "Tindakan": apa yang sudah dikerjakan. "Kendala" dan "Tindakan" masing-masing maksimal 2000 karakter.',
                'Klik "Pilih Foto" pada "Foto Bukti". Maksimal 5 foto, masing-masing 5 MB, format JPG, PNG, atau WebP.',
                'Pilih "Update Status" bila pekerjaan berubah tahap: Proses, Pending, Resolved, atau Closed. Status yang sedang berlaku ditandai "(saat ini)".',
                'Klik "Simpan Update". Jika berhasil muncul "Update berhasil disimpan."',
            ],
            notes: [
                'Pilihan Open dan Assigned tetap tampil di "Update Status", tetapi jika dipilih simpan akan ditolak.',
                'Tiket Dismantle tidak bisa diubah ke Closed sebelum perangkat diterima gudang di Pengambilan Perangkat. Gunakan Resolved dulu.',
                'Catatan untuk sesama staf ditulis di "Internal Comments" (tombol "Kirim"); pelanggan tidak melihatnya.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'status-dan-sla',
            question: 'Apa arti status tiket dan label SLA?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Status: "Open" baru dibuat dan belum ada teknisi; "Assigned" sudah ditugaskan; "Proses" sedang dikerjakan; "Pending" menunggu sesuatu (sparepart, pelanggan, akses); "Resolved" selesai di lapangan; "Closed" tiket ditutup.',
                'Batas waktu (deadline) dihitung dari prioritas jika tidak diisi saat membuat tiket: Urgent 4 jam, High 12 jam, Normal 24 jam, Low 72 jam.',
                'Label SLA di daftar: "Dalam SLA", "Mendekati SLA" (deadline 24 jam lagi atau kurang), "Lewat SLA", "Selesai" (Resolved/Closed), atau "Tanpa SLA". Di detail tiket, badge deadline menampilkan sisa waktu, "Hampir deadline", atau "OVERDUE".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'hubungi-pelanggan',
            question: 'Bagaimana menghubungi pelanggan atau menuju lokasinya dari tiket?',
            audience: ['teknisi'],
            answer: [
                'Di kartu "Info Pelanggan", nomor telepon bisa langsung ditekan untuk menelepon dari HP. Jika tiket punya koordinat, tekan koordinatnya untuk membuka Google Maps.',
                'Aplikasi tidak mengirim WA apa pun ke pelanggan dari tiket; semua notifikasi otomatis hanya ke grup staf dan teknisi.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'dismantle-di-lokasi',
            question: 'Saya dapat tiket Dismantle. Apa yang harus dilakukan?',
            audience: ['teknisi'],
            answer: [
                'Tiket Dismantle berarti layanan pelanggan dihentikan dan perangkat (ONU/router) diambil. Saat Admin, NOC, atau Superadmin menyimpan tiket Dismantle yang terhubung ke pelanggan, aplikasi otomatis membuat kasus di Pengambilan Perangkat dan menugaskannya kepada Anda sebagai teknisi tiket.',
            ],
            steps: [
                'Ambil perangkat di lokasi, lalu buka menu Pengambilan Perangkat dan isi "Catat pengambilan perangkat" (SN, kondisi, foto label SN, foto perangkat utuh).',
                'Kembali ke tiket, isi "Input Teknisi" dan ubah status ke Resolved.',
                'Serahkan perangkat ke gudang. Admin yang mencatat "Diterima gudang"; setelah itu tiket dismantle ditutup otomatis, baik yang masih terbuka maupun yang sudah Resolved. Hal yang sama berlaku saat exception disetujui atau admin memakai catat cepat.',
            ],
            notes: [
                'Tiket Dismantle tidak bisa Anda ubah ke Closed. Pesan yang muncul: "Tiket dismantle belum dapat ditutup: perangkat belum diterima gudang atau exception belum diselesaikan."',
            ],
            links: [{ label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'buat-dan-tugaskan',
            question: 'Bagaimana membuat tiket dan menugaskan teknisi?',
            images: [
                { src: '/images/faq/tiket-daftar.webp', alt: 'Halaman Ticketing untuk admin dengan tombol Buat Tiket, Pengambilan Perangkat, dan Alasan Dismantle', caption: 'Ticketing untuk admin: "Buat Tiket", "Pengambilan Perangkat", "Alasan Dismantle".' },
            ],
            audience: ['admin'],
            answer: [
                'Semua akun bisa membuat tiket dan memilih teknisi di form. Tombol Assign di halaman tiket, "Hapus Tiket", dan kartu "Lifecycle Pelanggan" hanya tersedia untuk Admin, NOC, dan Superadmin.',
            ],
            steps: [
                'Klik "Buat Tiket", isi "Gangguan / Kendala" (wajib), lalu pilih "Tipe": Gangguan, Pasang Baru, Tagihan, Request, "Dismantle (ambil perangkat)", atau Lainnya.',
                'Pilih "Prioritas" (Low, Normal, High, Urgent). "Deadline / SLA" boleh dikosongkan; aplikasi mengisinya dari prioritas.',
                'Pilih teknisi di kartu "Assign Teknisi". Tiket langsung berstatus "Assigned" dan teknisi menerima WA pribadi.',
                'Untuk tipe Dismantle, "Alasan dismantle" wajib dipilih; bila memilih "Lainnya", keterangan wajib diisi (maksimal 500 karakter).',
            ],
            notes: [
                'Jika WA ke teknisi gagal, tiket tetap tersimpan dan muncul peringatan "Tiket tersimpan, tetapi notifikasi WA teknisi belum terkirim. Periksa nomor teknisi dan status gateway." Gunakan "Kirim Notifikasi WA Manual" di halaman tiket.',
                'Kode tiket dibuat otomatis dengan format TCK-tanggal-xxxxx.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'notifikasi-wa-tiket',
            question: 'Notifikasi WA apa saja yang dikirim otomatis dari tiket?',
            audience: ['admin'],
            answer: [
                'Semua notifikasi tiket ditujukan ke staf, bukan ke pelanggan. Pengirimnya adalah nomor WA "Ticket & Progress" yang dipasang di WA Gateway, dan grupnya diatur di Settings, tab WhatsApp Gateway ("Grup Tiket" dan "Grup Laporan"). Menu WA Gateway dan Settings hanya untuk Superadmin.',
            ],
            steps: [
                'Tiket dibuat: pesan ke grup tiket.',
                'Tiket ditugaskan: WA pribadi ke teknisi. Jika dipindah ke teknisi lain, teknisi lama ikut diberi tahu.',
                'Status berubah (selain Resolved/Closed): ke grup dan ke teknisi.',
                'Resolved/Closed: ke grup, beserta laporan penutupan.',
            ],
            notes: [
                'Tiket Dismantle yang tertutup otomatis karena perangkat diterima gudang tidak mengirim WA.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'lifecycle-pelanggan',
            question: 'Apa fungsi kartu "Lifecycle Pelanggan" dan tombol "Ajukan Approval"?',
            images: [
                { src: '/images/faq/tiket-detail.webp', alt: 'Halaman detail tiket untuk admin', caption: 'Detail tiket untuk admin; kartu "Lifecycle Pelanggan" tampil bila tiket terhubung ke pelanggan.' },
            ],
            audience: ['admin'],
            answer: [
                'Kartu ini muncul pada tiket yang terhubung ke pelanggan. Pilihannya: Isolir, Reconnect, Dismantle, dan Pindah ODP ("ODP Baru" wajib, "Alamat Baru" opsional). Isi "Alasan" minimal 5 karakter lalu klik "Ajukan Approval"; admin lain (atau Anda sendiri) menekan "Approve" pada daftar yang menunggu.',
                'Approval hanya mengubah data pelanggan di database dan mencatat audit: Isolir menjadi "Isolir", Reconnect menjadi "Aktif", Dismantle menjadi "Berhenti", Pindah ODP mengganti ODP (dan alamat bila diisi). Tidak ada perintah yang dikirim ke MikroTik atau OLT. Untuk memutus koneksi sungguhan gunakan tombol "Isolir layanan" di halaman Pelanggan.',
            ],
            notes: [
                'Dismantle hanya bisa di-approve setelah tiket Resolved atau Closed: "Dismantle hanya boleh di-apply setelah ticket resolved/closed."',
                'Approve Dismantle juga membuat kasus Pengambilan Perangkat untuk tiket itu bila belum ada.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'dismantle-ke-pengambilan',
            question: 'Tiket Dismantle otomatis membuat kasus Pengambilan Perangkat?',
            audience: ['admin'],
            answer: [
                'Ya. Saat Admin, NOC, atau Superadmin menyimpan tiket bertipe Dismantle yang terhubung ke pelanggan, aplikasi membuat satu kasus Pengambilan Perangkat berstatus "Menunggu penugasan" dan menampilkan "(masuk Pengambilan Perangkat)" pada pesan sukses. Jika teknisi tiket adalah akun Teknisi (bukan Admin/NOC), kasus langsung ditugaskan kepadanya, dan tetap mengikuti setiap kali teknisi tiket diganti selama pengambilan belum dimulai. Tiket lain yang diubah tipenya menjadi Dismantle juga dibuatkan kasus.',
                'Tiket Dismantle yang dibuat oleh akun Teknisi tidak membuat kasus; admin membuatnya lewat tombol "Buat Pengambilan" di halaman tiket. Tiket harus terhubung ke pelanggan; tanpa pelanggan kasus tidak bisa dibuat.',
                'Pilihan alasan: "Tidak lanjut berlangganan", "Pindah rumah", "Pindah ke provider lain", "Tunggakan / tidak bayar", "Gangguan tidak terselesaikan", "Perangkat ganda (salah satu dicabut)", "Lainnya". Rekapnya ada di laporan alasan dismantle.',
            ],
            links: [
                { label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' },
                { label: 'Laporan alasan dismantle', route: 'tickets.dismantle-reasons' },
            ],
            updated: '2026-10-10',
        },
        {
            id: 'dismantle-tidak-bisa-ditutup',
            question: 'Kenapa tiket Dismantle tidak bisa ditutup?',
            audience: ['admin'],
            answer: [
                'Status Closed pada tiket Dismantle dikunci sampai kasus Pengambilan Perangkat-nya selesai: perangkat sudah "Diterima gudang", atau exception-nya sudah disetujui. Pesannya: "Tiket dismantle belum dapat ditutup: perangkat belum diterima gudang atau exception belum diselesaikan."',
                'Begitu gudang menerima perangkat (atau exception disetujui), tiket dismantle yang terbuka maupun yang sudah Resolved ditutup otomatis. Hal yang sama berlaku saat admin memakai catat cepat. Tidak perlu menutup manual.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'teknisi-403',
            question: 'Teknisi melapor tidak bisa membuka tiket (ditolak / 403). Apa penyebabnya?',
            audience: ['admin'],
            answer: [
                'Tiket itu belum ditugaskan ke akunnya. Teknisi hanya bisa membuka, mengubah, mengomentari, dan mengisi "Input Teknisi" pada tiket yang kolom teknisinya adalah dirinya. Buka tiketnya, pilih teknisi yang benar lewat Assign, lalu minta teknisi memuat ulang halaman.',
            ],
            updated: '2026-10-10',
        },
    ],
};
