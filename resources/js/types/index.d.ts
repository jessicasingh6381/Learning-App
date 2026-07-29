export interface User {
    id: number;
    name: string;
    email: string | null;
    username?: string | null;
    email_verified_at?: string;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        permissions: string[];
    };
    tenant: Record<string, unknown> | null;
    tenants: Record<string, unknown>[];
    flash: { success?: string };
};
