import { create } from 'zustand';
import { persist } from 'zustand/middleware';

interface LocationState {
    currentId: number | null;
    setCurrent: (locationId: number | null) => void;
}

/**
 * The store front the user is looking at. Almost every screen below the
 * dashboard is scoped to one, so it outlives a reload the same way the
 * organization does.
 */
export const useLocationStore = create<LocationState>()(
    persist(
        (set) => ({
            currentId: null,
            setCurrent: (locationId) => set({ currentId: locationId }),
        }),
        { name: 'stoc-meo:location' },
    ),
);
