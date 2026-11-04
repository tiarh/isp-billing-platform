import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import ClientAreaLayout from '@/Layouts/ClientAreaLayout';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Alert, AlertDescription } from '@/Components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';

interface Props {
    company: { name: string };
    sandbox?: boolean;
}

export default function Login({ company, sandbox = false }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        code: '',
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/pelanggan/login');
    };

    return (
        <ClientAreaLayout companyName={company.name} sandbox={sandbox}>
            <Head title="Masuk Area Pelanggan" />
            <Card className="mx-auto max-w-md">
                <CardHeader>
                    <CardTitle>Masuk Area Pelanggan</CardTitle>
                    <CardDescription>Gunakan kode pelanggan dan kata sandi Anda untuk melihat dan membayar tagihan.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        {errors.code && (
                            <Alert variant="destructive">
                                <AlertDescription>{errors.code}</AlertDescription>
                            </Alert>
                        )}
                        <div className="space-y-2">
                            <Label htmlFor="code">Kode pelanggan</Label>
                            <Input
                                id="code"
                                name="code"
                                autoComplete="username"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="password">Kata sandi</Label>
                            <Input
                                id="password"
                                name="password"
                                type="password"
                                autoComplete="current-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                required
                            />
                            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Memproses...' : 'Masuk'}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </ClientAreaLayout>
    );
}
