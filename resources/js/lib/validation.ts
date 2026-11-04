import { z } from 'zod';

type InertiaErrorBag = Record<string, string>;

interface FormWithErrors {
    clearErrors: (...fields: any[]) => void;
    setError: (field: any, value?: string) => void;
}

export function validateForm<TSchema extends z.ZodTypeAny>(
    schema: TSchema,
    data: unknown,
    form: FormWithErrors,
): data is z.infer<TSchema> {
    const result = schema.safeParse(data);

    if (result.success) {
        form.clearErrors();
        return true;
    }

    const errors: InertiaErrorBag = {};

    for (const issue of result.error.issues) {
        const field = issue.path.join('.');

        if (field && !errors[field]) {
            errors[field] = issue.message;
        }
    }

    form.setError(errors);
    return false;
}

export const optionalString = z.preprocess(
    (value) => value === '' ? null : value,
    z.string().nullable(),
);

export const requiredString = (label: string, max = 255) => z.string()
    .trim()
    .min(1, `${label} is required.`)
    .max(max, `${label} may not be greater than ${max} characters.`);

export const requiredId = (label: string) => z.string()
    .trim()
    .min(1, `${label} is required.`)
    .refine((value) => Number.isInteger(Number(value)) && Number(value) > 0, `${label} is invalid.`);

export const nullableId = (label: string) => z.string()
    .refine((value) => value === '' || (Number.isInteger(Number(value)) && Number(value) > 0), `${label} is invalid.`);

export const requiredNumber = (label: string, min = 0) => z.preprocess(
    (value) => value === '' ? Number.NaN : Number(value),
    z.number({ error: `${label} is required.` }).min(min, `${label} must be at least ${min}.`),
);

export const optionalNumberInRange = (label: string, min: number, max: number) => z.preprocess(
    (value) => value === '' || value === null || value === undefined ? null : Number(value),
    z.number({ error: `${label} must be a number.` }).min(min).max(max).nullable(),
);

export const optionalIpAddress = (label: string) => z.string()
    .refine((value) => value === '' || /^(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)(\.(25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)){3}$/.test(value), `${label} must be a valid IPv4 address.`);

export const optionalImage = (label: string, maxBytes = 2 * 1024 * 1024) => z
    .any()
    .refine((file) => !file || file instanceof File, `${label} must be a file.`)
    .refine((file) => !file || file.type.startsWith('image/'), `${label} must be an image.`)
    .refine((file) => !file || file.size <= maxBytes, `${label} may not be greater than 2MB.`);
