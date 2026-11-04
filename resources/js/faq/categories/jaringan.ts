import { Boxes } from 'lucide-react';
import type { FaqCategory } from '../types';

export const jaringan: FaqCategory = {
    slug: 'jaringan',
    title: 'OLT, Aktivasi, MikroTik & Area',
    description: 'Perangkat inti jaringan: daftar OLT dan ONU, aktivasi ONU baru, router MikroTik, profil isolir, dan pembagian area.',
    icon: Boxes,
    access: 'global_admin',
    routes: ['olts.index', 'oltc300.index', 'routers.index', 'areas.index'],
    items: [
        {
            id: 'baca-halaman-olt',
            question: 'Bagaimana membaca halaman detail OLT dan kualitas sinyal ONU?',
            audience: ['admin'],
            answer: [
                'Detail OLT punya tab "GPON Snapshot" dan "Detail OLT". Kartu ringkasan sekaligus filter: Total ONU, Online, Offline, Matched, Belum Match, Match Conflict, SN Double. Filter "Kualitas Sinyal": Good (RX -25 dBm atau lebih baik), Lemah (sampai -27), Loss (sampai -30), RX Missing, Offline.',
                'Kolom tabel: PON Port, ONU Ref, Nama ONU, Status (Online, keadaan dari OLT seperti LOS, atau Offline), Serial Number, Redaman (dBm), Jarak Fiber (m), Pelanggan, Aksi. Pada OLT ZTE C300 yang belum punya data redaman sama sekali muncul tombol "Refresh Redaman (SNMP)".',
            ],
            links: [{ label: 'Buka OLT', route: 'olts.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'aksi-onu',
            question: 'Aksi apa yang bisa dilakukan pada satu ONU, dan apa pengamannya?',
            audience: ['admin'],
            answer: [
                '"Lihat Detail", "Rename ONU + ACS" (mengganti nama di OLT dan tag GenieACS; ketik "RENAME ONU" lalu "Simpan OLT + ACS"), "Reboot ONU" (centang "Saya sudah memastikan ONU yang dipilih benar.", ketik "REBOOT ONU", lalu "Reboot sekarang"), dan "Hapus ONU" (mengirim perintah hapus ke OLT, data pelanggan tidak berubah; ketik "DELETE ONU", dan bila ONU masih online centang juga "ONU masih online — saya yakin…").',
                'Semua perintah tulis ke OLT diperiksa gerbang pengaman di server: hanya Admin global, NOC, atau Superadmin yang bisa menjalankannya, dan setiap perintah memakai persetujuan internal sekali pakai yang berlaku 15 menit. Bila mode dry-run atau kill switch OLT aktif, perintah ditolak dengan "OLT write blocked by the provisioning safety gate. No device write was performed."',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'hapus-onu-huawei-otomatis',
            question: 'Bagaimana penghapusan otomatis ONU Huawei (dismantle atau duplikat) bekerja?',
            audience: ['admin'],
            answer: [
                'Untuk ONU Huawei yang didismantle atau duplikat, penghapusan otomatis kini berurutan: service-port dihapus lebih dulu, lalu ONT, lalu konfigurasi disimpan.',
                'Urutan baru ini dipakai oleh penghapusan otomatis dismantle hanya selama pengaturan "hapus otomatis ONU Huawei" aktif (saat ini aktif) dan device-recovery dalam mode write. Perintah operator `olt:delete-duplicate-onus --execute` selalu memakai urutan baru, tetapi tetap membutuhkan mode write.',
                'Bila proses berhenti di tengah jalan, WA ops dikirim ke Alert Ops (maksimal 1 kali per ONU per 24 jam). Petunjuk membangun ulang hanya ada bila service-port sudah sempat dihapus; bila belum, pesan menyatakan status service-port tidak pasti dan meminta OLT diperiksa. Perintah `olt:delete-duplicate-onus` dalam mode dry-run menampilkan jumlah service-port per ONU sebelum eksekusi.',
            ],
            updated: '2026-10-16',
        },
        {
            id: 'sn-double',
            question: 'Ada SN double di OLT setelah migrasi. Mana yang dihapus?',
            audience: ['admin'],
            answer: [
                'Ikuti blok "Panduan hapus SN double / migrasi FHTT" di halaman OLT: SN lama di port lama (biasanya ZTE/CIOT offline, ghost) dihapus; SN FHTT baru di port baru yang online dipertahankan; serial yang identik di banyak port dicek status dan pelanggannya dulu. Label baris: Hapus (ghost), Pertahankan, Cek manual.',
                'Setelah menghapus, perbarui port/SN di data pelanggan secara manual bila pelanggan sudah dimigrasi.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'aktivasi-alur',
            question: 'Bagaimana mengaktifkan pelanggan baru dari menu Aktivasi?',
            images: [
                { src: '/images/faq/aktivasi.webp', alt: 'Halaman Aktivasi OLT dengan tab Auto PPPoE, Provision ZTE C300, Rekonfig ZTE, Provision FiberHome, Provision Huawei, PSB WA, VLAN MikroTik, VLAN OLT / Area, Rename ONU', caption: 'Tab "Auto PPPoE" dengan langkah 1. Foto KTP, 2. Identitas, 3. Area/ODP, 4. Aktivasi.' },
            ],
            audience: ['admin'],
            answer: [
                'Halaman "Aktivasi OLT" punya tab: Auto PPPoE, Provision ZTE C300, Rekonfig ZTE, Provision FiberHome, Provision Huawei, PSB WA, VLAN MikroTik, VLAN OLT / Area, dan Rename ONU.',
            ],
            steps: [
                'Auto PPPoE: "1. Foto KTP" (OCR mengisi NIK, Nama, RT/RW), "2. Identitas", "3. Area/ODP" (foto ODP opsional sebagai dokumentasi), "4. Aktivasi" dengan pilihan Area, ODP, Router (MikroTik), Paket Internet. Wajib diisi: Nama, RT, RW, ODP, Area, Router, Paket. PPPoE user dibuat dengan format NAMA_RTx_RWy_ODP; aplikasi membuat pelanggan Aktif, entri RADIUS, dan secret MikroTik. Centang "Dry run (preview only)" mengubah tombol menjadi "Preview"; tanpa centang tombolnya "Aktivasi sekarang".',
                'Provision ZTE C300: "Langkah 1: Ambil Foto & Deteksi SN ONU" (foto label, lalu "Deteksi ONU"), "Langkah 2: Konfirmasi & Aktifkan" (Username/Password PPPoE, "Aktifkan ONU Sekarang", ketik "PROVISION OLT").',
                'Provision FiberHome: pilih OLT, "Pilih MikroTik (PPPoE / TR-069)", dan SN ONU FiberHome (atau "Pilih SN dari ONU Uncfg"), lalu "Deteksi" untuk mengisi Port PON, ONU ID, dan VLAN otomatis; isi "Nama ONU / Tag GenieACS" dan Paket Bandwidth (format 25M/50M/100M/200M); "Dry Run (Preview)" lalu "Provision Now" dan ketik "PROVISION OLT".',
                'Provision Huawei (Gadang, MA5608T): "Deteksi SN (autofind)", lalu ont add, baca ulang, service-port, simpan. Hanya menyetel OLT; secret PPPoE dan data pelanggan tetap lewat PSB WA. Ada "Dry Run (Preview)"; "Provision Now" meminta ketik "PROVISION OLT".',
                'Rekonfig ZTE: mengganti ONU di slot yang sama; ketik "RECONFIGURE OLT".',
            ],
            links: [{ label: 'Buka Aktivasi', route: 'oltc300.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'mikrotik',
            question: 'Apa yang bisa dilakukan di menu MikroTik?',
            audience: ['admin'],
            answer: [
                '"Daftar Router" menampilkan semua router dengan tombol "Sinkron Semua" untuk mengantrekan sinkronisasi penuh (secret dan profil) semua router yang aktif. Peringatan yang mungkin tampil: "Secret di DB mungkin stale — Sinkron ulang" atau "Router tidak terjangkau".',
                'Detail router punya tab Ringkasan (Sinkronisasi Terakhir, Koneksi Aktif, PPPoE Address Pool, Paket Perlu Review), Pelanggan, Paket, dan VPN & RADIUS. Di tab VPN & RADIUS, bagian "Profil Isolir (Suspend)" dipakai untuk membuat profil PPP yang dipakai saat pelanggan diisolir: Nama Profil, Batas Kecepatan (misalnya 128k/128k), IP Lokal Router, Pool IP / IP Remote; tombol "Buat Profil Isolir" dan "Hapus Profil".',
            ],
            notes: [
                'Tab VPN & RADIUS: sakelar Aktifkan VPN dan Aktifkan RADIUS, tombol "Sync Profile MikroTik", "Sinkronkan RADIUS", "Simpan VPN". Kata sandi hanya ditampilkan sebagai "tersimpan/belum diatur".',
            ],
            links: [{ label: 'Buka MikroTik', route: 'routers.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'area',
            question: 'Untuk apa menu Area, dan apa yang dilakukan "Migrasi Area"?',
            images: [
                { src: '/images/faq/area.webp', alt: 'Halaman Areas dengan tombol Tambah Area dan Migrasi Area serta tabel Nama, Kode, Pelanggan, Perangkat (OLT)', caption: 'Tombol "Migrasi Area" di kanan atas; tabel menunjukkan jumlah pelanggan dan OLT per area.' },
            ],
            audience: ['admin'],
            answer: [
                'Area adalah wilayah operasi yang dipakai untuk membatasi data Admin area, menyaring laporan keuangan, dan mengelompokkan pelanggan serta OLT. Kolomnya: Nama, Kode, Pelanggan, Perangkat (OLT).',
                '"Migrasi Area" memindahkan semua pelanggan dari Area Asal ke Area Tujuan dengan tombol "Pindahkan Pelanggan", lalu konfirmasi "Pindahkan pelanggan". Yang berubah hanya area pelanggan di database (tercatat di audit); router, OLT, dan tagihan tidak disentuh. Centang "Saya memahami bahwa perubahan area pelanggan akan dicatat dan tidak dapat dibatalkan dari dialog ini."',
            ],
            links: [{ label: 'Buka Area', route: 'areas.index' }],
            updated: '2026-10-10',
        },
    ],
};
