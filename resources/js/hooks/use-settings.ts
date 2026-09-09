import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api';
import { keys } from '@/lib/query';
import { useCurrentOrganization } from '@/stores/auth';
import type { Brand, GbpConnection, Member, ReportSummary } from '@/types/api';

export function useBrands() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.brands(organization?.id ?? null),
        enabled: organization !== null,
        queryFn: async (): Promise<Brand[]> => {
            const { data } = await api.get<{ brands: Brand[] }>(`/organizations/${organization!.id}/brands`);

            return data.brands;
        },
    });
}

export function useMembers() {
    const organization = useCurrentOrganization();

    return useQuery({
        queryKey: keys.members(organization?.id ?? null),
        enabled: organization !== null,
        queryFn: async (): Promise<Member[]> => {
            const { data } = await api.get<{ members: Member[] }>(`/organizations/${organization!.id}/members`);

            return data.members;
        },
    });
}

export interface NewLocation {
    name: string;
    address?: string | null;
    phone?: string | null;
    website_url?: string | null;
    gbp_location_id?: string | null;
    latitude?: number | null;
    longitude?: number | null;
}

export function useCreateLocation() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (location: NewLocation) => {
            const { data } = await api.post(`/organizations/${organization!.id}/locations`, location);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations(organization?.id ?? null) }),
    });
}

export function useUpdateLocation() {
    const organization = useCurrentOrganization();
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async ({ id, ...changes }: NewLocation & { id: number }) => {
            const { data } = await api.patch(`/organizations/${organization!.id}/locations/${id}`, changes);

            return data;
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations(organization?.id ?? null) }),
    });
}

/** The store front's Google connection, if it has one. */
export function useGbpConnection(locationId: number | null) {
    return useQuery({
        queryKey: keys.gbpConnection(locationId),
        enabled: locationId !== null,
        queryFn: async (): Promise<GbpConnection | null> => {
            const { data } = await api.get<{ connection: GbpConnection | null }>('/auth/google/connection', {
                params: { location_id: locationId },
            });

            return data.connection;
        },
        // An administrator's screen; a member without the role gets a 403 and
        // there is nothing to show them.
        retry: false,
    });
}

/**
 * Start the Google consent flow. The API hands back the URL rather than
 * redirecting, so the browser leaves only once the state is safely stored.
 */
export function useConnectGoogle() {
    return useMutation({
        mutationFn: async (locationId: number) => {
            const { data } = await api.get<{ redirect_url: string }>('/auth/google/redirect', {
                params: { location_id: locationId },
            });

            return data.redirect_url;
        },
        onSuccess: (url) => {
            window.location.href = url;
        },
    });
}

export function useReports(locationId: number | null) {
    return useQuery({
        queryKey: keys.reports(locationId),
        enabled: locationId !== null,
        retry: false,
        queryFn: async (): Promise<ReportSummary[]> => {
            const { data } = await api.get<{ reports: ReportSummary[] }>(`/locations/${locationId}/reports`);

            return data.reports;
        },
    });
}
