import { EnterpriseFormLayout } from '@/Components/enterprise/EnterpriseFormLayout';
import { useForm } from '@inertiajs/react';
import { TextField } from '@/Components/ResourceFields';
import { ResourceFormShell } from '@/Components/ResourceFormShell';
import { requiredString, validateForm } from '@/lib/validation';
import { MapPin } from 'lucide-react';
import { z } from 'zod';

const areaSchema = z.object({
    name: requiredString('Area name'),
    code: requiredString('Area code'),
});

export default function Create() {
    const form = useForm({
        name: '',
        code: '',
        aliases: '',
    });
    const { data, setData, post, processing, errors } = form;

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(areaSchema, data, form)) return;
        post(route('areas.store'));
    };

    return (
        <EnterpriseFormLayout
            title="Create Area"
            pageTitle="Create Area"
            subtitle="Tambahkan area operasional baru"
            icon={MapPin}
            backHref={route('areas.index')}
            breadcrumbs={[
                { label: 'Areas', href: route('areas.index') },
                { label: 'Create Area' },
            ]}
        >
            <ResourceFormShell
                title="Area Details"
                description="Add a new operational area."
                onSubmit={submit}
                submitLabel="Create Area"
                processingLabel="Creating..."
                processing={processing}
                cancelHref={route('areas.index')}
            >
                <TextField
                    id="name"
                    label="Area Name"
                    value={data.name}
                    onChange={(value) => setData('name', value)}
                    placeholder="e.g. Singosari"
                    required
                    autoFocus
                    maxLength={255}
                    error={errors.name}
                />
                <TextField
                    id="code"
                    label="Area Code"
                    value={data.code}
                    onChange={(value) => setData('code', value)}
                    placeholder="e.g. SGS"
                    required
                    maxLength={255}
                    help="Unique identifier for this area."
                    error={errors.code}
                />
                <TextField
                    id="aliases"
                    label="Alias / ejaan lain"
                    value={data.aliases}
                    onChange={(value) => setData('aliases', value)}
                    placeholder="e.g. Kelampok, Kalmpok"
                    maxLength={1000}
                    help="Pisahkan dengan koma. Dipakai untuk mencocokkan daerah pelanggan baru ke area ini."
                    error={errors.aliases}
                />
            </ResourceFormShell>
        </EnterpriseFormLayout>
    );
}
