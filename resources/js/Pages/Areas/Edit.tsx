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

interface Props {
    area: {
        id: number;
        name: string;
        code: string;
    };
    aliases: string[];
}

export default function Edit({ area, aliases }: Props) {
    const form = useForm({
        name: area.name,
        code: area.code,
        aliases: aliases.join(', '),
    });
    const { data, setData, put, processing, errors } = form;

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!validateForm(areaSchema, data, form)) return;
        put(route('areas.update', area.id));
    };

    return (
        <EnterpriseFormLayout
            title="Edit Area"
            pageTitle={`Edit ${area.name}`}
            subtitle={`Perbarui informasi area ${area.name}`}
            icon={MapPin}
            backHref={route('areas.index')}
            breadcrumbs={[
                { label: 'Areas', href: route('areas.index') },
                { label: `Edit ${area.name}` },
            ]}
        >
            <ResourceFormShell
                title="Area Details"
                description="Update information for this area."
                onSubmit={submit}
                submitLabel="Save Changes"
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
