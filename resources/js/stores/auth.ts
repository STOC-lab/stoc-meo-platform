import { create } from 'zustand';

import { api, ensureCsrfCookie, handleUnauthenticated } from '@/lib/api';
import { useOrganizationStore } from '@/stores/organization';
import type { Organization, Profile, User } from '@/types/api';

type AuthStatus = 'idle' | 'loading' | 'authenticated' | 'guest';

export interface RegistrationPayload {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    organization_name: string;
}

interface AuthState {
    user: User | null;
    organizations: Organization[];
    status: AuthStatus;
    register: (payload: RegistrationPayload) => Promise<void>;
    login: (email: string, password: string, remember: boolean) => Promise<void>;
    logout: () => Promise<void>;
    loadProfile: () => Promise<void>;
    clear: () => void;
}

/**
 * Picks the organization to land on: the remembered one when the user still
 * belongs to it, otherwise their only organization, otherwise nothing.
 */
function resolveCurrentOrganization(organizations: Organization[]): number | null {
    const { currentId } = useOrganizationStore.getState();

    if (currentId !== null && organizations.some((organization) => organization.id === currentId)) {
        return currentId;
    }

    return organizations.length === 1 ? organizations[0].id : null;
}

function applyProfile(profile: Profile) {
    useOrganizationStore.getState().setCurrent(resolveCurrentOrganization(profile.organizations));

    return {
        user: profile.user,
        organizations: profile.organizations,
        status: 'authenticated' as const,
    };
}

export const useAuthStore = create<AuthState>()((set) => ({
    user: null,
    organizations: [],
    status: 'idle',

    register: async (payload) => {
        await ensureCsrfCookie();

        const { data } = await api.post<Profile>('auth/register', payload);

        set(applyProfile(data));
    },

    login: async (email, password, remember) => {
        await ensureCsrfCookie();

        const { data } = await api.post<Profile>('auth/login', { email, password, remember });

        set(applyProfile(data));
    },

    logout: async () => {
        try {
            await api.post('auth/logout');
        } finally {
            useOrganizationStore.getState().setCurrent(null);
            set({ user: null, organizations: [], status: 'guest' });
        }
    },

    loadProfile: async () => {
        set({ status: 'loading' });

        try {
            const { data } = await api.get<Profile>('auth/me');

            set(applyProfile(data));
        } catch {
            set({ user: null, organizations: [], status: 'guest' });
        }
    },

    clear: () => {
        useOrganizationStore.getState().setCurrent(null);
        set({ user: null, organizations: [], status: 'guest' });
    },
}));

// A rejected request means the session is gone; drop back to the login screen.
handleUnauthenticated(() => {
    if (useAuthStore.getState().status === 'authenticated') {
        useAuthStore.getState().clear();
    }
});

/**
 * The organization the user is currently working in, if it is still one of
 * theirs.
 */
export function useCurrentOrganization(): Organization | null {
    const organizations = useAuthStore((state) => state.organizations);
    const currentId = useOrganizationStore((state) => state.currentId);

    return organizations.find((organization) => organization.id === currentId) ?? null;
}
