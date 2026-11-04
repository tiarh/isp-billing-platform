import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthShell from '@/Layouts/AuthShell';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <AuthShell>
            <Head title="Email Verification" />

            <Card className="border-0 bg-transparent shadow-none">
                <CardHeader className="px-0 pt-0 text-center">
                    <CardTitle>Verify your email</CardTitle>
                    <CardDescription>
                        Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn't receive the email, we will gladly send you another.
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-0 pb-0">
                    {status === 'verification-link-sent' && (
                        <div className="mb-4 text-sm font-medium text-status-up text-center">
                            A new verification link has been sent to your email address.
                        </div>
                    )}
                    <form onSubmit={submit}>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Sending...' : 'Resend Verification Email'}
                        </Button>
                    </form>
                    <div className="mt-4 text-center">
                        <a
                            href={route('logout')}
                            onClick={(e) => {
                                e.preventDefault();
                                post(route('logout'));
                            }}
                            className="text-sm text-muted-foreground hover:text-primary underline-offset-4 hover:underline"
                        >
                            Log Out
                        </a>
                    </div>
                </CardContent>
            </Card>
        </AuthShell>
    );
}
