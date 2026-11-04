import { Activity } from 'lucide-react';
import type { FaqCategory } from '../types';

export const monitoring: FaqCategory = {
    slug: 'monitoring',
    title: 'Monitoring, Mapping & Inventaris',
    description: 'Status router/OLT/pelanggan dari snapshot, peta ODP/pelanggan, dan aset jaringan di Inventaris.',
    icon: Activity,
    access: 'auth',
    routes: ['monitoring.index', 'peta.index', 'inventaris.index'],
    items: [
        {
            id: 'monitoring-kesegaran',
            question: 'Apa yang ditampilkan Monitoring, dan seberapa baru datanya?',
            images: [
                { src: '/images/faq/monitoring.webp', alt: 'Halaman Monitoring dengan pilihan rentang waktu, tab Jaringan, Gangguan, Alerts, NOC, dan kartu ringkasan router', caption: 'Tab "Jaringan" terbuka lebih dulu. Contoh ini dari data uji tanpa router, jadi kartu masih kosong.' },
            ],
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Halaman "Monitoring" adalah snapshot read-only: tidak ada ping atau login ke perangkat dari browser. Snapshot di server diperbarui tiap 2 menit dan halaman memuat ulang sendiri tiap 60 detik (lebih jarang bila sempat gagal, dan berhenti saat tab tidak terlihat). Di tab "Jaringan", subjudul header menunjukkan "Sumber: Grafana · diperbarui" beserta jam pembaruan terakhir; di tab lain, badge di header menunjukkan "Snapshot: X menit lalu" beserta live/stale.',
                'Tab: "Jaringan" (tab awal, kondisi router, trafik, dan upstream), "Gangguan", "Alerts", "NOC", dan "Pemakaian Bandwidth" (hanya muncul bila fitur trafik diaktifkan). Tab "Overview" lama sudah digabung ke tab "Jaringan".',
                'Menu Monitoring hanya untuk Admin global (tanpa pembatasan area), NOC, dan Superadmin. Teknisi dan Admin yang dibatasi area tidak melihat menunya, dan membuka alamatnya langsung akan ditolak (403).',
            ],
            links: [{ label: 'Buka Monitoring', route: 'monitoring.index' }],
            updated: '2026-10-11',
        },
        {
            id: 'kartu-kesehatan-cpe',
            question: 'Apa arti kartu "Kesehatan CPE" di tab Jaringan?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Kartu "Kesehatan CPE" di tab "Jaringan" merangkum kondisi modem/ONU pelanggan berdasarkan status inform GenieACS dan daya optik ONU yang tercatat di eBilling. Isinya hanya angka agregat, tanpa nama atau data pelanggan, dan terdiri dari tiga bagian.',
                '"CPE online" menunjukkan persentase perangkat yang online beserta jumlah online, total, dan offline, plus waktu inform terakhir. Penandanya: "Sehat" bila 90% atau lebih, "Perlu dicek" bila 75% sampai kurang dari 90%, dan "Banyak offline" bila di bawah 75%.',
                '"Daya optik ONU (RX)" membagi pelanggan online yang terukur menjadi tiga kelompok: "Baik" (-25 dBm atau lebih kuat), "Sedang" (-25 sampai -27 dBm), dan "Lemah" (-27 dBm atau lebih lemah). "Area dengan CPE offline terbanyak" menampilkan area dengan CPE offline paling banyak, lengkap dengan jumlah offline dari total perangkat di area itu.',
            ],
            notes: [
                'Kartu hanya terlihat oleh Admin global, NOC, dan Superadmin, sama seperti menu Monitoring. Bila muncul "Belum ada data CPE", berarti belum ada perangkat yang tersinkron dari GenieACS.',
                'Untuk memeriksa satu pelanggan (reboot atau cek perangkat), gunakan menu GenieACS, bukan kartu ini.',
            ],
            links: [{ label: 'Buka Monitoring', route: 'monitoring.index' }],
            updated: '2026-10-12',
        },
        {
            id: 'detail-router',
            question: 'Bagaimana membuka halaman detail router, melihat daftar PPPoE, dan pemakaian hariannya?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Di tab "Jaringan", klik nama router pada tabel router untuk membuka halaman detailnya. Halaman ini hanya untuk Admin global, NOC, dan Superadmin; akun lain ditolak (403).',
                'Bagian atas memuat "Ringkasan router": PPPoE online, Download sekarang, Upload sekarang, dan Pemakaian bulan ini. Pilih rentang waktu di kanan atas untuk grafik "Trafik uplink" dan "10 pemakai terbesar" saat ini. Tombol "Buka di Grafana" membuka dashboard router itu di tab baru. Data diperbarui otomatis tiap 60 detik dan berhenti saat tab tidak terlihat.',
                'Grafik "Pemakaian harian (30 hari)" menampilkan total download dan upload per hari. Hari yang datanya tidak valid dikosongkan dan tidak ikut dijumlahkan; untuk router Krian lama (bukan Krian-OLT), data sebelum 11 Oktober 2026 memang tidak valid. Riwayat pemakaian harian tersedia sejak 3 Oktober 2026.',
            ],
            steps: [
                'Pada tabel "Pelanggan PPPoE", ketik di "Cari nama, kode, atau akun PPPoE", atau saring dengan Semua, Online, Offline, atau "Tidak tertaut" (akun PPPoE yang belum cocok dengan data pelanggan).',
                'Urutkan lewat judul kolom. Kolomnya memuat kecepatan download/upload saat ini serta pemakaian hari ini dan bulan ini; tanda * berarti ada hari bermasalah yang tidak dihitung.',
                'Klik sebuah baris untuk membuka panel pemakaian harian 30 hari pelanggan itu, termasuk tautan ke profil pelanggannya.',
            ],
            notes: [
                'Pemakaian bulan ini dihitung per bulan kalender (zona waktu WIB). Download adalah data yang dikirim router ke pelanggan, Upload adalah data yang diterima router.',
                'Bila Grafana sedang tidak tersedia, bagian trafik langsung menampilkan pesan, tetapi daftar PPPoE dan riwayat harian tetap tampil dari database.',
            ],
            links: [{ label: 'Buka Monitoring', route: 'monitoring.index' }],
            updated: '2026-10-12',
        },
        {
            id: 'bandwidth',
            question: 'Bagaimana melihat pemakaian bandwidth satu pelanggan?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Buka tab "Pemakaian Bandwidth" (hanya muncul bila fitur trafik diaktifkan), ketik di "Cari nama, kode, PPPoE", atau saring per area, router, paket, status, profile, sumber, dan CPE. Tabel menampilkan pemakaian hari ini, bulan ini, dan 30 hari; klik "Detail" pada baris untuk rincian dan grafik 24 jam (interval sekitar 15 menit).',
                'Arti kolom: Download adalah data yang dikirim router ke pelanggan, Upload adalah data yang diterima router. Data diperbarui tiap 30 detik dan dijeda saat tab tidak terlihat.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'gangguan-dan-noc',
            question: 'Bagaimana membaca tab "Gangguan" dan "NOC", dan apa itu triage?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Tab "Gangguan" mengelompokkan pelanggan offline per ODC/PON atau per ODP, dengan prioritas P1/P2/P3 dan jumlah pelanggan terdampak, lalu memperbarui diri tiap 30 detik. Tab "NOC" menambah tampilan "Perlu Tindakan", "Gangguan Massal", "Pelanggan", "Sumber & Perangkat", "Histori & SLA", serta preset filter yang bisa disimpan ("Simpan Tampilan").',
                'Triage adalah tanggapan NOC atas tiket monitoring sebuah insiden: "Ack" (sudah diketahui dan ditangani) atau "False positive" (bukan gangguan nyata). Keduanya membuka dialog "Alasan triage" yang wajib diisi, lalu "Simpan triage". Tombolnya hanya muncul bila fitur triage diaktifkan dan insiden sudah punya tiket; bila fitur dimatikan, server menolak dengan pesan "Monitoring ticket triage masih OFF."',
            ],
            notes: [
                'Pelanggan berstatus Isolir atau Berhenti tidak dihitung sebagai gangguan ("Excluded").',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'deteksi-gangguan',
            question: 'Apa isi tab "Deteksi Gangguan" di Monitoring?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Tab ini terlihat oleh Superadmin, NOC, dan admin tanpa batas area. Badge "Mode uji — tiket hanya dibuat manual" menandakan fitur masih uji coba: tiket tidak dibuat otomatis dan tidak ada WA ke pelanggan.',
                'Untuk kejadian yang masih terbuka, klik "Buat tiket", cek isinya, lalu konfirmasi. Tiket dibuat tanpa teknisi dan hanya grup Report yang menerima 1 pesan. Setelah itu baris menampilkan "Tiket #N" dan tiket memuat sumbernya.',
                'Isinya daftar deteksi gangguan otomatis: jenis LOS massal, LOS individual, Redaman lemah, PPPoE mati, dan Lainnya, lengkap dengan OLT/PON, status deteksi, dan pelanggan terdampak (kode, nama, PPPoE, port ONU, redaman). Bila pelanggan terdampak banyak, klik "+N lainnya" untuk melihat daftar lengkap.',
                'Filter yang tersedia: tanggal (bawaan 7 hari, maksimal 31 hari), OLT, jenis, dan status.',
            ],
            updated: '2026-10-19',
        },
        {
            id: 'deteksi-buat-tiket',
            question: 'Buat tiket dari Deteksi Gangguan',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Tombol "Buat tiket" di Deteksi Gangguan membuat tiket gangguan. Kabarnya hanya dikirim ke grup Report. Pelanggan tidak dapat WA.',
            ],
            updated: '2026-10-19',
        },
        {
            id: 'alert-wa',
            question: 'Bagaimana mengatur alert WA gangguan ke grup NOC?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Pada kartu "Alert WA Connectivity" di tab Alerts atau NOC (kasus PON mati sementara PPPoE masih aktif), klik "Pengaturan". Dialog "Alert WA Monitoring" menampilkan "Grup WhatsApp" yang dipakai (hanya tampil; diubah oleh Superadmin/Admin di Pengaturan › Grup WhatsApp), sakelar "Aktifkan alert", dan sakelar "Dry-run" (hanya dicatat di log, belum kirim WA). Status tampil sebagai "Nonaktif", "Dry-run", atau "WA Aktif".',
                'Mode bawaan mengirim ringkasan per ODP (anti-spam): dicek tiap 15 menit, diulang paling cepat 2 jam, maksimal 5 ODP per putaran. Hanya Admin, NOC, dan Superadmin yang melihat tombol "Pengaturan" dan bisa mengubahnya.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'mapping-cara-pakai',
            question: 'Bagaimana memakai Mapping untuk mencari ODP atau pelanggan?',
            images: [
                { src: '/images/faq/mapping.webp', alt: 'Halaman Peta Aset Skynet dengan panel pencarian, statistik, filter ODP ODC Pole Pelanggan Kabel, dan peta', caption: 'Panel kiri: pencarian, statistik, filter lapisan, dan daftar area.' },
            ],
            audience: ['teknisi', 'admin'],
            answer: [
                'Halaman "Peta Aset Skynet" menampilkan POP, ODC, ODP, tiang, kabel feeder, dan pelanggan per area. Ketik di "Cari ODP, pelanggan..." lalu pilih hasilnya; peta berpindah ke titik itu. Tombol filter ODP/ODC/Pole/Pelanggan/Kabel menyalakan atau mematikan lapisan; secara bawaan hanya area pertama yang aktif dan lapisan Pelanggan mati.',
                'Pin pelanggan berwarna sesuai online/offline/isolir; saat diperkecil menjadi "Cluster pelanggan" dengan hitungan. Popup ODP memuat OLT Port, Port Aktif / Total, Koneksi, RX terakhir, dan tautan Google Maps. Ada tombol "Satelit" untuk citra satelit.',
            ],
            notes: [
                'Di HP, panel samping tertutup; buka dengan "Buka panel peta". Panel menutup sendiri setelah hasil pencarian dipilih.',
                'Akun Teknisi menerima koordinat pelanggan yang dibulatkan 3 desimal; angka persis hanya untuk Admin, NOC, dan Superadmin.',
            ],
            links: [{ label: 'Buka Mapping', route: 'peta.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'tambah-odp',
            question: 'Bagaimana menambah atau mengubah data ODP?',
            audience: ['admin'],
            answer: [
                'Saat ini belum ada form tambah/ubah ODP di Mapping maupun Inventaris. "Import Preview" di Inventaris menerima .csv, .xlsx, .kml, atau .kmz (maksimal 10 MB) tetapi hanya memeriksa dan menampilkan hingga 200 baris dengan hitungan siap/perlu tinjau/duplikat, tanpa menyimpan. Pemilihan ODP untuk pelanggan baru dilakukan di menu Aktivasi, tab Auto PPPoE (menu ini hanya untuk Admin global, NOC, dan Superadmin).',
                'Perubahan data ODP yang diperlukan segera disampaikan ke tim pengembang.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'inventaris-isi',
            question: 'Apa yang dicatat di Inventaris?',
            audience: ['teknisi', 'admin'],
            answer: [
                'Halaman "Inventaris Aset" mencatat aset jaringan pasif: tab "Semua", ODP, "ODC/POP", "Kabel", "Splitter", dan "Perangkat kembali". ODP menampilkan Total Port, Aktif, Tersedia, Status, dan Koordinat; Splitter menambah Terpakai dan "Parent ODP/ODC". Stok ONU/router cadangan tidak dicatat di sini.',
                'Tab "Perangkat kembali" menampilkan ringkasan 20 kasus Pengambilan Perangkat yang terakhir diperbarui (tiket, pelanggan, SN, status fisik, teknisi); akun Teknisi hanya melihat kasus yang ditugaskan kepadanya. Setiap jenis aset lain ditampilkan hingga 200 baris.',
                'Daftarnya hanya untuk dibaca. "Export CSV" dan "Import Preview" hanya untuk Admin, NOC, dan Superadmin; akun Teknisi melihat tombolnya tetapi permintaannya ditolak.',
            ],
            links: [{ label: 'Buka Inventaris', route: 'inventaris.index' }],
            updated: '2026-10-10',
        },
    ],
};
