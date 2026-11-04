import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Alert, AlertDescription } from '@/Components/ui/alert';

export default function Login({
    status,
    canResetPassword,
}: {
    status?: string;
    canResetPassword: boolean;
}) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'));
    };

    return (
        <>
            <Head title="Login" />

            <div className="min-h-screen w-full lg:grid lg:grid-cols-2">
                {/* Left Panel - Visuals */}
                <div className="hidden bg-primary-soft lg:flex flex-col justify-between p-12 relative overflow-hidden">
                    <div className="relative z-10 flex items-center gap-2 text-lg font-bold text-foreground">
                        <img
                            src="/images/skynet-mark-96.png"
                            alt=""
                            aria-hidden="true"
                            width={32}
                            height={32}
                            className="h-8 w-8 shrink-0 rounded-lg"
                        />
                        <span>Skynet E-Billing</span>
                    </div>

                    <div className="relative z-10 max-w-md space-y-2">
                        <p className="text-lg font-medium text-foreground">Billing, aktivasi, dan monitoring jaringan Skynet.</p>
                        <p className="text-sm text-muted-foreground">
                            Masuk dengan akun staf. Semua aktivitas tercatat dan diaudit.
                        </p>
                    </div>

                    <p className="relative z-10 text-xs text-muted-foreground">Skynet Network</p>
                </div>

                {/* Right Panel - Form */}
                <div className="flex items-center justify-center p-8 bg-background">
                    <div className="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                        <div className="flex flex-col space-y-2 text-center">
                            <img
                                src="/images/skynet-mark-96.png"
                                alt=""
                                aria-hidden="true"
                                width={40}
                                height={40}
                                className="mx-auto h-10 w-10 rounded-lg lg:hidden"
                            />
                            <span className="text-base font-semibold text-foreground lg:hidden">Skynet E-Billing</span>
                            <h1 className="text-2xl font-semibold tracking-tight">
                                Welcome back
                            </h1>
                            <p className="text-sm text-muted-foreground">
                                Enter your credentials to access the admin portal
                            </p>
                        </div>

                        {status && (
                            <Alert>
                                <AlertDescription>{status}</AlertDescription>
                            </Alert>
                        )}

                        <div className="grid gap-6">
                            <form onSubmit={submit}>
                                <div className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="email">Email</Label>
                                        <Input
                                            id="email"
                                            placeholder="name@example.com"
                                            type="email"
                                            autoCapitalize="none"
                                            autoComplete="email"
                                            autoCorrect="off"
                                            disabled={processing}
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            required
                                            autoFocus
                                        />
                                        {errors.email && (
                                            <p className="text-sm text-destructive">{errors.email}</p>
                                        )}
                                    </div>
                                    <div className="grid gap-2">
                                        <div className="flex items-center justify-between">
                                            <Label htmlFor="password">Password</Label>
                                            {canResetPassword && (
                                                <Link
                                                    href={route('password.request')}
                                                    className="text-sm text-muted-foreground hover:text-primary underline-offset-4 hover:underline"
                                                >
                                                    Forgot password?
                                                </Link>
                                            )}
                                        </div>
                                        <Input
                                            id="password"
                                            type="password"
                                            autoComplete="current-password"
                                            placeholder="Enter password"
                                            disabled={processing}
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            required
                                        />
                                        {errors.password && (
                                            <p className="text-sm text-destructive">{errors.password}</p>
                                        )}
                                    </div>

                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Signing in...' : 'Sign In'}
                                    </Button>
                                </div>
                            </form>

                        </div>

                        <p className="px-8 text-center text-sm text-muted-foreground">
                            Sistem internal Skynet - akses hanya untuk staf yang berwenang.
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
