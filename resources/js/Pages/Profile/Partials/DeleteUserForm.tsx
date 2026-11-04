import { useForm } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/Components/ui/dialog';
import { Trash2, AlertTriangle } from 'lucide-react';

export default function DeleteUserForm() {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser: FormEventHandler = (e) => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);
        clearErrors();
        reset();
    };

    return (
        <Card className="border-destructive/30 bg-card/50">
            <CardHeader>
                <div className="flex items-center gap-2">
                    <div className="p-2 bg-destructive/10 rounded-lg text-destructive">
                        <AlertTriangle aria-hidden="true" className="h-5 w-5" />
                    </div>
                    <div>
                        <CardTitle>Delete Account</CardTitle>
                        <CardDescription>
                            Permanently delete your account and all associated data. This action cannot be undone.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    Once your account is deleted, all of its resources and data will be permanently deleted.
                    Please download any data you wish to retain before proceeding.
                </p>

                <Dialog open={confirmingUserDeletion} onOpenChange={setConfirmingUserDeletion}>
                    <DialogTrigger asChild>
                        <Button variant="destructive" size="sm">
                            <Trash2 aria-hidden="true" className="h-4 w-4 mr-2" />
                            Delete Account
                        </Button>
                    </DialogTrigger>
                    <DialogContent className="border-destructive/50">
                        <DialogHeader>
                            <DialogTitle>Are you sure you want to delete your account?</DialogTitle>
                            <DialogDescription>
                                This action cannot be undone. Please enter your password to confirm.
                            </DialogDescription>
                        </DialogHeader>
                        <form onSubmit={deleteUser} className="space-y-4">
                            <div className="grid gap-2">
                                <Input
                                    id="password"
                                    type="password"
                                    ref={passwordInput}
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                    placeholder="Enter your password"
                                    autoFocus
                                />
                                {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={closeModal}>Cancel</Button>
                                <Button type="submit" variant="destructive" disabled={processing}>
                                    Delete Account
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </CardContent>
        </Card>
    );
}
