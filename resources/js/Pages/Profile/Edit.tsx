import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { PageProps } from '@/types';
import { User } from 'lucide-react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({
    mustVerifyEmail,
    status,
}: PageProps<{ mustVerifyEmail: boolean; status?: string }>) {
    return (
        <EnterpriseFormLayout
            title="Profile"
            pageTitle="Profile"
            subtitle="Kelola informasi akun dan keamanan"
            icon={User}
            breadcrumbs={[{ label: 'Profile' }]}
        >
            <div className="space-y-6">
                <UpdateProfileInformationForm
                    mustVerifyEmail={mustVerifyEmail}
                    status={status}
                />

                <UpdatePasswordForm />

                <DeleteUserForm />
            </div>
        </EnterpriseFormLayout>
    );
}
