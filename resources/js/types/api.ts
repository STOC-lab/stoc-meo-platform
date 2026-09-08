export type Role = 'viewer' | 'editor' | 'admin' | 'owner';

export type OrganizationStatus = 'active' | 'trialing' | 'past_due' | 'canceled' | 'suspended';

export interface User {
    id: number;
    name: string;
    email: string;
}

export interface PlanSummary {
    code: string;
    name: string;
    product: string;
}

export interface Organization {
    id: number;
    name: string;
    slug: string;
    status: OrganizationStatus;
    role: Role;
    plan: PlanSummary | null;
}

export interface Profile {
    user: User;
    organizations: Organization[];
}
