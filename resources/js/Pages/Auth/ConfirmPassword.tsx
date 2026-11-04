import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AuthShell from '@/Layouts/AuthShell';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.confirm'));
    };

    return (
        <AuthShell>
            <Head title="Confirm Password" />

            <Card className="border-0 bg-transparent shadow-none">
                <CardHeader className="px-0 pt-0 text-center">
                    <CardTitle>Confirm your password</CardTitle>
                    <CardDescription>
                        This is a secure area of the application. Please confirm your password before continuing.
                    </CardDescription>
                </CardHeader>
                <CardContent className="px-0 pb-0">
                    <form onSubmit={submit} className="space-y-4">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <Input
                                id="password"
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                autoFocus
                            />
                            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                        </div>
                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing ? 'Confirming...' : 'Confirm'}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </AuthShell>
    );
}
