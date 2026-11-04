import { UserPlus } from 'lucide-react';
import type { FaqCategory } from '../types';

export const psbWa: FaqCategory = {
    slug: 'psb-wa',
    title: 'PSB WA',
    description: 'Pendaftaran pelanggan baru lewat bot WhatsApp: draft, koreksi, apply ke router/OLT, dan import PSB.',
    icon: UserPlus,
    access: 'admin',
    routes: ['psb-drafts.dashboard', 'psb-imports.index'],
    items: [
        {
            id: 'format-psb',
            question: 'Bagaimana format pesan #psb untuk mendaftarkan pelanggan baru dari lapangan?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Kirim satu pesan ke grup WhatsApp PSB yang sudah didaftarkan admin (nomor "Operasional & PSB WA"), dengan format:',
                '#psb / Nama / ODP / RT / RW / Tikor / Daerah / Router: RA/KRIAN/... / Paket: STARTLITE/CSR/... / SN: FHTT... / HP',
                'Semua kolom wajib: Nama, ODP, RT, RW, Tikor (koordinat), Daerah, Router, Paket, SN, dan KTP. Foto KTP dan foto stiker SN router dikirim sebagai pesan terpisah di grup yang sama; bot mengenali mana KTP dan mana SN. Jika KTP belum ada, tulis "KTP: menyusul" beserta "Alasan KTP:" dan "Tenggat KTP:" (tanggal yang belum lewat), lalu kirim fotonya dengan caption "#psb ktp #ID"; untuk sekolah/instansi tulis "KTP: CSR".',
            ],
            notes: [
                'Nama kolom boleh ditulis dengan sinonim: HP = No HP/Phone/WA, Tikor = Koordinat/GPS, Daerah = Area/Wilayah, Paket = package/profile/bandwidth.',
                'Jika data kurang, bot membalas "Data PSB belum lengkap …" beserta daftar kolom yang wajib.',
                'Paket dicocokkan dengan paket aktif milik router tujuan (atau paket umum tanpa router). Jika paket belum terdaftar untuk router itu, bot membalas bahwa paket/profile belum ada; minta admin/NOC menyiapkannya.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'perintah-bot',
            question: 'Perintah apa saja yang dimengerti bot PSB di grup?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                '"#psb bantu" menampilkan bantuan. "/psb" rekap hari ini. "#psb cek #ID" cek PPPoE dan redaman langsung. "#psb antri" daftar antrean. "#psb lanjut #ID" memproses ulang draft yang tersangkut. "#psb kirim lagi #ID" mengirim ulang kredensial PPPoE dan panduan setelan ONT. "#psb batal #ID" membatalkan draft (secret/ONU tidak dihapus). "#psb sn FHTT…" memperbaiki SN. "#psb ya #ID" menyetujui pemakaian SN yang terdeteksi di OLT bila berbeda dari chat. "#psb ktp #ID" sebagai caption foto KTP susulan. "#psb router <alias>" mengganti router tujuan; alias yang dikenal: ra, krian, bumiayu, rdg, gadang. "done" atau "done #ID" dipakai NOC setelah memasang VLAN.',
                'Perintah pencarian pelanggan lama: "/pppoe <kata kunci>" atau "#pppoe cari <nama/user/hp>". Satu hasil: bot membalas nama, user PPPoE, paket/profile, alamat/area, router, status, dan status live dari MikroTik; password PPPoE tidak ditampilkan. Lebih dari satu: hingga 5 kandidat; tulis kata kunci lebih spesifik untuk mempersempit. Jika tidak ada di data pelanggan, bot mencari di snapshot secret MikroTik (hanya baca).',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'alur-otomatis',
            question: 'Apa yang terjadi setelah pesan #psb dikirim?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Bot membuat draft bernomor #ID dan berjalan otomatis sampai pelanggan aktif. Urutannya:',
            ],
            steps: [
                'Menunggu SN muncul sebagai ONU belum terkonfigurasi (uncfg) di OLT; pasang dan nyalakan ONU dulu.',
                'Memeriksa pemetaan VLAN port PON. Jika belum ada, draft berstatus "Pending VLAN" sampai NOC memasang VLAN dan membalas "done".',
                'Membuat secret PPPoE di MikroTik sekaligus data pelanggan (balasan pertama ke grup berisi kredensial PPPoE).',
                'Provisioning ONU di OLT dan memberi tag di GenieACS (balasan kedua beserta panduan setelan ONT). Draft lalu berstatus OLT OK dan tercatat di KPI.',
            ],
            notes: [
                'Status draft yang tampil di panel: Tunggu SN, Pending VLAN, Koreksi, Secret OK, OLT OK, Applied, Failed, Timeout.',
                'Bot hanya menjawab di grup yang didaftarkan lewat tombol "Pilih grup".',
                'Langkah 1, 2, dan 4 hanya untuk site dengan OLT penuh (RA dan Krian). Di site PPPoE + VLAN, bot berhenti setelah secret dibuat (status Secret OK) dan ONU disetel manual.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'psb-gagal',
            question: 'Draft PSB tersangkut atau gagal. Apa yang harus dilakukan?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Bot diam: pastikan grup sudah terdaftar dan pesan diawali "#psb". "Konfigurasi PSB kurang": admin/NOC harus melengkapi grup WA, OLT default, router, dan paket di pengaturan bot, lalu kirim ulang #psb. Terus "Tunggu SN": cek FO/ONU menyala dan SN tidak salah ketik, lalu "#psb sn FHTT…" atau "#psb lanjut #ID". "Pending VLAN": tunggu NOC memasang VLAN dan membalas "done". PPPoE gagal atau pool IP penuh: NOC memperluas pool lalu "#psb lanjut #ID". Secret OK tetapi OLT gagal: NOC memeriksa OLT lalu "#psb lanjut #ID". SN di chat berbeda dengan yang terdeteksi: "#psb ya #ID" jika yang di OLT benar. Secret sudah ada: bot lanjut ke OLT tanpa membuat ulang.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'site-psb-wa',
            question: 'Daerah mana saja yang sudah bisa PSB via WA, dan apa bedanya?',
            audience: ['teknisi', 'admin'],
            access: 'auth',
            answer: [
                'Site dengan OLT penuh (bot membuat secret PPPoE dan provisioning ONU di OLT): Randuagung C300 (RA, site bawaan; router RA-OLT, OLT ZTE C300, ONU FiberHome) dan Krian C300 (router Krian-OLT, OLT ZTE C300; sejak 30 September 2026 PSB dan migrasi Krian diarahkan ke router Krian-OLT, bukan router Krian lama).',
                'Site Huawei: Gadang (router Gadang, OLT Huawei MA5608T; chat "Gadang" diarahkan ke sini; paket 50M/100M/200M). Site PPPoE + VLAN saja (bot hanya membuat secret MikroTik dan VLAN, ONU disetel manual): Bumiayu, Martopuro, Mangliawan, Srigading Hioso, dan Gadang Hioso. Randuagung CCR (alias rdg/htb) hanya MikroTik.',
                'Sejak 30 September 2026, Daerah Martopuro dan Srigading (juga Purwodadi, SLB, Jatisari, Gajahrejo, Kertosari) dipetakan ke Randuagung C300 untuk migrasi berbasis format PSB WA. Router tujuan tetap mengikuti kolom "Router:".',
                'Pilih router dengan kolom "Router:" di pesan atau "#psb router <alias>". Jika Daerah tidak dikenal dan Router tidak diisi, bot tidak menebak site.',
            ],
            updated: '2026-10-10',
        },
        {
            id: 'panel-admin',
            question: 'Apa isi dashboard menu PSB WA?',
            images: [
                { src: '/images/faq/psb-wa-dashboard.webp', alt: 'Dashboard Skynet KPI dengan filter Site, Area, Dari, Sampai, kartu target harian site, dan alur draft PSB', caption: 'Dashboard "Skynet KPI": filter site/area, target harian, dan alur draft Masuk → Verifikasi → Provisioning → Selesai.' },
            ],
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Menu PSB WA membuka dashboard "Skynet KPI" (PSB dari WA, manual, dan Excel; hanya baca). Saring dengan Site, Area, Dari, dan Sampai. Kolom alur: Masuk, Verifikasi, Provisioning, Selesai. Persentase sukses berwarna hijau bila 90% ke atas, kuning 70% sampai di bawah 90%, dan merah di bawah 70%.',
                'Tombol "Aktivasi PSB WA" membuka panel draft di menu Aktivasi; panel itu hanya bisa dibuka Admin global, NOC, dan Superadmin.',
            ],
            links: [{ label: 'Buka PSB WA', route: 'psb-drafts.dashboard' }],
            updated: '2026-10-10',
        },
        {
            id: 'panel-aktivasi',
            question: 'Di mana memantau dan menindak draft PSB satu per satu?',
            audience: ['admin'],
            access: 'global_admin',
            answer: [
                'Di Aktivasi, tab PSB WA, panel "PSB WA · Auto FiberHome": daftar draft hari ini, "Health Bot PSB WA" (WA, webhook, antrean, balasan terakhir), dan tombol "KPI / CSV", "Refresh", "Pilih grup". Pengaturan tiap site (Grup WhatsApp, Site PSB, Router target, Package / profile, OLT target, ACS URL) ada di tombol "Pilih grup" → "Pengaturan bot #psb".',
                'Tombol per draft: "Retry" (mengulang proses dari awal untuk status Tunggu SN, Pending VLAN, Failed, Timeout, Secret OK), "Apply" (muncul bila draft sudah punya data pelanggan; menandai pelanggan Aktif dan menyalin foto, tidak menyentuh router/OLT; konfirmasi "Saya sudah memeriksa data draft ini."), "Apply Koreksi" dan "Batalkan Koreksi" untuk draft berstatus Koreksi ("Apply Koreksi" menulis perubahan ke MikroTik/OLT, konfirmasi "Saya sudah memeriksa perubahan koreksi dan preflight."), serta "Kirim ke Data Pelanggan" (menyalin foto ke data pelanggan).',
            ],
            notes: [
                'Jika Apply atau Apply Koreksi gagal, muncul "PSB operation failed. Check the draft status and server logs." Salah satu penyebabnya: penulisan koreksi ke perangkat masih dikunci di pengaturan; hubungi NOC.',
                'Retry pada draft yang menunggu koreksi ditolak: gunakan "Apply Koreksi" atau "#psb lanjut #ID" setelah preflight dicek.',
            ],
            links: [{ label: 'Buka Aktivasi', route: 'oltc300.index' }],
            updated: '2026-10-10',
        },
        {
            id: 'psb-import',
            question: 'Untuk apa halaman PSB (import cadangan)?',
            audience: ['admin'],
            answer: [
                'Memantau sambungan baru per bulan dari data scraper dan menjalankan "Import cadangan" bila pendaftaran tidak lewat bot: unggah CSV, klik "Refresh mapping" agar area/paket/router terpetakan, periksa daftarnya, lalu "Apply" per baris, atau "Apply siap (N)" untuk semua kandidat Siap Apply dengan centang "Saya sudah memeriksa daftar dan ingin membuat N pelanggan baru." Pelanggan dibuat berstatus Aktif dengan jatuh tempo tetap router/area bila ada, selain itu tanggal 20; kode atau PPPoE yang sudah ada dilewati. MikroTik dan OLT tidak disentuh.',
                'Di dashboard KPI, "Import Excel/Sheet" hanya memperbarui angka KPI; konfirmasinya menegaskan "Aktivasi MikroTik dan OLT tidak dijalankan oleh aksi ini."',
            ],
            links: [{ label: 'Buka PSB', route: 'psb-imports.index' }],
            updated: '2026-10-10',
        },
    ],
};
