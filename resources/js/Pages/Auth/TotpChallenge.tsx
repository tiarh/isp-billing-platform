import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import AuthShell from '@/Layouts/AuthShell';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function TotpChallenge() {
    const form = useForm({ code: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('totp.challenge.store'));
    }

    return (
        <AuthShell>
            <Head title="Verifikasi TOTP" />
            <Card className="border-0 bg-transparent shadow-none">
                <CardHeader className="px-0 pt-0">
                    <CardTitle>Verifikasi login</CardTitle>
                    <CardDescription>Masukkan kode authenticator atau recovery code untuk menyelesaikan login superadmin.</CardDescription>
                </CardHeader>
                <CardContent className="px-0 pb-0">
                    <form onSubmit={submit} className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="totp-challenge-code">Kode verifikasi</Label>
                            <Input
                                id="totp-challenge-code"
                                inputMode="numeric"
                                autoComplete="one-time-code"
                                autoFocus
                                value={form.data.code}
                                onChange={(event) => form.setData('code', event.target.value)}
                                aria-invalid={Boolean(form.errors.code)}
                                required
                            />
                            {form.errors.code && <Alert variant="destructive"><AlertDescription>{form.errors.code}</AlertDescription></Alert>}
                        </div>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Memverifikasi...' : 'Lanjutkan'}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </AuthShell>
    );
}
