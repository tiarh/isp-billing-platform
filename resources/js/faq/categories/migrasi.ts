import { RefreshCw } from 'lucide-react';
import type { FaqCategory } from '../types';

export const migrasi: FaqCategory = {
    slug: 'migrasi',
    title: 'Migrasi',
    description: 'Memindahkan pelanggan lama ke ONU/router/OLT baru: lewat WA dari lapangan, import staging, device action, retry, dan cancel.',
    icon: RefreshCw,
    access: 'admin',
    routes: ['customer-migrations.index'],
    items: [
        {
            id: 'apa-itu-migrasi',
            question: 'Apa yang dimaksud migrasi di aplikasi ini?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Mengganti ONU lama pelanggan dengan ONU FiberHome ber-SN baru, dan bila perlu memindahkannya ke router/OLT lain, tanpa mencampur datanya dengan PSB pelanggan baru. Paket tujuan untuk pelanggan rumahan adalah STARTLITE/200M; sekolah atau instansi memakai CSR. Khusus Krian, paket pelanggan dipertahankan (tidak diubah kecepatannya).',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'migrasi-via-wa',
            question: 'Bagaimana melapor migrasi lewat WA dari lapangan?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Kirim pesan "#migrasi" ke grup PSB/migrasi yang didaftarkan admin, berisi Nama, SN Baru, Daerah, RT, RW, dan bila perlu OLT, Router, atau Keterangan. Yang wajib: Nama, SN Baru, dan satu pembeda pelanggan (PPPoE, HP, SN Lama, ODP, atau RT+RW) agar bot menemukan pelanggan yang tepat.',
                'Jika ada beberapa kandidat, bot menampilkan daftarnya; balas "#migrasi pilih #ID nomor" lalu "#migrasi konfirmasi #ID". Konfirmasi dilakukan oleh teknisi yang memilih kandidat. Perintah lain: "#migrasi cek #ID" (status), "#migrasi batal #ID", "#migrasi ktp #ID" sebagai caption foto KTP, "#migrasi no hp #ID <nomor>", "#migrasi sn #ID <SN>" untuk koreksi SN, "#migrasi bantu", dan "/migrasi" untuk rekap.',
            ],
            notes: [
                'Tidak ada eksekusi otomatis. Setelah draft direview dan dikonfirmasi, perubahan perangkat (pasang ONU baru, pindah secret, hapus ONU lama) dijalankan secara eksplisit: dari halaman Migrasi oleh admin/NOC, atau lewat "#migrasi eksekusi #ID" oleh teknisi yang mengonfirmasi atau NOC yang diizinkan.',
                'Eksekusi lewat WA hanya berjalan bila fitur itu sudah diaktifkan; jika belum, bot membalas "Eksekusi migrasi diblokir: WA-only live masih OFF."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'format-wa-srigading-c300-ra',
            question: 'Format pesan WA untuk migrasi pelanggan Srigading (Hioso) ke C300 RA seperti apa?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Untuk memindahkan pelanggan Srigading (ONU Hioso) ke OLT C300 Randuagung (RA), kirim satu pesan ke grup PSB/migrasi, satu isian per baris dengan tanda titik dua, dimulai dengan "#migrasi". Contoh (data hanya contoh):',
                '#migrasi\nNama: <NAMA PELANGGAN>\nPPPoE: <USER PPPOE>\nSN Baru: <SN ONU BARU>\nSN Lama: <SN ONU LAMA>\nOLT: RA\nDaerah: Srigading\nRT: 02\nRW: 04\nRouter Lama: Randuagung\nRouter Baru: Skynet-RA-OLT\nKeterangan: migrasi',
                'Yang wajib: "Nama", "SN Baru" (SN ONU baru yang dipasang), dan minimal satu data pembeda agar bot menemukan pelanggan yang tepat, yaitu salah satu dari PPPoE, No HP, SN Lama, Daerah beserta ODP, atau RT beserta RW. Disarankan mengisi PPPoE karena paling tepat.',
                'Yang opsional: "SN Lama", "Daerah", "RT", "RW", "Router Lama", "Router Baru", "OLT", dan "Keterangan". Baris "OLT: RA" memilih OLT C300 RA sebagai tujuan ONU baru, dan baris ini didahulukan dibanding OLT lama pelanggan. Bila baris OLT tidak ada, OLT tujuan ditentukan dari Router Baru atau Daerah. "Router Lama" dan "Router Baru" menunjukkan pindah router; user PPPoE pelanggan tetap sama.',
            ],
            steps: [
                'Kirim pesan "#migrasi" dengan format di atas. Bot membalas daftar kandidat pelanggan atau langsung draft migrasi lengkap dengan nomor ID.',
                'Bila ada beberapa kandidat, balas "#migrasi pilih #ID nomor" (1 sampai 5). Setelah itu, termasuk bila kandidatnya langsung unik, konfirmasi selalu wajib: kirim "#migrasi konfirmasi #ID" dari nomor WA yang sama dengan yang memilih, dalam 30 menit.',
                'Jika bot meminta KTP, kirim foto dengan caption "#migrasi ktp #ID". Jika bot meminta nomor HP, kirim "#migrasi no hp #ID 08xxxxxxxxxx". Lalu ulangi konfirmasi.',
                'Setelah dikonfirmasi dan dry-run siap, kirim "#migrasi eksekusi #ID" untuk menjalankan perubahan perangkat. Tidak ada eksekusi otomatis.',
                'Cek hasilnya kapan saja dengan "#migrasi cek #ID".',
            ],
            notes: [
                'Jika migrasi tersangkut atau berhenti di tengah jalan, jangan membuat draft baru. Kirim "#migrasi cek #ID" untuk melihat status, lalu "#migrasi eksekusi #ID" dengan ID yang sama untuk melanjutkan. Pengiriman pertama kadang hanya menyiapkan dry-run lanjutan; bila bot membalas "Kirim lagi untuk melanjutkan", kirim perintah yang sama sekali lagi. Draft baru untuk pelanggan yang sama justru bisa memicu penolakan karena masih ada migrasi yang sebagian dieksekusi.',
                'SN salah ketik? Kirim "#migrasi sn #ID SNBENAR" (SN ditulis tanpa spasi), lalu ikuti balasan bot: biasanya "#migrasi konfirmasi #ID" lalu "#migrasi eksekusi #ID"; bila migrasi sudah sebagian dieksekusi, langsung "#migrasi eksekusi #ID". Koreksi hanya diterima dari pengirim draft/pemilih kandidat atau NOC yang diizinkan. Pada baris "SN Baru:" dan "SN Lama:" di pesan awal, spasi dan tanda baca diabaikan dan huruf kecil otomatis menjadi kapital.',
                'Tulis nama isian persis seperti contoh ("SN Baru", bukan "SN ONU baru"), karena nama lain tidak dikenali. Spasi sebelum titik dua tidak masalah ("PPPoE :user" sama dengan "PPPoE: user").',
                'Eksekusi hanya diterima dari nomor WA yang mengirim pesan "#migrasi" awal, atau nomor yang ada di daftar izin eksekusi (NOC), dan hanya bila fitur eksekusi WA sudah dinyalakan. Paling aman: kirim semua perintah (#migrasi, pilih, konfirmasi, eksekusi) dari nomor yang sama.',
                '"Router Lama" dipakai untuk memeriksa akun PPPoE di router asal; nilai yang salah bisa menahan migrasi, jadi kosongkan baris itu bila ragu (untuk Srigading, "Randuagung" sudah benar). Daerah Srigading sudah dipetakan ke Randuagung C300 sejak 30 September 2026.',
            ],
            updated: '2026-10-12',
        },
        {
            id: 'site-migrasi',
            question: 'Daerah mana saja yang mendukung migrasi via WA?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Site mengikuti daftar site PSB WA. Migrasi dengan provisioning ONU di OLT tersedia untuk Randuagung C300 (RA) dan Krian C300 (sejak 30 September 2026 diarahkan ke router Krian-OLT; paket pelanggan dipertahankan). Sejak tanggal yang sama, Daerah Martopuro dan Srigading (juga Purwodadi, SLB, Jatisari, Gajahrejo, Kertosari) dipetakan ke Randuagung C300.',
                'Gadang memakai alur khusus OLT Huawei MA5608T (format "Mode: OLT_ONLY_HUAWEI", lihat "#migrasi bantu"), termasuk penggantian router FiberHome yang rusak. Pindah router (misalnya Bumiayu ke Gadang) ditulis dengan baris "Router Lama:" dan "Router Baru:"; user PPPoE tetap sama.',
                'Perubahan perangkat dijaga saklar pengaman (kill switch); selama saklar tertutup, migrasi hanya sampai dry-run dan tidak ada perubahan perangkat.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'status-migrasi',
            question: 'Apa arti status di halaman Migrasi?',
            images: [
                { src: '/images/faq/migrasi.webp', alt: 'Halaman Migrasi dengan ringkasan Baris masuk, Total unik, Terverifikasi aktual, Belum selesai, Perlu review, Gagal, dan kartu Operasional hari ini', caption: 'Ringkasan migrasi: "Sukses final" berarti baris unik yang sudah synced atau terbukti live.' },
            ],
            audience: ['admin'],
            answer: [
                'Received: laporan diterima. Tunggu SN: ONU baru belum terdeteksi di OLT. Review: perlu keputusan admin (kandidat ganda, data tidak lengkap). Confirmed: pelanggan sudah dipastikan, siap device action. Dry-run Ready / Siap Execute: pratinjau perubahan perangkat sudah dibuat. Sebagian dieksekusi: sebagian langkah perangkat sudah jalan, sebagian belum. OLT OK: ONU baru sudah terprovisi. Synced: data pelanggan sudah diperbarui. Gagal, Blocked (ditahan saklar pengaman), Batal.',
                'Pada tabel staging ada klasifikasi baris: Migrasi, PSB Baru, Duplikat, Belum Migrasi, Sudah Migrasi, Staged, Applied.',
            ],
            links: [{ label: 'Buka Migrasi', route: 'customer-migrations.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'import-staging',
            question: 'Bagaimana memakai "Import Migrasi Staging"?',
            audience: ['admin'],
            answer: [
                'Unggah .csv/.xlsx (maksimal 20 MB) atau isi "URL snapshot opsional", beri "Label batch", lalu klik "Dry-run". Kolom yang dikenali: nama_pelanggan/nama, pppoe/pppoe_user, sn_baru, sn_lama, sn, area/daerah, rw, project, status. Hasilnya hanya pratinjau: "Dry-run snapshot, tidak commit ke pelanggan/OLT/MikroTik."',
                '"Koreksi Bulk" ("Koreksi Terpilih") mengubah klasifikasi (Migrasi/PSB Baru/Review) dan RW untuk maksimal 200 baris terpilih dengan alasan minimal 8 karakter. "Apply Staging" / "Apply Terpilih" (maksimal 100 baris, alasan minimal 8 karakter) menyimpan hasil klasifikasi tanpa menulis ke OLT, MikroTik, PPPoE, atau data pelanggan.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'device-action',
            question: 'Bagaimana menjalankan perubahan perangkat (device action) untuk satu migrasi?',
            audience: ['admin'],
            answer: [
                'Hanya untuk baris berstatus Confirmed.',
            ],
            steps: [
                'Isi "Alasan action migrasi" (minimal 8 karakter) lalu klik "Dry-run". Muncul "Dry-run migrasi #ID siap. Ledger action dibuat, belum ada write perangkat."',
                'Periksa pratinjau, lalu klik "Execute". Dialog "Konfirmasi execute migrasi" menampilkan Identitas, Router target, SN lama → baru, ONU lama → baru, OLT lama → baru, serta VLAN internet / TR-069; centang "Saya sudah memeriksa preview dan menyetujui execute migrasi ini."',
                'ONU baru harus sudah online sebelum ONU lama dihapus; aplikasi memeriksanya.',
            ],
            notes: [
                'Execute hanya aktif bila saklar tulis migrasi, OLT, MikroTik, dan GenieACS menyala dan mode read-only global mati; selain itu tombolnya nonaktif ("Execute diblok kill switch"). Execute tanpa dry-run ditolak: "Buat dry-run/approval dulu sebelum execute."',
                'Jika migrasi "Sebagian dieksekusi", tombol Retry dan Batal ditolak; ikuti petunjuk pesan: perbaiki SN dengan "#migrasi sn #ID <SN>" lalu "#migrasi eksekusi #ID", atau batalkan paksa dengan "#migrasi batal paksa #ID".',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'lookup-operator',
            question: 'Apa fungsi "Lookup Migrasi Operator"?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Pencarian pelanggan untuk laporan migrasi yang belum terpasangkan: isi Nama pelanggan dan SN baru (wajib), ditambah HP, Kode pelanggan, Area, ODP, RT, atau RW; aplikasi menampilkan hingga 5 kandidat. Memilih kandidat membutuhkan "Alasan approval DB-only" minimal 8 karakter dan hanya memasangkan data di database ("Tidak update customer/perangkat."). Hanya Admin global, NOC, dan Superadmin yang bisa membukanya.',
            ],
            updated: '2026-10-10',
        },
    ],
};
