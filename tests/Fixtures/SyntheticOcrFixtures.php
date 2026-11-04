<?php

namespace Tests\Fixtures;

final class SyntheticOcrFixtures
{
    public static function ktpText(): string
    {
        return <<<'TXT'
PROVINSI UJI
KABUPATEN UJI
NIK: 9999994510000001
Nama: TEST PELANGGAN
Tempat/Tgl Lahir: UJI, 01-01-1990
Jenis Kelamin: LAKI-LAKI
Alamat: JALAN FIXTURE NOMOR 1
RT/RW: 001/002
Kel/Desa: DATA UJI
Kecamatan: KECAMATAN UJI
TXT;
    }

    /** Column-wise Tesseract output (the 2026-10-17 shape): label column first, ":" value column after. Synthetic person. */
    public static function ktpColumnWiseText(): string
    {
        return <<<'TXT'
PROVINSI JAWA TIMUR
KABUPATEN MALANG
NIK
Nama
Tempat/Tgl Lahir
Jenis Kelamin
Alamat
RT/RW
Kel/Desa
Kecamatan
Agama
Status Perkawinan
Pekerjaan
Kewarganegaraan
Berlaku Hingga
3507120110000005
: DRAFTE CO SANTOSO
: MALANG, 01-01-1990
: LAKI-LAKI Gol. Darah : O
: JL. CONTOH NO. 12
: 001/002
: KRIAN
: KRIAN
: ISLAM
: BELUM KAWIN
: WIRASWASTA
: WNI
: SEUMUR HIDUP
TXT;
    }

    /** Only the label column was read (no values at all). */
    public static function ktpLabelsOnlyText(): string
    {
        return <<<'TXT'
PROVINSI JAWA TIMUR
KABUPATEN MALANG
NIK
Nama
Tempat/Tgl Lahir
Jenis Kelamin
Alamat
RT/RW
Kel/Desa
Kecamatan
Agama
Status Perkawinan
Pekerjaan
Kewarganegaraan
Berlaku Hingga
TXT;
    }

    /** PaddleOCR style: one text box per line, label line then value line. */
    public static function ktpPaddleLinesText(): string
    {
        return <<<'TXT'
PROVINSI JAWA TIMUR
KABUPATEN MALANG
NIK
3507120110000005
Nama
DRAFTE CO SANTOSO
Tempat/Tgl Lahir
MALANG, 01-01-1990
Jenis Kelamin
LAKI-LAKI
Alamat
JL. CONTOH NO. 12
RT/RW
001/002
TXT;
    }

    public static function snText(): string
    {
        return 'MODEL ONT CANARY TEST SN: FHTTCANARY1234 GPON SERIAL';
    }
}
