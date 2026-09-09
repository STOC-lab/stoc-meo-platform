import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import { useCurrentOrganization } from '@/stores/auth';
import { useLocationStore } from '@/stores/location';
import type { Location } from '@/types/api';

/**
 * Every store front in the active organization.
 */
export function useLocations() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.locations(organization?.id ?? null),
        enabled: organization !== null,
        queryFn: async (): Promise<Location[]> => {
            const { data } = await api.get<{ locations: Location[] }>(
                `/organizations/${organization!.id}/locations`,
            );

            return data.locations;
        },
    });
}

/**
 * The store front the screens are scoped to.
 *
 * A remembered choice that is no longer in the organization — because it was
 * deleted, or because the user switched organizations — falls back to the
 * first one rather than leaving every screen empty.
 */
export function useCurrentLocation() {
    const { data: locations, isPending } = useLocations();
    const currentId = useLocationStore((state) => state.currentId);
    const setCurrent = useLocationStore((state) => state.setCurrent);

    const current = locations?.find((location) => location.id === currentId) ?? locations?.[0] ?? null;

    useEffect(() => {
        if (current !== null && current.id !== currentId) {
            setCurrent(current.id);
        }
    }, [current, currentId, setCurrent]);

    return { location: current, locations: locations ?? [], isPending };
}
