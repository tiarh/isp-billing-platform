import { PackageCheck } from 'lucide-react';
import type { FaqCategory } from '../types';

export const pengambilanPerangkat: FaqCategory = {
    slug: 'pengambilan-perangkat',
    title: 'Pengambilan Perangkat',
    description: 'Kasus pengambilan ONU/router dari tiket Dismantle: pencatatan oleh teknisi, penerimaan gudang dengan cek SN, dan penanganan exception.',
    icon: PackageCheck,
    access: 'auth',
    routes: ['device-recovery.index'],
    items: [
        {
            id: 'dari-mana-kasus',
            question: 'Dari mana kasus pengambilan perangkat muncul, dan kenapa ada di daftar saya?',
            images: [
                { src: '/images/faq/hp-pengambilan.webp', alt: 'Halaman Pengambilan Perangkat di HP dengan ringkasan Total, Berjalan, Perlu tinjauan, kolom cari, filter status, dan daftar kasus', caption: 'Pengambilan Perangkat di HP: ringkasan, pencarian, filter, dan kartu kasus.' },
            ],
            audience: ['teknisi'],
            answer: [
                'Setiap tiket Dismantle yang disimpan admin otomatis menjadi satu kasus di sini. Kasus ditugaskan ke teknisi yang tertulis di tiket; jika admin mengganti teknisi tiket sebelum pengambilan dimulai, kasus ikut berpindah.',
                'Saat kasus ditugaskan ke Anda, Anda menerima WA berisi SN yang harus diambil dan tautan ke kasusnya. Bila penugasan terjadi bersamaan dengan penugasan tiket, info ini ikut di WA tiket sehingga Anda hanya menerima satu pesan.',
                'Anda hanya melihat kasus yang ditugaskan ke Anda. Kasus milik teknisi lain ditolak (403) walaupun tautannya dibuka langsung.',
            ],
            links: [{ label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'tampilan-hp',
            question: 'Bagaimana memakai halaman Pengambilan Perangkat di HP?',
            images: [
                { src: '/images/faq/hp-pengambilan.webp', alt: 'Pengambilan Perangkat di HP: tombol Lapor pengambilan tanpa tiket, ringkasan Total, Berjalan, Perlu tinjauan, kolom cari, chip status, kartu kasus, dan bilah bawah Tiket dan Cari SN', caption: 'Tampilan HP: ringkasan, pencarian, filter, kartu kasus, dan bilah bawah.' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                'Di layar HP, halaman Pengambilan Perangkat dibuat ringkas untuk dipakai di lapangan. Bagian atas berisi tiga kotak ringkasan: "Total" (semua kasus yang sesuai pencarian dan filter Anda), "Berjalan" (kasus yang sudah ditugaskan atau dijadwalkan sampai perangkat dibawa teknisi), dan "Perlu tinjauan" (kasus Exception, termasuk Exception disetujui). Angka ini tidak berubah saat Anda mengetuk filter status.',
                'Di bawahnya ada kolom "Cari tiket, pelanggan, atau SN", tombol filter (ikon penggeser) untuk memilih area, lalu deretan chip status fisik di atas daftar. Setiap kasus tampil sebagai kartu berisi kode tiket, nama pelanggan, status, SN perangkat, teknisi dan area, serta alasan dismantle. Ketuk kartunya untuk membuka rincian dan form pencatatan.',
            ],
            steps: [
                'Buka Ticketing dari menu geser, lalu ketuk tombol "Pengambilan Perangkat" di bilah bawah (atau pakai tautan "Buka Pengambilan Perangkat" di bawah jawaban ini).',
                'Ketuk tombol "Cari SN" di bilah bawah untuk langsung mengetik SN, atau pakai kolom cari untuk tiket dan nama pelanggan.',
                'Ketuk chip status (misalnya "Menunggu penugasan") untuk menyaring, atau tombol filter untuk memilih area, lalu "Terapkan".',
                'Ketuk kartu kasus untuk membuka rincian, lalu catat pengambilan seperti biasa.',
            ],
            notes: [
                'Bila perangkat sudah terambil tetapi belum ada tiket, tombol "Lapor pengambilan tanpa tiket" ada di bagian atas halaman. Tombol "Tiket" di bilah bawah membawa Anda kembali ke daftar tiket.',
                'Akun Teknisi hanya melihat kasus yang ditugaskan kepadanya, sehingga angka ringkasan juga hanya menghitung kasus Anda. Admin dan NOC melihat kasus sesuai hak area mereka dan memiliki pilihan filter teknisi.',
            ],
            links: [{ label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' }],
            updated: '2026-10-12',
        },
        {
            id: 'catat-pengambilan',
            question: 'Bagaimana mencatat perangkat yang sudah saya ambil?',
            audience: ['teknisi'],
            answer: [
                'Buka kasusnya, lalu isi kartu "Catat pengambilan perangkat". Tidak ada lagi langkah "Mulai pengambilan" terpisah; semuanya dalam satu form dan satu kali simpan.',
            ],
            steps: [
                'Ketik SN perangkat sesuai label (wajib untuk kondisi "Baik dan SN cocok").',
                'Pilih "Kondisi": "Baik dan SN cocok", "Rusak", "SN tidak terbaca", atau "Hilang / tidak ada di lokasi".',
                'Unggah "Foto label SN" minimal 1 (maksimal 2) dan "Foto perangkat utuh" minimal 1 (maksimal 3). Kamera HP terbuka langsung; foto bisa dilihat ulang dan dihapus sebelum dikirim.',
                'Isi "Catatan" bila ada yang perlu diketahui gudang.',
                'Klik "Simpan hasil pengambilan". Kasus berpindah ke status "Dibawa teknisi".',
            ],
            notes: [
                'Format foto JPG, PNG, atau WEBP, maksimal 5 MB per foto dan 5 foto per kasus. Foto dikompres otomatis di HP sebelum dikirim.',
                'Pesan jika foto kurang: "Foto label SN wajib diunggah minimal 1." atau "Foto perangkat utuh wajib diunggah minimal 1."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'rusak-hilang-sn',
            question: 'Perangkat rusak, hilang, atau SN-nya tidak terbaca. Bagaimana mencatatnya?',
            audience: ['teknisi'],
            answer: [
                'Pilih kondisi yang sesuai di form yang sama. Kasus dengan kondisi selain "Baik dan SN cocok" otomatis masuk status "Exception" dan akan diputuskan oleh admin.',
            ],
            steps: [
                '"Hilang / tidak ada di lokasi": foto boleh kosong, tetapi "Catatan" wajib minimal 5 karakter (misalnya siapa yang Anda temui dan apa penjelasannya).',
                '"Rusak" atau "SN tidak terbaca": tetap wajib kedua jenis foto dan catatan.',
            ],
            notes: [
                'Kasus exception tidak menerminasi pelanggan secara otomatis, juga setelah exception disetujui. Tiket Dismantle baru tertutup setelah admin menyetujui exception.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'upload-gagal',
            question: 'Upload gagal karena sinyal lemah. Apakah data saya hilang?',
            audience: ['teknisi'],
            answer: [
                'Tidak. Form menampilkan "Upload gagal (koneksi terputus?). Data dan foto masih ada di form, silakan kirim ulang." Pindah ke tempat bersinyal lebih baik lalu tekan simpan lagi; SN, kondisi, catatan, dan foto tetap terisi.',
                'Jangan menutup atau memuat ulang halaman sebelum berhasil tersimpan, karena isian hanya tersimpan di layar itu.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'status-kasus',
            question: 'Apa arti status kasus pengambilan perangkat?',
            audience: ['teknisi', 'admin'],
            answer: [
                '"Menunggu penugasan": kasus dibuat, belum ada teknisi. "Ditugaskan" / "Dijadwalkan": teknisi sudah ada, perangkat belum diambil. "Dibawa teknisi": teknisi sudah mencatat pengambilan dan membawa perangkat. "Diterima gudang": gudang sudah mengecek SN dan foto; pelanggan diterminasi dan tiket Dismantle tertutup otomatis. "Exception": ada ketidaksesuaian (rusak, hilang, SN beda) yang menunggu keputusan. "Exception disetujui": keputusan sudah diambil. "Dibatalkan": kasus dihentikan. "Dipakai ulang": ONU-nya sudah dipasang ke pelanggan lain.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'terima-gudang',
            question: 'Bagaimana mencatat "Diterima gudang"?',
            images: [
                { src: '/images/faq/pengambilan-daftar.webp', alt: 'Daftar pengambilan perangkat dengan kolom Tiket, Pelanggan, Status fisik, Perangkat / SN, Teknisi, Area', caption: '"Daftar pengambilan": status fisik tiap kasus; klik baris untuk membuka form penerimaan.' },
            ],
            audience: ['admin'],
            answer: [
                'Penerimaan gudang hanya bisa dilakukan pada kasus berstatus "Dibawa teknisi" dan membutuhkan bukti fisik: SN tiap perangkat dan foto penerimaan.',
            ],
            steps: [
                'Buka kasus, pada bagian penerimaan ketik atau scan SN fisik setiap perangkat. Di samping kolom tampil SN yang dicatat teknisi dan indikator langsung "SN cocok." atau "SN tidak cocok dengan catatan teknisi."',
                'Unggah "Foto penerimaan gudang" minimal 1 (maksimal 3).',
                'Simpan. Kasus menjadi "Diterima gudang".',
            ],
            notes: [
                'Pencocokan SN mengabaikan huruf besar/kecil dan tanda baca; SN GPON seperti FHTT… juga cocok dengan bentuk heksadesimalnya.',
                'Efek penerimaan: pelanggan berstatus "Berhenti" (kecuali kasus ditandai tetap aktif), tiket Dismantle tertutup otomatis tanpa WA, dan ONU dibersihkan dari OLT.',
                'Tiket Dismantle yang berstatus Resolved juga ikut ditutup otomatis; tiket yang sudah Closed atau Cancelled tidak diubah.',
                'Pesan bila belum lengkap: "SN fisik perangkat wajib diketik atau di-scan.", "Foto penerimaan gudang wajib diunggah minimal 1.", "Perangkat belum berada pada status dibawa teknisi."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'sn-tidak-cocok',
            question: 'SN di gudang tidak cocok dengan catatan teknisi. Apa yang terjadi?',
            audience: ['admin'],
            answer: [
                'Penerimaan ditolak dan tidak ada yang berubah. Pesannya: "SN fisik (X) tidak cocok dengan SN yang dicatat teknisi (Y). Periksa ulang, atau ajukan sebagai exception."',
                'Periksa dulu label fisik dan kemungkinan salah ketik. Jika memang berbeda, centang "SN memang berbeda: ajukan sebagai exception" lalu simpan. Kasus berpindah ke "Exception" dengan alasan "SN gudang tidak cocok", pelanggan tidak diterminasi, dan tiket tetap terbuka sampai exception diputuskan.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'catat-langsung',
            question: 'Kapan memakai "Sudah diambil, catat langsung"?',
            audience: ['admin'],
            answer: [
                'Untuk perangkat yang sudah dibawa teknisi ke gudang tanpa dicatat di aplikasi. Kartu ini hanya tampil pada kasus berstatus "Menunggu penugasan", "Ditugaskan", atau "Dijadwalkan" yang berisi tepat satu perangkat.',
            ],
            steps: [
                'Pilih teknisi yang mengambil perangkat.',
                'Ketik SN; SN yang diharapkan tampil beserta indikator cocok/tidak.',
                'Pilih "Kondisi", isi "Catatan", dan unggah foto dengan syarat yang sama seperti form teknisi (label SN dan perangkat utuh).',
                'Centang "Saya konfirmasi perangkat ini sudah diterima di gudang." lalu klik "Simpan & terima gudang".',
            ],
            notes: [
                'Kasus langsung menjadi "Diterima gudang" dengan efek yang sama seperti penerimaan normal (terminasi pelanggan, tiket tertutup, OLT dibersihkan). Jika SN berbeda atau kondisi tidak baik, kasus menjadi "Exception".',
                'Pesan bila tidak memenuhi syarat: "Catat langsung hanya untuk case dengan 1 perangkat; gunakan form teknisi untuk multi-perangkat." dan "Pilih teknisi yang mengambil perangkat."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'exception',
            question: 'Siapa yang menyelesaikan kasus "Exception", dan apa pilihannya?',
            audience: ['admin'],
            answer: [
                'Kartu "Tinjauan exception" hanya tampil untuk Admin global (admin tanpa batasan area), NOC, atau Superadmin; Admin area tidak bisa memutuskan exception. Isi "Alasan keputusan" minimal 5 karakter, lalu pilih salah satu:',
                '"Setujui exception untuk closing": kasus menjadi "Exception disetujui" dan tiket Dismantle tertutup otomatis. Status pelanggan tidak diubah otomatis. Semua perangkat di kasus harus sudah punya hasil pengambilan.',
                '"Buka ulang pengambilan": hasil pengambilan dihapus dan kasus kembali ke "Ditugaskan" (atau "Menunggu penugasan" bila belum ada teknisi), sehingga teknisi mencatat ulang.',
                'Bila kasus memang tidak perlu dilanjutkan, gunakan kartu "Batalkan case" (Admin, NOC, Superadmin; wajib alasan). Riwayat kasus tetap tersimpan.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'pelanggan-tetap-aktif',
            question: 'Pelanggan masih berlangganan, hanya perangkat tambahan yang diambil. Bagaimana agar tidak ikut diterminasi?',
            audience: ['admin'],
            answer: [
                'Di kasus tersebut, kartu "Status pelanggan setelah diterima gudang" menjelaskan apa yang akan terjadi. Klik "Pelanggan tetap aktif" sebelum perangkat diterima gudang; saat diterima, pelanggan tidak diterminasi, sementara tiket Dismantle tetap tertutup otomatis.',
                'Untuk membatalkan pilihan itu, klik "Terminate pelanggan saat diterima". Tombol ini bisa dipakai Admin, NOC, dan Superadmin selama kasus belum "Diterima gudang" atau "Exception disetujui".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'tugaskan-ulang',
            question: 'Bagaimana menugaskan atau menjadwalkan ulang teknisi?',
            audience: ['admin'],
            answer: [
                'Di kasus, kartu "Penugasan": pilih teknisi, isi jadwal bila perlu, lalu klik "Simpan penugasan". Teknisi baru menerima WA. Selama pengambilan belum dimulai, kasus juga mengikuti teknisi tiket secara otomatis, jadi cukup ganti teknisi di tiketnya. Perubahan tercatat di riwayat kasus dengan keterangan "Otomatis mengikuti teknisi tiket".',
                'Admin area hanya bisa menugaskan kasus di areanya sendiri.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'inventaris-perangkat-kembali',
            question: 'Di mana melihat rekap perangkat yang sudah kembali?',
            audience: ['admin'],
            answer: [
                'Menu Inventaris, tab "Perangkat kembali", menampilkan status kasus pengambilan. Penugasan ke teknisi bukan bukti perangkat sudah diterima gudang; yang menjadi bukti adalah status "Diterima gudang".',
            ],
            links: [{ label: 'Buka Inventaris', route: 'inventaris.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'sop-ambil-tanpa-tiket',
            question: 'Teknisi sudah membawa perangkat ke kantor, tetapi belum ada tiket. Apa yang dilakukan admin?',
            audience: ['admin'],
            answer: [
                'Aturan resmi: teknisi tidak mengambil perangkat tanpa tiket, kecuali darurat. Untuk keadaan darurat ada dua jalan: teknisi menyerahkan perangkat ke admin dan admin mencatatnya dengan langkah di bawah, atau teknisi melapor sendiri lewat "Lapor pengambilan tanpa tiket".',
                'Bila teknisi sudah melapor sendiri, kasusnya sudah berstatus "Dibawa teknisi". Admin cukup memakai kartu "Penerimaan gudang" ("Tandai diterima gudang"); langkah di bawah tidak perlu.',
            ],
            steps: [
                'Terima perangkat dari teknisi dan tanyakan nama pelanggannya.',
                'Ticketing, "Buat Tiket": pilih pelanggan, jenis Dismantle, isi alasan dismantle. Kolom teknisi boleh dikosongkan supaya teknisi tidak menerima WA penugasan; WA grup "Tiket baru" tetap terkirim.',
                'Buka Pengambilan Perangkat, lalu kasus pelanggan tersebut.',
                'Bila pelanggan tetap berlangganan (ganti perangkat, upgrade, atau hanya perangkat tambahan yang diambil), klik "Pelanggan tetap aktif" lebih dulu.',
                'Buka kartu "Sudah diambil, catat langsung": pilih teknisi yang mengambil, ketik atau scan SN, pilih kondisi, unggah foto label SN dan perangkat utuh, centang konfirmasi, lalu klik "Simpan & terima gudang".',
            ],
            notes: [
                'Teknisi yang dipilih di "Sudah diambil, catat langsung" juga otomatis menjadi teknisi tiket Dismantle (tercatat di riwayat tiket), tanpa WA penugasan.',
                'Bila SN berbeda dari SN pelanggan, isi catatan; kasus masuk "Exception" dan diputuskan Admin global, NOC, atau Superadmin.',
                'Perangkat yang menumpuk di kantor tanpa catatan: inventarisasi rutin dan jalankan langkah di atas untuk setiap perangkat.',
            ],
            links: [{ label: 'Buat tiket', route: 'tickets.create' }, { label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'lapor-tanpa-tiket',
            question: 'Saya sudah mengambil perangkat, tetapi admin belum membuat tiket. Bagaimana melapor?',
            audience: ['teknisi'],
            answer: [
                'Hanya untuk keadaan darurat; aturan normalnya perangkat diambil setelah ada tiket Dismantle. Laporan membuat tiket Dismantle atas nama Anda dan kasus berstatus "Dibawa teknisi". Anda tidak menerima WA penugasan; grup WA tiket menerima pesan "Tiket baru" yang meminta admin mengonfirmasi penerimaan.',
            ],
            steps: [
                'Buka Pengambilan Perangkat, klik "Lapor pengambilan tanpa tiket".',
                'Di "Pelanggan", ketik minimal 3 huruf nama, kode, PPPoE, atau SN, lalu pilih pelanggannya. SN pelanggan yang tercatat ikut ditampilkan.',
                'Isi "SN perangkat" dan pilih "Kondisi". Bila SN tidak cocok, kondisi tidak baik, atau perangkat hilang, isi "Catatan" (minimal 5 karakter).',
                'Isi "Alasan darurat (kenapa tanpa tiket)", minimal 10 karakter.',
                'Unggah "Foto label SN" dan "Foto perangkat utuh" (boleh kosong hanya untuk kondisi hilang), lalu klik "Kirim laporan".',
                'Serahkan perangkat ke admin. Admin mencatat "Diterima gudang"; setelah itu tiket tertutup otomatis.',
            ],
            notes: [
                'SN yang berbeda dari SN pelanggan, perangkat rusak, SN tidak terbaca, atau hilang membuat kasus masuk "Exception" untuk ditinjau admin.',
                'Laporan ditolak bila pelanggan masih punya kasus pengambilan yang berjalan ("Pelanggan ini sudah punya Pengambilan Perangkat yang masih berjalan ...") atau perangkatnya sudah tercatat dibawa/diterima gudang dalam 30 hari terakhir. Hubungi admin.',
                'Batas: 10 laporan per jam per akun. Laporan yang terkirim ulang karena sinyal lemah tidak membuat tiket ganda.',
            ],
            links: [{ label: 'Buka Pengambilan Perangkat', route: 'device-recovery.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'tiket-tertaut-otomatis',
            question: 'Kenapa tiket Dismantle yang baru saya buat langsung tertutup dan tidak masuk Pengambilan Perangkat?',
            audience: ['admin'],
            answer: [
                'Perangkat pelanggan itu sudah tercatat "Diterima gudang" atau "Dibawa teknisi" dalam 30 hari terakhir (atau SN ONU pelanggan sudah diterima gudang di kasus lain). Agar tidak ada pengambilan ganda, tiket baru ditautkan ke kasus yang sudah ada dan ditutup otomatis.',
                'Riwayat tiket mencatat "Perangkat pelanggan sudah diterima gudang" atau "sedang dibawa teknisi" beserta nomor kasus dan kode tiket asal. Tombol "Lihat Pengambilan (tertaut)" di halaman tiket membuka kasus tersebut.',
            ],
            notes: [
                'Tidak ada kasus baru dan teknisi tidak menerima WA penugasan. WA grup "Tiket baru" tetap terkirim.',
                'Pemasangan ulang yang dicabut lagi lebih dari 30 hari kemudian tetap membuat kasus baru seperti biasa.',
                'Kasus lain yang masih menunggu atau sedang ditugaskan tidak menghalangi tiket baru.',
            ],
            updated: '2026-10-10',
        },
    ],
};
