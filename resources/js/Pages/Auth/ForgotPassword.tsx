import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthShell from '@/Layouts/AuthShell';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.email'));
    };

    return (
        <AuthShell>
            <Head title="Forgot Password" />

            <Card className="border-0 bg-transparent shadow-none">
                <CardHeader className="px-0 pt-0 text-center">
                    <CardTitle>Forgot password?</CardTitle>
                    <CardDescription>
                        No problem. Just let us know your email address and we will email you a password reset link.
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-0 pb-0">
                    {status && (
                        <div className="mb-4 text-sm font-medium text-status-up text-center">
                            {status}
                        </div>
                    )}
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                placeholder="name@example.com"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoFocus
                                autoComplete="email"
                            />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Sending...' : 'Email Password Reset Link'}
                        </Button>
                    </form>
                    <div className="mt-4 text-center">
                        <Link href={route('login')} className="text-sm text-muted-foreground hover:text-primary underline-offset-4 hover:underline">
                            Back to login
                        </Link>
                    </div>
                </CardContent>
            </Card>
        </AuthShell>
    );
}
