import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Shield, CheckCircle2 } from 'lucide-react';

export default function UpdatePasswordForm() {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <Card className="border-border bg-card/50">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-status-degraded-bg rounded-lg text-status-degraded">
                        <Shield aria-hidden="true" className="h-5 w-5" />
                    </div>
                    <div>
                        <CardTitle>Update Password</CardTitle>
                        <CardDescription>Ensure your account is using a long, random password to stay secure.</CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <form onSubmit={updatePassword} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor="current_password">Current Password</Label>
                        <Input
                            id="current_password"
                            ref={currentPasswordInput}
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                            type="password"
                            autoComplete="current-password"
                        />
                        {errors.current_password && <p className="text-sm text-destructive">{errors.current_password}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">New Password</Label>
                        <Input
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            autoComplete="new-password"
                        />
                        {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            type="password"
                            autoComplete="new-password"
                        />
                        {errors.password_confirmation && <p className="text-sm text-destructive">{errors.password_confirmation}</p>}
                    </div>

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
