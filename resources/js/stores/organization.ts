import { create } from 'zustand';
import { persist } from 'zustand/middleware';

import { setOrganizationHeader } from '@/lib/api';

interface OrganizationState {
    currentId: number | null;
    setCurrent: (organizationId: number | null) => void;
}

/**
 * The organization the user is working in. It outlives a reload so the app
 * comes back where it was left, and every change is mirrored onto the axios
 * default headers.
 */
export const useOrganizationStore = create<OrganizationState>()(
    persist(
        (set) => ({
            currentId: null,
            setCurrent: (organizationId) => set({ currentId: organizationId }),
        }),
        {
            name: 'stoc-meo:organization',
            onRehydrateStorage: () => (state) => setOrganizationHeader(state?.currentId ?? null),
        },
    ),
);

useOrganizationStore.subscribe((state) => setOrganizationHeader(state.currentId));
