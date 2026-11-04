import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { readMetaCsrfToken } from '@/lib/csrf';

type Enrollment = {
    issuer: string;
    account: string;
    secret: string;
    otpauth_uri: string;
    recovery_codes: string[];
};

type Props = {
    enabled: boolean;
    issuer: string;
    digits: number;
    period: number;
    recovery_codes: number;
};

export default function Totp({ enabled, issuer, digits, period, recovery_codes }: Props) {
    const [enrollment, setEnrollment] = useState<Enrollment | null>(null);
    const [beginError, setBeginError] = useState('');
    const confirmation = useForm({ code: '' });

    async function beginEnrollment() {
        setBeginError('');

        try {
            const response = await fetch(route('totp.enroll.begin'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': readMetaCsrfToken(),
                },
            });

            const payload = await response.json() as Enrollment & { message?: string };
            if (! response.ok) {
                setBeginError(payload.message ?? 'Enrollment TOTP tidak dapat dimulai.');
                return;
            }

            setEnrollment(payload);
        } catch {
            setBeginError('Enrollment TOTP tidak dapat dimulai. Periksa koneksi lalu coba lagi.');
        }
    }

    function confirmEnrollment(event: FormEvent) {
        event.preventDefault();
        confirmation.post(route('totp.enroll.confirm'), {
            preserveScroll: true,
            onSuccess: () => setEnrollment(null),
        });
    }

    return (
        <AuthenticatedLayout breadcrumbs={[{ label: 'Keamanan TOTP' }]}>
            <Head title="Keamanan TOTP" />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">Keamanan TOTP</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Verifikasi tambahan untuk akun superadmin dan mutasi control plane.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>{enabled ? 'TOTP aktif' : 'Aktifkan TOTP'}</CardTitle>
                        <CardDescription>
                            Kebijakan: {issuer}, SHA1, {digits} digit, periode {period} detik, {recovery_codes} recovery code sekali pakai.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {enabled ? (
                            <Alert>
                                <AlertDescription>
                                    TOTP sudah terdaftar pada akun ini. Reset oleh superadmin lain memerlukan re-auth password dan dicatat pada audit log.
                                </AlertDescription>
                            </Alert>
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Mulai enrollment setelah login password. Secret dan recovery code hanya ditampilkan pada sesi enrollment ini.
                                </p>
                                <Button type="button" onClick={beginEnrollment} disabled={enrollment !== null}>
                                    Mulai enrollment
                                </Button>
                                {beginError && <p className="text-sm text-destructive" role="alert">{beginError}</p>}
                            </>
                        )}
                    </CardContent>
                </Card>

                {enrollment && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Konfirmasi authenticator</CardTitle>
                            <CardDescription>
                                Tambahkan akun {enrollment.account} ke authenticator menggunakan URI di bawah atau masukkan secret secara manual.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="grid gap-2">
                                <Label htmlFor="totp-secret">Secret</Label>
                                <Input id="totp-secret" value={enrollment.secret} readOnly className="font-mono" aria-describedby="totp-secret-help" />
                                <p id="totp-secret-help" className="text-xs text-muted-foreground">Jangan bagikan secret ini. Halaman tidak menyimpannya sebagai props umum.</p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="totp-uri">Provisioning URI</Label>
                                <Input id="totp-uri" value={enrollment.otpauth_uri} readOnly className="font-mono text-xs" />
                            </div>
                            <div className="rounded-lg border border-status-degraded/40 bg-status-degraded-bg p-4">
                                <p className="text-sm font-medium">Recovery code</p>
                                <p className="mt-1 text-xs text-muted-foreground">Simpan di tempat aman. Setiap code hanya dapat digunakan sekali.</p>
                                <div className="mt-3 grid grid-cols-2 gap-2 font-mono text-sm sm:grid-cols-4">
                                    {enrollment.recovery_codes.map((code) => <code key={code}>{code}</code>)}
                                </div>
                            </div>
                            <form onSubmit={confirmEnrollment} className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                <div className="grid gap-2">
                                    <Label htmlFor="totp-code">Kode dari authenticator</Label>
                                    <Input
                                        id="totp-code"
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={digits}
                                        value={confirmation.data.code}
                                        onChange={(event) => confirmation.setData('code', event.target.value)}
                                        aria-invalid={Boolean(confirmation.errors.code)}
                                        required
                                    />
                                    {confirmation.errors.code && <p className="text-sm text-destructive">{confirmation.errors.code}</p>}
                                </div>
                                <Button type="submit" disabled={confirmation.processing}>
                                    {confirmation.processing ? 'Memverifikasi...' : 'Aktifkan TOTP'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
