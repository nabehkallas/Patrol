export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    /** Only present for a tenant (station) user -- absent for a platform super admin, whose
     * central-database row has no such column. */
    default_entry_date?: 'today' | 'yesterday';
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    isAdmin: boolean;
    isSuperAdmin: boolean;
};
