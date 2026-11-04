import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

export default function TotpStepUp() {
    const form = useForm({ code: '' });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('totp.step-up.store'));
    }

    return (
        <AuthenticatedLayout breadcrumbs={[{ label: 'Verifikasi control plane' }]}>
            <Head title="Verifikasi control plane" />
            <div className="mx-auto max-w-lg">
                <Card>
                    <CardHeader>
                        <CardTitle>Verifikasi ulang diperlukan</CardTitle>
                        <CardDescription>Mutasi control plane membutuhkan kode TOTP baru. Recovery code juga dapat digunakan sekali.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="totp-step-up-code">Kode verifikasi</Label>
                                <Input
                                    id="totp-step-up-code"
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
                                {form.processing ? 'Memverifikasi...' : 'Verifikasi dan lanjutkan'}
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
