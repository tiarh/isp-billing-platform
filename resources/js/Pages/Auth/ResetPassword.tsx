import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthShell from '@/Layouts/AuthShell';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'));
    };

    return (
        <AuthShell>
            <Head title="Reset Password" />

            <Card className="border-0 bg-transparent shadow-none">
                <CardHeader className="px-0 pt-0 text-center">
                    <CardTitle>Reset password</CardTitle>
                    <CardDescription>
                        Enter your new password below.
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-0 pb-0">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                autoFocus
                                autoComplete="email"
                            />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoComplete="new-password"
                            />
                            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">Confirm Password</Label>
                            <Input
                                id="password_confirmation"
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                autoComplete="new-password"
                            />
                            {errors.password_confirmation && <p className="text-sm text-destructive">{errors.password_confirmation}</p>}
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Resetting...' : 'Reset Password'}
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
