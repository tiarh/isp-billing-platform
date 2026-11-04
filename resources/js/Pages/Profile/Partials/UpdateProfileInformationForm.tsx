import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { User, CheckCircle2 } from 'lucide-react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const user = usePage().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
        });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('profile.update'));
    };

    return (
        <Card className="border-border bg-card/50">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-primary/10 rounded-lg text-primary">
                        <User aria-hidden="true" className="h-5 w-5" />
                    </div>
                    <div>
                        <CardTitle>Profile Information</CardTitle>
                        <CardDescription>Update your account's profile information and email address.</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Name</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                            autoFocus
                            autoComplete="name"
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Email</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            required
                            autoComplete="username"
                        />
                        {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                    </div>

                    {mustVerifyEmail && user.email_verified_at === null && (
                        <div className="text-sm text-muted-foreground">
                            Your email address is unverified.{' '}
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="underline text-foreground hover:text-foreground/80"
                            >
                                Click here to re-send the verification email.
                            </Link>

                            {status === 'verification-link-sent' && (
                                <p className="mt-2 font-medium text-status-up">
                                    A new verification link has been sent to your email address.
                                </p>
                            )}
                        </div>
                    )}

                    <div className="flex items-center gap-4">
                        <Button type="submit" disabled={processing}>{processing ? 'Saving...' : 'Save'}</Button>
                        {recentlySuccessful && (
                            <span className="flex items-center gap-1 text-sm text-status-up">
                                <CheckCircle2 className="h-3.5 w-3.5" /> Saved
                            </span>
                        )}
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
