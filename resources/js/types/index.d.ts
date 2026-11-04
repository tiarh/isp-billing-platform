export interface User {
    id: number;
    name: string;
    email: string;
    role: 'superadmin' | 'admin' | 'noc' | 'teknisi';
    scope: 'superadmin' | 'global_admin' | 'scoped_admin';
    area_ids: number[];
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string;
        warning?: string;
        error?: string;
    };
    settings: {
        payment_channels: Array<{
            bank: string;
            account_number: string;
            account_name: string;
        }>;
    };
};
