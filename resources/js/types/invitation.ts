import type { Role } from '@/types/api';

export interface Invitation {
    email: string;
    role: Role;
    role_label: string;
    organization: {
        id: number;
        name: string;
    };
    invited_by: string | null;
    expires_at: string;
    accepted: boolean;
    expired: boolean;
    requires_registration: boolean;
}
